<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\SocketClient\Reactor;

use BAGArt\ASKClient\SocketClient\Connection\ConnectionState;
use BAGArt\ASKClient\SocketClient\Connection\PooledConnection;

final class SocketReactor
{
    /**
     * Default microseconds to block in stream_select when read sockets are pending but
     * there is no write work. Lower = tighter polling (catches ready sockets sooner).
     */
    public const int DEFAULT_POLL_INTERVAL_US = 1_000;

    /**
     * Microseconds to block when no sockets are registered at all. Higher than the
     * read-pending interval to avoid busy-looping when the reactor has nothing to poll.
     */
    private const int IDLE_TIMEOUT_US = 10_000;

    /** @var array<int, resource> */
    private array $readSockets = [];

    /** @var array<int, resource> */
    private array $writeSockets = [];

    /** @var array<int, resource> */
    private array $exceptSockets = [];

    public function __construct(
        private readonly int $pollIntervalUs = self::DEFAULT_POLL_INTERVAL_US,
    ) {
    }

    public function register(PooledConnection $connection): void
    {
        if (!is_resource($connection->socket)) {
            return;
        }

        $rid = get_resource_id($connection->socket);
        $this->readSockets[$rid] = $connection->socket;
        $this->exceptSockets[$rid] = $connection->socket;

        if ($this->needsWrite($connection)) {
            $this->writeSockets[$rid] = $connection->socket;
        } else {
            unset($this->writeSockets[$rid]);
        }
    }

    /**
     * Removes a socket from the write set unconditionally. Used when the socket should no
     * longer be polled for writability (e.g. an empty TCP send buffer during TLS handshake
     * that is now waiting for inbound bytes, or after the write buffer has been fully sent).
     */
    public function removeFromWrite(PooledConnection $connection): void
    {
        if (!is_resource($connection->socket)) {
            return;
        }

        unset($this->writeSockets[get_resource_id($connection->socket)]);
    }

    public function unregister(PooledConnection $connection): void
    {
        if (!is_resource($connection->socket)) {
            return;
        }

        $rid = get_resource_id($connection->socket);
        unset(
            $this->readSockets[$rid],
            $this->writeSockets[$rid],
            $this->exceptSockets[$rid],
        );
    }

    /**
     * @param  list<resource>  $extraRead
     */
    public function tick(array $extraRead = []): ReactorEvents
    {
        if ($this->readSockets === [] && $this->writeSockets === [] && $extraRead === []) {
            return new ReactorEvents();
        }

        $read = $this->readSockets;
        $write = $this->writeSockets;
        $except = $this->exceptSockets;

        if ($extraRead !== []) {
            foreach ($extraRead as $sock) {
                if (is_resource($sock)) {
                    $read[] = $sock;
                    $except[] = $sock;
                }
            }
        }

        // Adaptive select timeout:
        // 1. Pending write work -> 0 us (flush immediately, never block).
        // 2. Read sockets pending -> pollIntervalUs (tight polling for inbound readiness).
        // 3. Nothing to poll     -> IDLE_TIMEOUT_US (avoid busy-loop).
        if ($write !== []) {
            $usec = 0;
        } elseif ($read !== []) {
            $usec = $this->pollIntervalUs;
        } else {
            $usec = self::IDLE_TIMEOUT_US;
        }

        $changed = @stream_select(
            $read,
            $write,
            $except,
            0,
            $usec,
        );

        if ($changed === false || $changed <= 0) {
            return new ReactorEvents();
        }

        return new ReactorEvents(
            readable: $read,
            writable: $write,
            exceptional: $except,
        );
    }

    private function needsWrite(PooledConnection $conn): bool
    {
        // Non-blocking TCP connect (STREAM_CLIENT_ASYNC_CONNECT) — the socket becomes
        // writable once the SYN-ACK round-trip completes.
        if ($conn->state === ConnectionState::CONNECTING) {
            return true;
        }

        // Non-blocking TLS handshake: stream_socket_enable_crypto performs reads and writes
        // internally. The handshake needs write-readiness to send ClientHello/Finished, so
        // the socket must stay in the write set during TLS_HANDSHAKE.
        if ($conn->state === ConnectionState::TLS_HANDSHAKE) {
            return true;
        }

        // Outbound payload pending (request bytes or HTTP/2 frames).
        return $conn->writePayload !== '';
    }
}
