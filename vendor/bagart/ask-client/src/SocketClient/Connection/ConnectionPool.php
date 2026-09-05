<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\SocketClient\Connection;

final class ConnectionPool
{
    /** @var PooledConnection */
    private array $idle = [];

    /** Total idle connections across all keys. */
    private int $totalCount = 0;

    public function __construct(
        private readonly int $maxIdlePerHost,
        private readonly int $maxIdleTotal,
        private readonly float $idleTimeout,
        private readonly float $maxLifetime = INF,
    ) {
    }

    public function tryAcquire(string $key): ?PooledConnection
    {
        if (!isset($this->idle[$key]) || $this->idle[$key] === []) {
            unset($this->idle[$key]);
            return null;
        }

        $now = microtime(true);

        // LIFO reuse
        while (isset($this->idle[$key]) && $this->idle[$key] !== []) {
            /** @var PooledConnection $conn */
            $conn = array_pop($this->idle[$key]);
            $this->totalCount--;

            if ($this->idle[$key] === []) {
                unset($this->idle[$key]);
            }

            if ($this->isConnectionAlive($conn, $now)) {
                $conn->resetForReuse();
                return $conn;
            }

            $this->close($conn);
        }

        return null;
    }

    public function release(PooledConnection $conn): void
    {
        if ($conn->socket === null || !is_resource($conn->socket)) {
            return;
        }

        $key = $conn->key;
        $hostCount = isset($this->idle[$key]) ? count($this->idle[$key]) : 0;

        if (
            $this->totalCount >= $this->maxIdleTotal ||
            $hostCount >= $this->maxIdlePerHost
        ) {
            $this->close($conn);
            return;
        }

        $conn->resetForReuse();
        $conn->lastActivity = microtime(true);

        $this->idle[$key][] = $conn;
        $this->totalCount++;
    }

    public function evictIdle(): int
    {
        if ($this->idle === []) {
            return 0;
        }

        $now = microtime(true);
        $evicted = 0;

        foreach ($this->idle as $key => $list) {
            $kept = [];
            foreach ($list as $conn) {
                if ($this->isExpired($conn, $now)) {
                    $this->close($conn);
                    $this->totalCount--;
                    $evicted++;
                } else {
                    $kept[] = $conn;
                }
            }

            if ($kept === []) {
                unset($this->idle[$key]);
            } else {
                $this->idle[$key] = $kept;
            }
        }

        return $evicted;
    }

    private function isExpired(PooledConnection $conn, float $now): bool
    {
        if ($conn->socket === null || !is_resource($conn->socket)) {
            return true;
        }

        if (($now - $conn->createdAt) > $this->maxLifetime) {
            return true;
        }

        $idleLimit = $conn->serverTimeout !== null
            ? min($conn->serverTimeout, $this->idleTimeout)
            : $this->idleTimeout;

        if (($now - $conn->lastActivity) > $idleLimit) {
            return true;
        }

        return !$this->isConnectionAlive($conn, $now);
    }

    public function closeAll(): void
    {
        foreach ($this->idle as $list) {
            foreach ($list as $conn) {
                $this->close($conn);
            }
        }

        $this->idle = [];
        $this->totalCount = 0;
    }

    public function idleCount(): int
    {
        return $this->totalCount;
    }

    public function idleCountForHost(string $key): int
    {
        return isset($this->idle[$key]) ? count($this->idle[$key]) : 0;
    }

    /**
     * Verifies physical socket health and checks max lifetime bounds.
     *
     * The STREAM_PEEK probe runs with the socket temporarily switched to non-blocking mode so
     * it never hangs on a blocking socket (e.g. a freshly constructed loopback pair in tests).
     * Production sockets are already non-blocking; the toggle is a no-op there.
     */
    private function isConnectionAlive(PooledConnection $conn, float $now): bool
    {
        if ($conn->socket === null || !is_resource($conn->socket)) {
            return false;
        }

        if (($now - $conn->createdAt) > $this->maxLifetime) {
            return false;
        }

        if (feof($conn->socket)) {
            return false;
        }

        $wasBlocking = (bool)stream_get_meta_data($conn->socket)['blocked'] ?? false;
        if ($wasBlocking) {
            stream_set_blocking($conn->socket, false);
        }

        try {
            $peek = @stream_socket_recvfrom($conn->socket, 1, STREAM_PEEK);
        } finally {
            if ($wasBlocking) {
                stream_set_blocking($conn->socket, true);
            }
        }

        if ($peek !== false && $peek !== '') {
            return false;
        }

        return true;
    }

    private function close(PooledConnection $conn): void
    {
        if (is_resource($conn->socket)) {
            @fclose($conn->socket);
        }
        $conn->socket = null;
    }
}
