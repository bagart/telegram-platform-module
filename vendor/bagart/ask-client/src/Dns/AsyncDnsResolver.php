<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

/**
 * Internal async DNS resolution engine over UDP/TCP, wrapped by
 * {@see Adapters\AsyncDnsAdapter} (the registry-facing adapter). Kept as a
 * standalone class so the wire-protocol implementation remains independent of
 * the pluggable adapter contract.
 */
final class AsyncDnsResolver
{
    private const int QUERY_TIMEOUT_NS = 500_000_000;
    private const int MAX_RETRIES = 1;

    private const array WELL_KNOWN_DNS = [
        '8.8.8.8',
        '1.1.1.1',
    ];

    /** @var array<int, string> */
    private readonly array $dnsServers;

    private readonly int $dnsServerCount;

    private readonly DnsCache $cache;

    private readonly DnsQueryBuilder $queryBuilder;

    private readonly DnsResponseParser $parser;

    private readonly DnsSocketTransport $transport;

    private readonly bool $useTls;

    /** @var array<string, DnsPendingQuery> */
    private array $pending = [];

    /** @var array<string, ?string> */
    private array $fresh = [];

    /** Earliest deadline among pending queries (nanoseconds). PHP_INT_MAX when empty. */
    private int $nextDeadline = PHP_INT_MAX;

    public function __construct(
        float $ttl = 60.0,
        float $failureTtl = 10.0,
        ?array $dnsServers = null,
        bool $useTls = false,
        ?DnsCache $cache = null,
    ) {
        $this->dnsServers = $dnsServers ?? self::loadSystemDnsServers();
        $this->dnsServerCount = count($this->dnsServers);
        $this->useTls = $useTls;
        $this->cache = $cache ?? new DnsCache(maxEntries: 10000, ttl: $ttl, negativeTtl: $failureTtl);
        $this->queryBuilder = new DnsQueryBuilder();
        $this->parser = new DnsResponseParser();
        $this->transport = new DnsSocketTransport($useTls);
    }

    /** @return array<int, string> */
    private static function loadSystemDnsServers(): array
    {
        $servers = [];

        $resolv = @file_get_contents('/etc/resolv.conf');
        if ($resolv !== false) {
            foreach (explode("\n", $resolv) as $line) {
                if (preg_match('/^nameserver\s+(\S+)/i', $line, $m)) {
                    $ip = trim($m[1]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $servers[] = $ip;
                    }
                }
            }
        }

        return $servers !== [] ? $servers : self::WELL_KNOWN_DNS;
    }

    /** @return array<int, resource> */
    public function getReadSockets(): array
    {
        return $this->transport->getReadSockets();
    }

    public function isDnsSocket(int $rid): bool
    {
        return $this->transport->isDnsSocket($rid);
    }

    public function resolve(string $host): ?string
    {
        $cache = $this->cache;
        $fresh = &$this->fresh;

        $cached = $cache->get($host);
        if ($cached !== null) {
            return $cached;
        }

        if (isset($fresh[$host])) {
            $ip = $fresh[$host];
            unset($fresh[$host]);

            return $ip;
        }

        if (isset($this->pending[$host])) {
            return null;
        }

        $this->dispatchQuery($host);

        return null;
    }

    public function tick(): void
    {
        $now = hrtime(true);

        if ($now < $this->nextDeadline) {
            return;
        }

        $pending = &$this->pending;
        $cache = $this->cache;
        $fresh = &$this->fresh;
        $nextDeadline = PHP_INT_MAX;

        foreach ($pending as $host => $p) {
            if ($now <= $p->deadline) {
                if ($p->deadline < $nextDeadline) {
                    $nextDeadline = $p->deadline;
                }

                continue;
            }

            if ($p->retries < self::MAX_RETRIES) {
                $this->retry($p, $now);
                if ($p->deadline < $nextDeadline) {
                    $nextDeadline = $p->deadline;
                }
            } else {
                unset($pending[$host]);
                $cache->putNegative($host);
            }
        }

        $this->nextDeadline = $nextDeadline;
    }

    public function processReadable(mixed $socket): bool
    {
        $transport = $this->transport;
        $pending = &$this->pending;
        $cache = $this->cache;
        $fresh = &$this->fresh;
        $parser = $this->parser;

        $host = $transport->getHost($socket);

        if ($host === null || !isset($pending[$host])) {
            return false;
        }

        $p = $pending[$host];

        $data = $transport->read($socket);

        if ($data === null) {
            $idx = $transport->getSocketIndex($socket);
            $transport->close($socket);

            if ($idx !== null) {
                unset($p->sockets[$idx]);
            }

            if ($p->sockets === []) {
                $this->retryOrFallback($host);
            }

            return true;
        }

        $result = $parser->parse($p->queryId, $data);

        if ($result !== null) {
            $cache->put($host, $result->ip, $result->ttl);
            $fresh[$host] = $result->ip;
        }

        $this->closePending($host);

        return true;
    }

    public function flushFresh(): array
    {
        $result = $this->fresh;
        $this->fresh = [];

        return $result;
    }

    public function hasFresh(string $host): bool
    {
        return isset($this->fresh[$host]);
    }

    public function clearCache(): void
    {
        $this->cache->clear();
    }

    public function hasPendingQueries(): bool
    {
        return $this->pending !== [];
    }

    public function resolveFresh(string $host): ?string
    {
        if (isset($this->fresh[$host])) {
            $ip = $this->fresh[$host];
            unset($this->fresh[$host]);

            return $ip;
        }

        return null;
    }

    /**
     * Blocks until the host is resolved or the timeout expires.
     * Uses hrtime, zero-copy socket arrays, and direct internal access.
     */
    public function waitUntilResolved(string $host, float $timeout): ?string
    {
        $cache = $this->cache;

        $cached = $cache->get($host);
        if ($cached !== null) {
            return $cached;
        }

        $fresh = $this->resolveFresh($host);
        if ($fresh !== null) {
            return $fresh;
        }

        if (!isset($this->pending[$host])) {
            $this->dispatchQuery($host);
        }

        $deadline = hrtime(true) + (int)($timeout * 1_000_000_000);
        $write = null;
        $except = null;

        for (; ;) {
            if (hrtime(true) >= $deadline) {
                break;
            }

            $this->tick();

            if (!isset($this->pending[$host])) {
                break;
            }

            $sockets = $this->transport->getReadSockets();

            if ($sockets === []) {
                continue;
            }

            if (@stream_select($sockets, $write, $except, 0, 100_000) > 0) {
                foreach ($sockets as $socket) {
                    $this->processReadable($socket);
                }

                if (isset($this->fresh[$host])) {
                    $ip = $this->fresh[$host];
                    unset($this->fresh[$host]);

                    return $ip;
                }
            }
        }

        return $this->resolveFresh($host);
    }

    /** Blocking DNS resolution for warmup/startup only. */
    public function resolveBlocking(string $host): ?string
    {
        $cache = $this->cache;
        $parser = $this->parser;

        $cached = $cache->get($host);
        if ($cached !== null) {
            return $cached;
        }

        if (isset($this->fresh[$host])) {
            return $this->fresh[$host];
        }

        $query = $this->queryBuilder->build($host);
        $framed = pack('n', strlen($query->packet)).$query->packet;

        foreach ($this->dnsServers as $server) {
            $socket = @stream_socket_client(
                'tcp://'.$server.':53',
                $errno,
                $errstr,
                2.0,
            );

            if (!$socket) {
                continue;
            }

            if (@fwrite($socket, $framed) === false) {
                @fclose($socket);
                continue;
            }

            $header = '';
            $remaining = 2;
            while ($remaining > 0) {
                $chunk = @fread($socket, $remaining);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $header .= $chunk;
                $remaining -= strlen($chunk);
            }

            if (strlen($header) < 2) {
                @fclose($socket);
                continue;
            }

            $msgLen = unpack('n', $header)[1];

            if ($msgLen < 12 || $msgLen > 4096) {
                @fclose($socket);
                continue;
            }

            $body = '';
            $remaining = $msgLen;
            while ($remaining > 0) {
                $chunk = @fread($socket, $remaining);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
            @fclose($socket);

            if (strlen($body) < $msgLen) {
                continue;
            }

            $result = $parser->parse($query->id, $body);

            if ($result !== null && filter_var($result->ip, FILTER_VALIDATE_IP)) {
                $ttl = min($result->ttl, 3600);
                $cache->put($host, $result->ip, $ttl);

                return $result->ip;
            }
        }

        $ip = @gethostbyname($host);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $cache->put($host, $ip);

            return $ip;
        }

        return null;
    }

    private function dispatchQuery(string $host): void
    {
        $query = $this->queryBuilder->build($host);
        $wirePacket = $this->useTls ? pack('n', strlen($query->packet)).$query->packet : $query->packet;

        $deadline = hrtime(true) + self::QUERY_TIMEOUT_NS;
        $sockets = [];

        foreach ($this->dnsServers as $idx => $server) {
            $socket = $this->transport->create($host, $idx, $server);

            if ($socket === null) {
                continue;
            }

            @fwrite($socket, $wirePacket);
            $sockets[$idx] = $socket;
        }

        if ($sockets === []) {
            $this->cache->putNegative($host);

            return;
        }

        $this->pending[$host] = new DnsPendingQuery(
            host: $host,
            queryId: $query->id,
            rawPacket: $query->packet,
            deadline: $deadline,
            retries: 0,
            sockets: $sockets,
        );

        if ($deadline < $this->nextDeadline) {
            $this->nextDeadline = $deadline;
        }
    }

    private function retry(DnsPendingQuery $pending, int $now): void
    {
        $this->transport->closeAll($pending->sockets);
        $pending->sockets = [];

        $wirePacket = $this->useTls
            ? pack('n', strlen($pending->rawPacket)).$pending->rawPacket
            : $pending->rawPacket;

        foreach ($this->dnsServers as $idx => $server) {
            $socket = $this->transport->create($pending->host, $idx, $server);

            if ($socket === null) {
                continue;
            }

            @fwrite($socket, $wirePacket);
            $pending->sockets[$idx] = $socket;
        }

        $pending->retries++;
        $pending->deadline = $now + self::QUERY_TIMEOUT_NS;
    }

    private function retryOrFallback(string $host): void
    {
        $pending = $this->pending[$host] ?? null;

        if ($pending === null) {
            return;
        }

        if ($pending->retries < self::MAX_RETRIES) {
            $this->retry($pending, hrtime(true));
        } else {
            $this->fallback($host);
        }
    }

    private function fallback(string $host): void
    {
        $this->closePending($host);
        $this->cache->putNegative($host);
    }

    private function closePending(string $host): void
    {
        $pending = $this->pending[$host] ?? null;

        if ($pending !== null) {
            foreach ($pending->sockets as $socket) {
                $this->transport->close($socket);
            }
        }

        unset($this->pending[$host]);
    }

}
