<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Tests;

use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\SocketClient\AskHttpSocketClient;
use BAGArt\ASKClient\SocketClient\Connection\ConnectionPool;
use BAGArt\ASKClient\SocketClient\Connection\PooledConnection;
use BAGArt\ASKClient\SocketClient\HttpsSocketClientConfig;

/**
 * Helper: build a PooledConnection wrapping a live duplex TCP socket pair (loopback).
 * Uses STREAM_PF_INET to stay portable across Windows and POSIX, since stream_socket_pair
 * with STREAM_PF_UNIX is unsupported on Windows.
 *
 * The peer side is kept alive (held in $peerKeepalive) so the client socket passes the
 * active STREAM_PEEK health probe in the pool — closing the peer would make the socket
 * half-closed and the probe would (correctly) reject it. Peers are closed in tearDown to
 * avoid leaking fds across tests.
 */
class PeerKeepalive
{
    /** @var list<resource> */
    public static array $peers = [];
}

function makePooledConnection(string $key = 'example.com:443', string $host = 'example.com'): PooledConnection
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$server) {
        throw new \RuntimeException("stream_socket_server failed: [{$errno}] {$errstr}");
    }

    $port = (int)substr(
        stream_socket_get_name($server, false),
        strrpos(stream_socket_get_name($server, false), ':') + 1
    );
    $client = stream_socket_client("tcp://127.0.0.1:{$port}");
    $accepted = @stream_socket_accept($server, 1);
    fclose($server);

    if ($accepted === false) {
        throw new \RuntimeException('stream_socket_accept failed');
    }

    PeerKeepalive::$peers[] = $accepted;

    $conn = new PooledConnection(
        socket: $client,
        key: $key,
        host: $host,
        port: 443,
        startedAt: microtime(true),
    );
    $conn->protocol = 'http/1.1';

    return $conn;
}

describe('ConnectionPool', function () {
    afterEach(function () {
        foreach (PeerKeepalive::$peers as $peer) {
            if (is_resource($peer)) {
                @fclose($peer);
            }
        }
        PeerKeepalive::$peers = [];
    });

    it('releases and acquires a connection for the same key', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0);
        expect($pool->idleCount())->toBe(0);

        $conn = makePooledConnection('api.telegram.org:443');
        $pool->release($conn);

        expect($pool->idleCount())->toBe(1);
        expect($pool->idleCountForHost('api.telegram.org:443'))->toBe(1);

        $reused = $pool->tryAcquire('api.telegram.org:443');

        expect($reused)->not->toBeNull()
            ->and($reused)->toBe($conn)
            ->and($pool->idleCount())->toBe(0);
    });

    it('returns null when no idle connection exists for the key', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0);

        expect($pool->tryAcquire('unknown.host:443'))->toBeNull();
    });

    it('drops connections beyond the per-host cap', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 2, maxIdleTotal: 16, idleTimeout: 30.0);

        $kept = makePooledConnection();
        $overflow = makePooledConnection();

        $pool->release($kept);
        $pool->release($overflow);

        expect($pool->idleCount())->toBe(2);

        $third = makePooledConnection();
        $pool->release($third);

        // Cap is 2 — third release must drop the connection instead of storing it.
        expect($pool->idleCount())->toBe(2)
            ->and($third->socket)->toBeNull();
    });

    it('enforces the global idle total across hosts', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 10, maxIdleTotal: 2, idleTimeout: 30.0);

        $pool->release(makePooledConnection('a.host:443'));
        $pool->release(makePooledConnection('b.host:443'));

        $over = makePooledConnection('c.host:443');
        $pool->release($over);

        expect($pool->idleCount())->toBe(2)
            ->and($over->socket)->toBeNull();
    });

    it('evicts idle connections past the timeout', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 0.05);

        $pool->release(makePooledConnection());

        expect($pool->idleCount())->toBe(1);

        usleep(60_000); // > 50ms timeout
        $evicted = $pool->evictIdle();

        expect($evicted)->toBe(1)
            ->and($pool->idleCount())->toBe(0);
    });

    it('does not evict connections still within the timeout', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 10.0);

        $pool->release(makePooledConnection());
        $evicted = $pool->evictIdle();

        expect($evicted)->toBe(0)
            ->and($pool->idleCount())->toBe(1);
    });

    it('resets per-request state on acquire for reuse', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0);

        $conn = makePooledConnection();
        $conn->writePayload = 'stale payload';
        $conn->written = 42;
        $conn->readBuffer = 'leftover';

        $pool->release($conn);
        $reused = $pool->tryAcquire($conn->key);

        expect($reused->writePayload)->toBe('')
            ->and($reused->written)->toBe(0)
            ->and($reused->readBuffer)->toBe('');
    });

    it('drops a connection whose socket was already closed', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0);

        $dead = makePooledConnection();
        fclose($dead->socket);
        $dead->socket = null;

        $pool->release($dead);

        expect($pool->idleCount())->toBe(0);
    });

    it('evicts a connection that exceeds the max lifetime even if idle time is short', function () {
        // A server/NAT/LB may silently drop a connection well before idleTimeout. The hard
        // maxLifetime ceiling must evict it regardless of lastActivity.
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0, maxLifetime: 0.05);

        $pool->release(makePooledConnection());

        usleep(60_000); // > 50ms lifetime
        $evicted = $pool->evictIdle();

        expect($evicted)->toBe(1)
            ->and($pool->idleCount())->toBe(0);
    });

    it('does not hand out a lifetime-expired connection from tryAcquire', function () {
        // Belt-and-suspenders: even if evictIdle() has not run yet, tryAcquire() must refuse
        // a connection past its maxLifetime and keep draining for a fresh one.
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0, maxLifetime: 0.05);

        $conn = makePooledConnection();
        $pool->release($conn);

        usleep(60_000); // > 50ms lifetime
        $acquired = $pool->tryAcquire($conn->key);

        expect($acquired)->toBeNull()
            ->and($pool->idleCount())->toBe(0);
    });

    it('treats maxLifetime=INF as "no lifetime limit"', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0, maxLifetime: INF);

        $conn = makePooledConnection();
        $pool->release($conn);

        expect($pool->evictIdle())->toBe(0)
            ->and($pool->tryAcquire($conn->key))->not->toBeNull();
    });

    it('uses the server-advertised timeout as a ceiling over the configured idleTimeout', function () {
        // The server sends "Keep-Alive: timeout=1" — even if our idleTimeout is 30s, the
        // connection must be evicted after 1s of idleness because the server will close first.
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0);

        $conn = makePooledConnection();
        $conn->serverTimeout = 0.05; // server promised a 50ms idle window
        $pool->release($conn);

        usleep(60_000); // > server-advertised 50ms
        $evicted = $pool->evictIdle();

        expect($evicted)->toBe(1)
            ->and($pool->idleCount())->toBe(0);
    });

    it('falls back to the configured idleTimeout when the server did not advertise one', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0);

        $conn = makePooledConnection();
        expect($conn->serverTimeout)->toBeNull();
        $pool->release($conn);

        // Only 60ms idle, well under the 30s configured timeout — must survive.
        usleep(60_000);

        expect($pool->evictIdle())->toBe(0)
            ->and($pool->idleCount())->toBe(1);
    });
});

describe('HttpsSocketClientConfig', function () {
    it('defaults to connection pooling enabled, http/1.1 ALPN', function () {
        $cfg = new HttpsSocketClientConfig();

        expect($cfg->keepAlive)->toBeTrue()
            ->and($cfg->http2Enabled)->toBeFalse()
            ->and($cfg->effectiveAlpn())->toBe('http/1.1');
    });

    it('advertises h2 when http2Enabled is true', function () {
        $cfg = new HttpsSocketClientConfig(http2Enabled: true);

        expect($cfg->effectiveAlpn())->toBe('h2,http/1.1');
    });

    it('honours an explicit alpn override over http2Enabled', function () {
        $cfg = new HttpsSocketClientConfig(http2Enabled: true, alpnProtos: 'h2');

        expect($cfg->effectiveAlpn())->toBe('h2');
    });

    it('separates ALPN from keepAlive — http2Enabled controls capability', function () {
        $withoutH2 = new HttpsSocketClientConfig(keepAlive: false, http2Enabled: false);
        $withH2 = new HttpsSocketClientConfig(keepAlive: false, http2Enabled: true);

        expect($withoutH2->effectiveAlpn())->toBe('http/1.1')
            ->and($withH2->effectiveAlpn())->toBe('h2,http/1.1');
    });

    it('enforces a per-host connection cap by default', function () {
        $cfg = new HttpsSocketClientConfig();

        expect($cfg->maxConnectionsPerHost)->toBe(20);
    });

    it('defaults maxLifetime to a conservative 60s', function () {
        // Guards against servers/NAT/LBs that silently drop connections before idleTimeout.
        expect((new HttpsSocketClientConfig())->maxLifetime)->toBe(60.0);
    });

    it('defaults maxConnectionsTotal to 128 (well under FD_SETSIZE 1024)', function () {
        // Prevents stream_select(): Bad file descriptor when many hosts fan out.
        expect((new HttpsSocketClientConfig())->maxConnectionsTotal)->toBe(128);
    });
});

/*
 * Half-open detection contract: a passive feof() check lies when the peer has sent a TCP FIN
 * (half-close) — PHP only learns about it on the next read attempt. The active STREAM_PEEK probe
 * in isSocketAlive() must catch it. These tests assert the probe contract directly on a loopback
 * socket pair, mirroring the logic the client uses, without depending on a private method.
 */
describe('half-open connection detection', function () {
    it('flags a socket as dead after the peer sends FIN', function () {
        // Build a live loopback pair, then close the peer side. The client side still
        // looks open to feof() until a read is attempted — but STREAM_PEEK reveals the EOF.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$server) {
            throw new \RuntimeException("stream_socket_server failed: [{$errno}] {$errstr}");
        }
        $port = (int)substr(
            stream_socket_get_name($server, false),
            strrpos(stream_socket_get_name($server, false), ':') + 1
        );
        $clientSide = stream_socket_client("tcp://127.0.0.1:{$port}");
        $peerSide = stream_socket_accept($server, 1);
        fclose($server);

        // Non-blocking so the STREAM_PEEK probe never blocks waiting for data that will
        // never arrive — it must report EOF immediately once the peer's FIN is observed.
        stream_set_blocking($clientSide, false);

        // Healthy state: peer still open, no data. On a non-blocking socket recvwith PEEK
        // returns false (would-block) while feof stays false — that is the "alive" signal.
        $healthyPeek = @stream_socket_recvfrom($clientSide, 1, STREAM_PEEK);
        expect($healthyPeek)->toBeFalse()
            ->and(feof($clientSide))->toBeFalse();

        // Peer closes. PHP must now observe EOF on the next probe.
        fclose($peerSide);

        // Small wait so the FIN propagates to the client socket buffer.
        $observed = false;
        for ($i = 0; $i < 50; $i++) {
            $peek = @stream_socket_recvfrom($clientSide, 1, STREAM_PEEK);
            if ($peek === '' && feof($clientSide)) {
                $observed = true;

                break;
            }
            usleep(2_000);
        }

        expect($observed)->toBeTrue('Expected the STREAM_PEEK probe to observe the peer FIN');

        fclose($clientSide);
    });
});

/*
 * White-box unit tests for the tick/flush/eof machinery — no real network.
 *
 * They cover the regressions that motivated the refactor:
 *   • tick() reports work via activity, not via shrinking queue/active counts;
 *   • flushQueue() never drops queued entries while iterating;
 *   • a remote FIN on an already-resolved (idle) connection retires the socket
 *     silently instead of rejecting the promise a second time.
 */
describe('AskHttpSocketClient tick/flush/eof', function () {
    it('reports no work when idle', function () {
        $client = new AskHttpSocketClient(new HttpsSocketClientConfig());

        $client->tick(0);
        expect($client->isIdle())->toBeTrue();
    });

    it('implements WarmableClientContract', function () {
        expect(
            is_a(
                AskHttpSocketClient::class,
                \BAGArt\ASKClient\Contracts\Client\WarmableClientContract::class,
                true,
            )
        )->toBeTrue();
    });

    it('warmUp is a no-op when keepAlive is disabled', function () {
        // warmUp() is a no-op without keepAlive, and must report 0 warmed — and crucially
        // must NOT throw (this is the regression for the property-declaration bug).
        $client = new AskHttpSocketClient(new HttpsSocketClientConfig(keepAlive: false));

        expect($client->warmUp('localhost', 2))->toBe(0);
    });

    it('exposes a MetricsCollector instance', function () {
        $client = new AskHttpSocketClient(new HttpsSocketClientConfig(keepAlive: false));

        expect($client->metrics())->toBeInstanceOf(
            \BAGArt\ASKClient\SocketClient\Metrics\MetricsCollector::class,
        );
    });

    it('returns 0 from flushQueue when the queue is empty', function () {
        $client = new AskHttpSocketClient(new HttpsSocketClientConfig());

        $rc = new \ReflectionObject($client);
        $flushQueue = $rc->getMethod('flushQueue');

        // No queued requests → flushQueue performs no work and reports zero dequeued,
        // which is what tick() relies on to avoid false "work done" signals.
        expect($flushQueue->invoke($client))->toBe(0);
    });

    it('drains every queued entry in a single flushQueue pass', function () {
        // Regression guard for the mid-foreach unset() bug: when flushQueue() removed
        // entries while iterating, PHP's internal pointer could skip a neighbour, leaving
        // it orphaned in the queue. We assert every entry is visited in ONE pass by
        // checking the queue array is fully empty afterwards — no survivor means no skip.
        //
        // The error handler is silenced only for this test because stream_socket_client
        // to a non-resolvable host emits a PHP warning that Pest flags as risky; the
        // invariant under test (queue fully drained) does not depend on that output.
        $client = new AskHttpSocketClient(new HttpsSocketClientConfig());

        for ($i = 0; $i < 3; $i++) {
            $client->request(
                new ASKHttpRequest(
                    url: 'https://127.0.0.1:1/test-'.$i,
                    method: 'GET',
                )
            );
        }

        expect($client->queueSize())->toBe(3);

        $rc = new \ReflectionObject($client);
        $flushQueue = $rc->getMethod('flushQueue');

        set_error_handler(static fn () => true);

        try {
            $flushQueue->invoke($client);
        } finally {
            restore_error_handler();
        }

        $queue = $rc->getProperty('queue');

        // Every entry was visited this pass: whether it connected (moved to
        // activeConnections) or was rejected (promise rejected + removed), it must NOT
        // still sit in the queue — that would mean the mid-foreach unset() skipped it.
        expect(count($queue->getValue($client)))->toBe(0);
    });

    it('retires an eof socket silently when its promise is already gone', function () {
        // Simulate a kept-alive connection whose response was already delivered (so the
        // promise has been unset) and whose peer then sent FIN. readConnection() must NOT
        // call reject() again — it should just drop the socket from activeConnections.
        $client = new AskHttpSocketClient(new HttpsSocketClientConfig(keepAlive: true));

        // Build the connection directly with a null socket — this is exactly the state
        // left behind after releaseOrClose() nulled it, before the remote FIN is noticed.
        $conn = new PooledConnection(
            socket: null,
            key: 'peer.closed:443',
            host: 'peer.closed',
            port: 443,
            startedAt: microtime(true),
        );
        $conn->protocol = 'http/1.1';
        $conn->tlsReady = true;
        $conn->processor = new \BAGArt\ASKClient\SocketClient\Http1\Http1Processor();

        $rc = new \ReflectionObject($client);
        $active = $rc->getProperty('activeConnections');
        $active->setValue($client, [999 => $conn]);
        // Deliberately do NOT seed connectionPromises[999] — that is the "already resolved" case.

        $readConnection = $rc->getMethod('readConnection');
        $readConnection->invoke($client, 999);

        expect($client->queueSize())->toBe(0)
            ->and($client->isIdle())->toBeTrue();
    });

    it('refuses a new connection when the global maxConnectionsTotal cap is reached', function () {
        // FD_SETSIZE guard: even when the per-host cap would allow more, a second host's
        // request must stay queued once the global cap is saturated. We pre-seed one
        // active connection (the cap) and assert a second distinct host is NOT admitted.
        $client = new AskHttpSocketClient(
            new HttpsSocketClientConfig(
                keepAlive: true,
                maxConnectionsPerHost: 20, // generous — per-host cap must not be the gate
                maxConnectionsTotal: 1,    // global cap is the gate under test
            )
        );

        // Seed one active connection for host A so the global count is at the cap.
        // Use a live loopback socket (not 127.0.0.1:1) so construction never blocks.
        $connA = makePooledConnection(key: 'host-a:443', host: 'host-a');

        $rc = new \ReflectionClass($client);
        $active = $rc->getProperty('activeConnections');
        $active->setValue($client, [1 => $connA]);

        $counts = $rc->getProperty('activeCounts');
        $counts->setValue($client, ['host-a:443' => 1]);

        // Enqueue a request to a DIFFERENT host. acquireOrCreate must see the global cap
        // saturated and refuse — the returned connection is null (entry stays queued).
        $client->request(
            new ASKHttpRequest(url: 'https://host-b:443/x', method: 'GET')
        );

        $acquire = new \ReflectionMethod($client, 'acquireOrCreate');
        $queueProp = $rc->getProperty('queue');
        $pending = $queueProp->getValue($client)[0];

        $admitted = $acquire->invoke($client, $pending);

        expect($admitted)->toBeNull('Global cap must refuse admission when total open connections >= maxConnectionsTotal');
    });
});

/*
 * Integration tests below hit the real network. They are skipped unless ASK_LIVE_NET=1
 * is set, so CI runs offline; developers opt in locally to validate pooling end-to-end.
 */
$liveNet = getenv('ASK_LIVE_NET') === '1';

function liveUrl(): string
{
    return 'https://open.er-api.com/v6/latest/USD';
}

describe('AskHttpSocketClient (live network)', function () use ($liveNet) {
    beforeEach(function () use ($liveNet) {
        if (!$liveNet) {
            $this->markTestSkipped('Set ASK_LIVE_NET=1 to run live-network socket tests.');
        }
    });

    it('completes a request with pooling disabled (legacy parity)', function () {
        $client = new AskHttpSocketClient(new HttpsSocketClientConfig(keepAlive: false));

        $promise = $client->request(new ASKHttpRequest(url: liveUrl(), method: 'GET'));
        $client->drain();

        $response = $promise->await();

        expect($response->getStatusCode())->toBe(200)
            ->and($client->idlePoolSize())->toBe(0);
    });

    it('reuses a keep-alive connection across two sequential requests', function () {
        $client = new AskHttpSocketClient();

        $first = $client->request(new ASKHttpRequest(url: liveUrl(), method: 'GET'));
        $client->drain();
        $firstStatus = $first->await()->getStatusCode();

        // After the first response the connection should sit idle in the pool.
        expect($client->idlePoolSize())->toBe(1);

        $second = $client->request(new ASKHttpRequest(url: liveUrl(), method: 'GET'));
        $client->drain();
        $secondStatus = $second->await()->getStatusCode();

        expect($firstStatus)->toBe(200)
            ->and($secondStatus)->toBe(200)
            ->and($client->idlePoolSize())->toBe(1);
    });

    it('warms up connections into the pool', function () {
        $client = new AskHttpSocketClient(
            new HttpsSocketClientConfig(
                maxIdlePerHost: 4,
            )
        );

        $warmed = $client->warmUp('open.er-api.com', 3);

        expect($warmed)->toBe(3)
            ->and($client->idlePoolSize())->toBe(3);
    });
});
