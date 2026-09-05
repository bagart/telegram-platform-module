<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

final class DnsSocketTransport
{
    private const int DNS_PORT = 53;
    private const int DNS_OVER_TLS_PORT = 853;

    private readonly bool $useTls;

    /** @var array<int, string> Resource ID => host */
    private array $socketToHost = [];

    /** @var array<int, int> Resource ID => socket index (0-primary, 1-secondary) */
    private array $socketToIdx = [];

    /** @var array<int, resource> Resource ID => resource */
    private array $readSockets = [];

    public function __construct(bool $useTls = false)
    {
        $this->useTls = $useTls;
    }

    public function create(string $host, int $serverIndex, string $serverIp): mixed
    {
        $port = $this->useTls ? self::DNS_OVER_TLS_PORT : self::DNS_PORT;
        $uri = $this->useTls ? 'tls://'.$serverIp.':'.$port : 'udp://'.$serverIp.':'.$port;

        $context = null;
        if ($this->useTls) {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
        }

        $socket = @stream_socket_client(
            $uri,
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_ASYNC_CONNECT,
            $context,
        );

        if (!$socket) {
            return null;
        }

        if ($this->useTls) {
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $result = @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                );
                if ($result === true) {
                    break;
                }
                if ($result === false) {
                    @fclose($socket);
                    return null;
                }
                usleep(1_000);
            }
            if (microtime(true) >= $deadline) {
                @fclose($socket);
                return null;
            }
        }

        stream_set_blocking($socket, false);

        $rid = get_resource_id($socket);
        $this->readSockets[$rid] = $socket;
        $this->socketToHost[$rid] = $host;
        $this->socketToIdx[$rid] = $serverIndex;

        return $socket;
    }

    /** @return array<int, resource> */
    public function getReadSockets(): array
    {
        return array_values($this->readSockets);
    }

    public function read(mixed $socket): ?string
    {
        if (!is_resource($socket)) {
            return null;
        }

        $data = @fread($socket, 512);

        if ($data === false || $data === '') {
            return null;
        }

        return $data;
    }

    public function close(mixed $socket): void
    {
        if (!is_resource($socket)) {
            return;
        }

        $rid = get_resource_id($socket);

        unset(
            $this->readSockets[$rid],
            $this->socketToHost[$rid],
            $this->socketToIdx[$rid],
        );

        @fclose($socket);
    }

    /** @return resource|false */
    public function getSocket(int $rid): mixed
    {
        return $this->readSockets[$rid] ?? false;
    }

    public function getHost(mixed $socket): ?string
    {
        if (!is_resource($socket)) {
            return null;
        }

        return $this->socketToHost[get_resource_id($socket)] ?? null;
    }

    public function getSocketIndex(mixed $socket): ?int
    {
        if (!is_resource($socket)) {
            return null;
        }

        return $this->socketToIdx[get_resource_id($socket)] ?? null;
    }

    public function isDnsSocket(int $rid): bool
    {
        return isset($this->readSockets[$rid]);
    }

    public function flush(): void
    {
        foreach ($this->readSockets as $socket) {
            @fclose($socket);
        }
        $this->socketToHost = [];
        $this->socketToIdx = [];
        $this->readSockets = [];
    }

    public function closeAll(array $sockets): void
    {
        foreach ($sockets as $socket) {
            $this->close($socket);
        }
    }
}
