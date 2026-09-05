<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\SocketClient;

use BAGArt\ASKClient\Contracts\Client\AskNetworkClientContract;
use BAGArt\ASKClient\Contracts\Client\WarmableClientContract;
use BAGArt\ASKClient\Contracts\Dns\AskDnsAdapterContract;
use BAGArt\ASKClient\Dns\AskDnsConfig;
use BAGArt\ASKClient\Dns\AskDnsRegistry;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\SocketClient\Connection\ConnectionLease;
use BAGArt\ASKClient\SocketClient\Connection\ConnectionPool;
use BAGArt\ASKClient\SocketClient\Connection\ConnectionState;
use BAGArt\ASKClient\SocketClient\Connection\PooledConnection;
use BAGArt\ASKClient\SocketClient\Http1\Http1Processor;
use BAGArt\ASKClient\SocketClient\Http2\Http2Connection;
use BAGArt\ASKClient\SocketClient\Metrics\MetricsCollector;
use BAGArt\ASKClient\SocketClient\Metrics\RequestTiming;
use BAGArt\ASKClient\SocketClient\Reactor\SocketReactor;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Exceptions\ASKException;
use BAGArt\AsyncKernel\Exceptions\ASKInterruptException;
use BAGArt\AsyncKernel\Promise\ASKPromise;
use Psr\Http\Message\ResponseInterface;
use SplQueue;
use Throwable;

final class AskHttpSocketClient implements AskNetworkClientContract, WarmableClientContract, ASKTickableContract
{
    private const int DEFAULT_HTTPS_PORT = 443;
    private const int DEFAULT_HTTP_PORT = 80;
    private const string PROTOCOL_HTTP_1_1 = 'http/1.1';
    private const string PROTOCOL_HTTP_2 = 'h2';

    private const int IDLE_EVICT_INTERVAL = 10;

    /** @var SplQueue<array{request: ASKHttpRequest, promise: ASKPromise, host: string, port: int, key: string, path: string, secure: bool}> */
    private SplQueue $queue;

    /** @var array<string, true> Hosts already dispatched to the DNS resolver — skip redundant resolve() calls. */
    private array $dnsRequested = [];

    /** @var array<int, PooledConnection> */
    private array $activeConnections = [];

    /** @var array<int, ASKPromise> */
    private array $connectionPromises = [];

    /** @var array<int, ConnectionLease> */
    private array $leases = [];

    private readonly ConnectionPool $connectionPool;
    private readonly AskDnsAdapterContract $dnsResolver;
    private readonly SocketReactor $reactor;
    private readonly MetricsCollector $metrics;

    /** @var array<string, int> Per-host:port count of connections in connecting state. */
    private array $connectingCount = [];

    /** @var array<string, int> Per-host:port count of active (in-flight) connections. */
    private array $activeCounts = [];

    /** @var array<int, int> Resource ID → connection index for O(1) lookup without building map each tick. */
    private array $socketIndex = [];

    private int $tickCounter = 0;

    public function __construct(
        private readonly HttpsSocketClientConfig $config = new HttpsSocketClientConfig(),
        ?MetricsCollector $metrics = null,
        ?AskDnsAdapterContract $dnsResolver = null,
    ) {
        $this->queue = new SplQueue();
        $this->connectionPool = $this->config->keepAlive
            ? new ConnectionPool(
                maxIdlePerHost: $this->config->maxIdlePerHost,
                maxIdleTotal: $this->config->maxIdleTotal,
                idleTimeout: $this->config->idleTimeout,
                maxLifetime: $this->config->maxLifetime,
            )
            : new ConnectionPool(0, 0, 0.0);

        $this->dnsResolver = $dnsResolver
            ?? new AskDnsRegistry()->make(
                null,
                new AskDnsConfig()
                    ->withTtl($this->config->dnsCacheTtl),
            );

        $this->reactor = new SocketReactor(pollIntervalUs: $this->config->reactorPollIntervalUs);
        $this->metrics = $metrics ?? new MetricsCollector();
    }

    public function metrics(): MetricsCollector
    {
        return $this->metrics;
    }

    public function request(ASKHttpRequest $request): ASKPromiseContract
    {
        $parsed = parse_url($request->url);
        $host = $parsed['host'] ?? null;

        if (!$host) {
            throw new ASKException("Cannot extract host from URL: {$request->url}");
        }

        $scheme = strtolower((string)($parsed['scheme'] ?? 'https'));
        $secure = $scheme !== 'http';

        $port = (int)($parsed['port'] ?? ($secure ? self::DEFAULT_HTTPS_PORT : self::DEFAULT_HTTP_PORT));
        $path = ($parsed['path'] ?? '/').(isset($parsed['query']) ? "?{$parsed['query']}" : '');

        $promise = new ASKPromise(...$this->tickable());

        $this->queue->enqueue([
            'request' => $request,
            'promise' => $promise,
            'host' => $host,
            'port' => $port,
            'key' => "{$host}:{$port}",
            'path' => $path,
            'secure' => $secure,
        ]);

        return $promise;
    }

    public function tick(int $systemPressure): void
    {
        $this->dnsResolver->tick();
        $this->drainFreshDns();
        $this->preResolveDns();
        $this->flushQueue();
        $this->processConnections();

        if ($this->config->keepAlive) {
            $this->tickCounter++;
            if ($this->tickCounter >= self::IDLE_EVICT_INTERVAL) {
                $this->tickCounter = 0;
                $this->connectionPool->evictIdle();
            }
        }
    }

    private function preResolveDns(): void
    {
        foreach ($this->queue as $pending) {
            $host = $pending['host'];
            if (isset($this->dnsRequested[$host])) {
                continue;
            }
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                continue;
            }
            $this->dnsResolver->resolve($host);
            $this->dnsRequested[$host] = true;
        }
    }

    /**
     * Remove dnsRequested entries for hosts that have resolved.
     * Peek at the fresh cache without consuming it — resolve() will pick them up.
     */
    private function drainFreshDns(): void
    {
        foreach ($this->dnsRequested as $host => $_) {
            if ($this->dnsResolver->hasFresh($host)) {
                unset($this->dnsRequested[$host]);
            }
        }
    }

    public function pressure(): int
    {
        $total = $this->queue->count() + count($this->activeConnections);

        if ($total === 0) {
            return 0;
        }

        return (int)round(($total / 64) * 100);
    }

    public function warmUp(string $host, int $count, int $port = 443): int
    {
        if (!$this->config->keepAlive || $count <= 0) {
            return 0;
        }

        $key = "{$host}:{$port}";
        $already = $this->connectionPool->idleCountForHost($key);

        $target = min($count, $this->config->maxIdlePerHost) - $already;
        if ($target <= 0) {
            return 0;
        }

        $connectHost = $this->resolveSync($host);
        if ($connectHost === null) {
            return 0;
        }

        $warmed = 0;
        for ($i = 0; $i < $target; $i++) {
            try {
                $conn = $this->openConnection(
                    $connectHost,
                    $host,
                    $port,
                    $key,
                    null
                );
            } catch (ASKInterruptException $e) {
                throw $e;
            } catch (Throwable) {
                continue;
            }

            if ($conn && $this->completeHandshakeBlocking($conn)) {
                $this->connectionPool->release($conn);
                $warmed++;
            }
        }

        return $warmed;
    }

    private function flushQueue(): int
    {
        if ($this->queue->isEmpty()) {
            return 0;
        }

        $dequeued = 0;
        $count = $this->queue->count();

        for ($i = 0; $i < $count; $i++) {
            $pending = $this->queue->dequeue();

            try {
                $connection = $this->acquireOrCreate($pending);
            } catch (ASKInterruptException $e) {
                throw $e;
            } catch (Throwable $e) {
                $pending['promise']->reject($e);
                $dequeued++;
                continue;
            }

            if ($connection === null) {
                $this->queue->enqueue($pending);
                continue;
            }

            $socketId = $this->socketIdOf($connection);

            $lease = $this->createLease($connection, $pending['promise']);

            $this->activeConnections[$socketId] = $connection;
            $this->leases[$socketId] = $lease;
            $this->connectionPromises[$socketId] = $pending['promise'];
            $this->socketIndex[$socketId] = $socketId;
            $this->reactor->register($connection);

            $key = $pending['key'];
            if (isset($this->connectingCount[$key])) {
                $this->connectingCount[$key]--;
                if ($this->connectingCount[$key] <= 0) {
                    unset($this->connectingCount[$key]);
                }
            }

            $this->activeCounts[$key] = ($this->activeCounts[$key] ?? 0) + 1;

            $dequeued++;
        }

        return $dequeued;
    }

    private function acquireOrCreate(array $pending): ?PooledConnection
    {
        $key = $pending['key'];

        if ($this->config->keepAlive) {
            $reused = $this->connectionPool->tryAcquire($key);

            if ($reused !== null) {
                if ($this->isSocketAlive($reused)) {
                    $reused->state = ConnectionState::READY;

                    $this->prepareHttp1Payload(
                        $reused,
                        $pending['request'],
                        $pending['host'],
                        $pending['path'] ?? null,
                    );

                    $reused->useCount++;
                    $this->metrics->incrementPoolHit();
                    $this->metrics->incrementTotalRequests(reused: true);

                    return $reused;
                }

                $this->metrics->incrementPoolMiss();
                $this->closeAndClear($reused);
            } else {
                $this->metrics->incrementPoolMiss();
            }
        }

        $totalForHost =
            ($this->connectingCount[$key] ?? 0)
            + $this->countActiveForHost($key)
            + $this->connectionPool->idleCountForHost($key);

        if ($totalForHost >= $this->config->maxConnectionsPerHost) {
            $this->metrics->incrementPoolWaitCount();

            return null;
        }

        if ($this->countOpenConnections() >= $this->config->maxConnectionsTotal) {
            $this->metrics->incrementPoolWaitCount();

            return null;
        }

        $this->connectingCount[$key] = ($this->connectingCount[$key] ?? 0) + 1;

        [$ip, $timing] = $this->resolveHostTimed($pending['host']);

        if ($ip === null) {
            $this->connectingCount[$key]--;
            return null;
        }

        $conn = $this->openConnection(
            $ip,
            $pending['host'],
            $pending['port'],
            $key,
            $pending,
            $pending['secure'] ?? true,
            $timing,
        );

        if ($conn === null) {
            $this->connectingCount[$key]--;
        }

        $this->metrics->incrementConnectionsCreated();
        $this->metrics->incrementTotalRequests(reused: false);

        return $conn;
    }

    /**
     * Resolve the host and capture DNS phase timing into a fresh RequestTiming.
     *
     * Returns [ip, timing]: timing.dnsStart/End bracket the resolution even when it
     * spans multiple ticks (async resolver), and timing is attached to the connection
     * created afterwards so later phases share one origin.
     *
     * @return array{0: ?string, 1: RequestTiming}
     */
    private function resolveHostTimed(string $host): array
    {
        $timing = new RequestTiming();
        $timing->dnsStart = microtime(true);

        $ip = $this->resolveHost($host);

        $timing->dnsEnd = microtime(true);
        $this->metrics->recordDnsResolve($timing->dnsEnd - $timing->dnsStart);

        return [$ip, $timing];
    }

    private function countActiveForHost(string $key): int
    {
        return $this->activeCounts[$key] ?? 0;
    }

    /**
     * Total open sockets across all hosts (connecting + active + idle).
     * The global {@see HttpsSocketClientConfig::$maxConnectionsTotal} cap is enforced
     * against this to stay clear of FD_SETSIZE on stream_select().
     */
    private function countOpenConnections(): int
    {
        $connecting = 0;
        foreach ($this->connectingCount as $count) {
            $connecting += $count;
        }

        $active = 0;
        foreach ($this->activeCounts as $count) {
            $active += $count;
        }

        return $connecting + $active + $this->connectionPool->idleCount();
    }

    private function decrementActiveCount(string $key): void
    {
        if (isset($this->activeCounts[$key])) {
            $this->activeCounts[$key]--;
            if ($this->activeCounts[$key] <= 0) {
                unset($this->activeCounts[$key]);
            }
        }
    }


    private function processConnections(): bool
    {
        $dnsSockets = $this->dnsResolver->getReadSockets();

        if ($this->activeConnections === []) {
            if ($dnsSockets === []) {
                return false;
            }

            $writeIgnored = [];
            $exceptIgnored = [];

            $changed = @stream_select(
                $dnsSockets,
                $writeIgnored,
                $exceptIgnored,
                0,
                50_000,
            );

            if ($changed === false || $changed <= 0) {
                return false;
            }

            foreach ($dnsSockets as $socket) {
                $this->dnsResolver->processReadable($socket);
            }

            return true;
        }

        // Batch: process multiple ready-socket generations per tick so that data made
        // available by an earlier write/read is observed in the same tick rather than
        // waiting for the next kernel tick. Capped to bound CPU — see $reactorBatchIterations.
        $totalProgress = false;
        for ($batch = 0; $batch < $this->config->reactorBatchIterations; $batch++) {
            $events = $this->reactor->tick($dnsSockets);

            $changed = count($events->readable) + count($events->writable) + count($events->exceptional);
            $this->metrics->recordStreamSelect($changed);

            if ($changed === 0) {
                break;
            }

            foreach ($events->writable as $socket) {
                if (!is_resource($socket)) {
                    continue;
                }
                $rid = get_resource_id($socket);

                if (isset($this->socketIndex[$rid])) {
                    $this->writeConnection($this->socketIndex[$rid]);
                }
            }

            // Single walk of readable: DNS sockets go to the resolver, connection sockets to readConnection.
            // Writing before reading lets a just-completed TLS handshake send its queued request in the
            // same batch, and avoids walking readable twice (the prior implementation recomputed rid twice).
            foreach ($events->readable as $socket) {
                if (!is_resource($socket)) {
                    continue;
                }
                $rid = get_resource_id($socket);

                if ($this->dnsResolver->isDnsSocket($rid)) {
                    $this->dnsResolver->processReadable($socket);
                } elseif (isset($this->socketIndex[$rid])) {
                    $this->readConnection($this->socketIndex[$rid]);
                }
            }

            foreach ($events->exceptional as $socket) {
                if (!is_resource($socket)) {
                    continue;
                }
                $rid = get_resource_id($socket);

                if (isset($this->socketIndex[$rid])) {
                    $this->failConnection($this->socketIndex[$rid], 'socket exception');
                }
            }

            $totalProgress = true;
        }

        return $totalProgress;
    }

    private function writeConnection(?int $id): void
    {
        if ($id === null || !isset($this->activeConnections[$id])) {
            return;
        }

        $conn = $this->activeConnections[$id];

        if ($conn->state === ConnectionState::CONNECTING && $conn->secure) {
            $conn->state = ConnectionState::TLS_HANDSHAKE;
            if ($conn->timing !== null) {
                $conn->timing->tlsStart ??= microtime(true);
            }
        }

        if ($conn->state === ConnectionState::TLS_HANDSHAKE) {
            $tlsResult = $this->finalizeTls($conn);
            if ($tlsResult === true) {
                $conn->state = ConnectionState::READY;
                if ($conn->timing !== null) {
                    $conn->timing->tlsEnd ??= microtime(true);
                }
            } elseif ($tlsResult === false) {
                $this->failConnection($id, 'TLS handshake failed');

                return;
            } else {
                // 0 (EAGAIN): handshake is waiting for inbound bytes (ServerHello/Certificate).
                // stream_socket_enable_crypto reads/writes internally; the next step advances on a
                // read event in readConnection(). Remove the socket from the write set — an empty
                // TCP send buffer is always writable, otherwise writeConnection would call
                // finalizeTls every tick (spin).
                $this->reactor->removeFromWrite($conn);

                return;
            }
        }

        if ($conn->state === ConnectionState::CONNECTING && !$conn->secure) {
            $conn->state = ConnectionState::READY;
        }

        if ($conn->state === ConnectionState::READY) {
            $this->detectProtocol($id, $conn);

            if ($conn->protocol === null) {
                return;
            }
        }

        if ($conn->writePayload === '') {
            return;
        }

        $conn->state = ConnectionState::WRITING;
        if ($conn->timing !== null) {
            $conn->timing->writeStart ??= microtime(true);
        }

        $written = @fwrite($conn->socket, $conn->writePayload);

        if ($written === false || $written === 0) {
            return;
        }

        $conn->written += $written;
        $conn->lastActivity = microtime(true);

        if ($written < strlen($conn->writePayload)) {
            $conn->writePayload = substr($conn->writePayload, $written);
        } else {
            $conn->writePayload = '';
        }

        if ($conn->writePayload === '') {
            $conn->state = ConnectionState::READING;
            if ($conn->timing !== null) {
                $conn->timing->writeEnd ??= microtime(true);
            }
            $this->reactor->removeFromWrite($conn);
        }
    }

    private function detectProtocol(int $id, PooledConnection $s): void
    {
        $meta = stream_get_meta_data($s->socket);
        $alpn = $meta['crypto']['alpn_negotiated'] ?? null;

        $request = $s->request;

        if ($request === null) {
            throw new ASKNetworkException('Request is not set');
        }

        if ($alpn !== null && str_starts_with($alpn, 'h2')) {
            $s->protocol = self::PROTOCOL_HTTP_2;

            $h2 = new Http2Connection();
            $s->processor = $h2;

            $path = $s->path ?: $this->extractPath($request->url);
            $s->writePayload = $h2->getInitialFrames()
                .$h2->buildRequest($request->method, $s->host, $path, $request->body ?? '', $request->headers);
            $s->written = 0;

            return;
        }

        $s->protocol = self::PROTOCOL_HTTP_1_1;
        $s->processor = new Http1Processor();

        $this->prepareHttp1Payload($s, $s->request, $s->host);
    }

    private function completeHandshakeBlocking(PooledConnection $conn): bool
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            if (!is_resource($conn->socket)) {
                return false;
            }

            $read = [$conn->socket];
            $write = [$conn->socket];
            $except = [$conn->socket];

            $changed = @stream_select($read, $write, $except, 0, 100_000);

            if ($changed === false) {
                return false;
            }

            if ($changed === 0) {
                continue;
            }

            $tlsResult = $this->finalizeTls($conn);

            if ($tlsResult === true) {
                $conn->state = ConnectionState::READY;
                return true;
            }

            if ($tlsResult === false) {
                $this->closeAndClear($conn);

                return false;
            }
        }

        $this->closeAndClear($conn);

        return false;
    }

    private function openConnection(
        string $connectHost,
        string $requestHost,
        int $port,
        string $key,
        ?array $pending = null,
        bool $secure = true,
        ?RequestTiming $timing = null,
    ): ?PooledConnection {
        $connectStart = microtime(true);
        $socket = $this->createSocket($connectHost, $requestHost, $port, $secure);

        if ($socket === null) {
            return null;
        }

        $connectEnd = microtime(true);
        $this->metrics->recordConnect($connectEnd - $connectStart);

        $conn = new PooledConnection(
            socket: $socket,
            key: $key,
            host: $requestHost,
            port: $port,
            startedAt: microtime(true),
        );
        $conn->secure = $secure;
        $conn->state = ConnectionState::CONNECTING;

        if ($timing !== null) {
            $timing->connectStart = $connectStart;
            $timing->connectEnd = $connectEnd;
            $timing->url = $pending['request']->url ?? null;
            $conn->timing = $timing;
        }

        if ($pending !== null) {
            $conn->request = $pending['request'];
            $conn->path = $pending['path'] ?? $this->extractPath($pending['request']->url);
        }

        if ($this->config->keepAlive) {
            $conn->protocol = self::PROTOCOL_HTTP_1_1;
            $conn->processor = new Http1Processor();

            if ($pending !== null) {
                $this->prepareHttp1Payload($conn, $pending['request'], $pending['host'], $pending['path'] ?? null);
            }
        }

        return $conn;
    }

    private function createSocket(
        string $connectHost,
        string $requestHost,
        int $port,
        bool $secure,
    ): mixed {
        $contextOptions = [
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ];

        if ($secure) {
            $contextOptions['ssl'] = [
                'peer_name' => $requestHost,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'alpn_protos' => $this->config->effectiveAlpn(),
                'allow_self_signed' => false,
            ];
        }

        $context = stream_context_create($contextOptions);

        $socket = @stream_socket_client(
            "tcp://{$connectHost}:{$port}",
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context,
        );

        if (!$socket) {
            return null;
        }

        stream_set_blocking($socket, false);

        return $socket;
    }

    private function prepareHttp1Payload(
        PooledConnection $conn,
        ASKHttpRequest $request,
        string $host,
        ?string $path = null,
    ): void {
        $conn->request = $request;
        $conn->path = $path ?? $conn->path ?? $this->extractPath($request->url);
        $conn->writePayload = $this->buildHttpRequest(
            method: $request->method,
            host: $host,
            path: $conn->path,
            body: $request->body ?? '',
            headers: $request->headers,
        );
        $conn->written = 0;
    }

    private function readConnection(?int $socketId): void
    {
        if ($socketId === null) {
            return;
        }

        $conn = $this->activeConnections[$socketId] ?? null;

        if ($conn === null) {
            return;
        }

        if ($conn->socket === null || !is_resource($conn->socket)) {
            if (isset($this->connectionPromises[$socketId])) {
                $this->failConnection($socketId, 'Connection closed prematurely by remote peer');
            } else {
                unset($this->activeConnections[$socketId]);
            }

            return;
        }

        if ($conn->state === ConnectionState::CONNECTING && $conn->secure) {
            $conn->state = ConnectionState::TLS_HANDSHAKE;
            if ($conn->timing !== null) {
                $conn->timing->tlsStart ??= microtime(true);
            }
        }

        if ($conn->state === ConnectionState::TLS_HANDSHAKE) {
            $tlsResult = $this->finalizeTls($conn);
            if ($tlsResult === true) {
                $conn->state = ConnectionState::READY;
                if ($conn->timing !== null) {
                    $conn->timing->tlsEnd ??= microtime(true);
                }
                // Handshake completed on a read event: the socket was removed from the write set
                // in writeConnection(). Re-register it so the outbound payload (request) can be
                // sent in the next writeConnection().
                $this->reactor->register($conn);
            } elseif ($tlsResult === false) {
                $this->failConnection($socketId, 'TLS handshake failed');

                return;
            } else {
                return;
            }
        }

        if ($conn->state === ConnectionState::READY) {
            $this->detectProtocol($socketId, $conn);

            if ($conn->protocol === null) {
                return;
            }
        }

        $chunk = @fread($conn->socket, 65536);

        if ($chunk === false) {
            $this->failConnection($socketId, 'Socket read error');

            return;
        }

        if ($chunk === '') {
            if (feof($conn->socket)) {
                if (isset($this->connectionPromises[$socketId])) {
                    $this->failConnection($socketId, 'Connection closed prematurely by remote peer');
                } else {
                    $conn->state = ConnectionState::CLOSED;
                    $this->decrementActiveCount($conn->key);
                    $this->metrics->recordConnectionClosed($conn->useCount, microtime(true) - $conn->createdAt);
                    $this->closeAndClear($conn);
                    $this->reactor->unregister($conn);
                    unset($this->activeConnections[$socketId], $this->leases[$socketId], $this->socketIndex[$socketId]);
                }
            }

            return;
        }

        $conn->readBuffer .= $chunk;
        $conn->lastActivity = microtime(true);
        $conn->state = ConnectionState::READING;
        if ($conn->timing !== null) {
            $conn->timing->firstByte ??= microtime(true);
        }

        if ($conn->processor === null) {
            return;
        }

        try {
            $parseStart = microtime(true);
            $response = $conn->processor->handleBuffer($conn->readBuffer);
            $this->metrics->recordHttpParse(microtime(true) - $parseStart);
        } catch (ASKInterruptException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->failConnection($socketId, $e->getMessage());

            return;
        }

        $outbound = $conn->processor->drainOutbound();
        if ($outbound !== '' && $conn->socket !== null && is_resource($conn->socket)) {
            @fwrite($conn->socket, $outbound);
        }

        if ($response === null) {
            return;
        }

        if ($conn->timing !== null) {
            $conn->timing->bodyEnd ??= microtime(true);
            $this->metrics->recordRequestTiming($conn->timing);
        }

        $promise = $this->connectionPromises[$socketId] ?? null;
        $key = $conn->key;
        $this->reactor->unregister($conn);
        unset(
            $this->activeConnections[$socketId],
            $this->connectionPromises[$socketId],
            $this->leases[$socketId],
            $this->socketIndex[$socketId],
        );

        $this->releaseOrClose($conn, $response, $key);

        if ($promise !== null) {
            try {
                $promise->resolve($response);
            } catch (ASKInterruptException $e) {
                throw $e;
            } catch (Throwable $e) {
                $promise->reject($e);
            }
        }
    }

    private function releaseOrClose(PooledConnection $conn, ResponseInterface $response, ?string $key = null): void
    {
        $lifetime = microtime(true) - $conn->createdAt;
        $connKey = $key ?? $conn->key;

        if (!$this->config->keepAlive) {
            $this->decrementActiveCount($connKey);
            $this->metrics->recordConnectionClosed($conn->useCount, $lifetime);
            $this->closeAndClear($conn);

            return;
        }

        if ($conn->socket === null || !is_resource($conn->socket)) {
            $this->decrementActiveCount($connKey);
            $this->clearConnectionBuffers($conn);
            $this->metrics->recordConnectionClosed($conn->useCount, $lifetime);

            return;
        }

        $connectionHeader = strtolower($response->getHeaderLine('Connection'));

        $http1 = $response->getProtocolVersion() === '1.0';

        $serverForcesClose =
            $connectionHeader === 'close'
            || ($http1 && $connectionHeader !== 'keep-alive');

        if ($serverForcesClose) {
            $this->decrementActiveCount($connKey);
            $this->metrics->recordConnectionClosed($conn->useCount, $lifetime);
            $this->closeAndClear($conn);

            return;
        }

        $isAlive = is_resource($conn->socket) && !feof($conn->socket);

        if (!$isAlive) {
            $this->decrementActiveCount($connKey);
            $this->metrics->recordConnectionClosed($conn->useCount, $lifetime);
            $this->closeAndClear($conn);

            return;
        }

        // Parse the server's idle-timeout hint: "Keep-Alive: timeout=60, max=1000".
        // Only `timeout=` matters for eviction; `max=` is a request-count limit we do not enforce.
        $keepAliveHeader = $response->getHeaderLine('Keep-Alive');
        if ($keepAliveHeader !== '' && preg_match('/timeout=(\d+)/i', $keepAliveHeader, $m) === 1) {
            $conn->serverTimeout = (float)$m[1];
        }

        $this->decrementActiveCount($connKey);
        $this->clearConnectionBuffers($conn);
        $this->connectionPool->release($conn);
    }

    private function buildHttpRequest(
        string $method,
        string $host,
        string $path,
        string $body,
        array $headers = [],
    ): string {
        $lines = [];
        $lines[] = "{$method} {$path} HTTP/1.1";
        $lines[] = "Host: {$host}";

        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        if (!isset($headers['User-Agent'])) {
            $lines[] = 'User-Agent: AskHttpSocketClient/1.0';
        }

        if (!isset($headers['Accept'])) {
            $lines[] = 'Accept: application/json';
        }

        if ($body !== '' && !isset($headers['Content-Type'])) {
            $lines[] = 'Content-Type: application/json';
        }

        if (!isset($headers['Content-Length'])) {
            $lines[] = 'Content-Length: '.\strlen($body);
        }

        $connectionDirective = $this->config->keepAlive ? 'keep-alive' : 'close';
        $lines[] = "Connection: {$connectionDirective}";

        return implode("\r\n", $lines)."\r\n\r\n".$body;
    }

    private function failConnection(int $socketId, string $reason): void
    {
        $promise = $this->connectionPromises[$socketId] ?? null;
        $conn = $this->activeConnections[$socketId] ?? null;

        if ($conn !== null) {
            $this->reactor->unregister($conn);
        }
        unset(
            $this->activeConnections[$socketId],
            $this->connectionPromises[$socketId],
            $this->leases[$socketId],
            $this->socketIndex[$socketId],
        );

        if ($conn) {
            $conn->state = ConnectionState::CLOSED;
            $this->decrementActiveCount($conn->key);
            $lifetime = microtime(true) - $conn->createdAt;
            $this->metrics->recordConnectionClosed($conn->useCount, $lifetime);
            $this->closeAndClear($conn);
        }

        $promise?->reject(new ASKNetworkException($reason));
    }

    private function closeAndClear(PooledConnection $conn): void
    {
        $conn->state = ConnectionState::CLOSED;

        if (is_resource($conn->socket)) {
            $rid = get_resource_id($conn->socket);
            @fclose($conn->socket);

            unset(
                $this->activeConnections[$rid],
                $this->connectionPromises[$rid],
                $this->leases[$rid],
                $this->socketIndex[$rid]
            );
        }

        $this->clearConnectionBuffers($conn);
    }

    private function clearConnectionBuffers(PooledConnection $conn): void
    {
        $conn->readBuffer = '';
        $conn->writePayload = '';
        $conn->written = 0;
    }

    private function createLease(PooledConnection $conn, ASKPromise $promise): ConnectionLease
    {
        return new ConnectionLease(
            connection: $conn,
            onRelease: function (PooledConnection $released): void {
            },
        );
    }

    private function socketIdOf(PooledConnection $conn): int
    {
        return get_resource_id($conn->socket);
    }

    private function isSocketAlive(PooledConnection $conn): bool
    {
        if ($conn->socket === null || !is_resource($conn->socket)) {
            return false;
        }

        // Active probe via STREAM_PEEK: a peer that sent FIN/RST makes the next read surface
        // EOF. On non-blocking sockets (which all pooled sockets are) a healthy idle connection
        // returns false from recvfrom with feof still false (would-block, no data) — so feof()
        // is the real discriminator, not the return value.
        //   false + feof=false → healthy (would-block)
        //   ''    + feof=true  → peer closed (EOF observed)
        //   non-empty          → stale pipelined bytes → reject (never reuse a dirty conn)
        $peek = @stream_socket_recvfrom($conn->socket, 1, STREAM_PEEK);

        if ($peek !== false && $peek !== '') {
            return false;
        }

        return !feof($conn->socket);
    }

    private function finalizeTls(PooledConnection $conn): ?bool
    {
        if ($conn->state === ConnectionState::READY
            || $conn->state === ConnectionState::WRITING
            || $conn->state === ConnectionState::READING
            || $conn->state === ConnectionState::IDLE
        ) {
            return true;
        }

        if (!is_resource($conn->socket)) {
            return false;
        }

        $start = microtime(true);
        $result = @stream_socket_enable_crypto(
            $conn->socket,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );

        $duration = microtime(true) - $start;

        if ($result === true) {
            $conn->state = ConnectionState::READY;
            $this->metrics->recordTlsHandshake($duration, true);

            $meta = @stream_get_meta_data($conn->socket);
            $version = $meta['crypto']['protocol'] ?? 'unknown';
            $this->metrics->recordTlsVersion($version);

            return true;
        }

        if ($result === false) {
            $this->metrics->recordTlsHandshake($duration, false);

            return false;
        }

        return null;
    }

    private function resolveSync(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        return $this->dnsResolver->resolveBlocking($host);
    }

    private function resolveHost(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        return $this->dnsResolver->resolve($host);
    }

    public function idlePoolSize(): int
    {
        return $this->connectionPool->idleCount();
    }

    public function drain(): void
    {
        $deadline = microtime(true) + 30.0;

        while (!$this->isIdle() && microtime(true) < $deadline) {
            $this->tick(0);
        }
    }

    public function isIdle(): bool
    {
        return $this->queue->isEmpty() && $this->activeConnections === [];
    }

    public function queueSize(): int
    {
        return $this->queue->count() + count($this->activeConnections);
    }

    private function extractPath(string $url): string
    {
        $parsed = parse_url($url);

        return ($parsed['path'] ?? '/')
            .(isset($parsed['query']) ? "?{$parsed['query']}" : '');
    }

    public function tickable(): array
    {
        return [$this];
    }

    public function __destruct()
    {
        foreach ($this->leases as $lease) {
            $lease->release();
        }
        $this->connectionPool->closeAll();
    }
}
