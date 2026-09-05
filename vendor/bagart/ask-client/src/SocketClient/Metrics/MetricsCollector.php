<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\SocketClient\Metrics;

final class MetricsCollector
{
    private const int RECENT_BUFFER_SIZE = 1000;

    private int $poolHit = 0;
    private int $poolMiss = 0;
    private int $poolWaitCount = 0;
    private float $poolWaitDuration = 0.0;
    private int $totalRequests = 0;
    private int $reusedRequests = 0;
    private int $connectionsCreated = 0;
    private int $connectionsClosed = 0;
    private int $connectionRequestSum = 0;
    private int $connectionRequestCount = 0;
    private float $connectionLifetimeSum = 0.0;
    private int $connectionLifetimeCount = 0;
    private int $tlsHandshakeCount = 0;
    private float $tlsHandshakeDurationSum = 0.0;
    private int $tlsHandshakeFailed = 0;

    private int $streamSelectCalls = 0;
    private int $streamSelectEmpty = 0;
    private int $streamSelectReadySum = 0;

    /** @var array<string, int> TLS version → count of negotiated handshakes. */
    private array $tlsVersions = [];

    private int $dnsLookupCount = 0;
    private float $dnsResolveTimeSum = 0.0;

    private int $httpParseCount = 0;
    private float $httpParseTimeSum = 0.0;

    private int $connectCount = 0;
    private float $connectTimeSum = 0.0;

    /** @var array<int, float> */
    private array $recentConnectionLifetimes = [];

    /** @var array<int, float> */
    private array $recentTlsDurations = [];

    /** @var array<int, RequestTiming> */
    private array $requestTimings = [];

    private int $lifetimeCursor = 0;
    private int $tlsCursor = 0;
    private int $timingCursor = 0;

    public function incrementPoolHit(): void
    {
        $this->poolHit++;
    }

    public function incrementPoolMiss(): void
    {
        $this->poolMiss++;
    }

    public function incrementPoolWaitCount(): void
    {
        $this->poolWaitCount++;
    }

    public function addPoolWaitDuration(float $seconds): void
    {
        $this->poolWaitDuration += $seconds;
    }

    public function incrementTotalRequests(bool $reused): void
    {
        $this->totalRequests++;
        if ($reused) {
            $this->reusedRequests++;
        }
    }

    public function incrementConnectionsCreated(): void
    {
        $this->connectionsCreated++;
    }

    public function recordStreamSelect(int $readyCount): void
    {
        $this->streamSelectCalls++;
        $this->streamSelectReadySum += $readyCount;
        if ($readyCount === 0) {
            $this->streamSelectEmpty++;
        }
    }

    public function recordDnsResolve(float $seconds): void
    {
        $this->dnsLookupCount++;
        $this->dnsResolveTimeSum += $seconds;
    }

    public function recordHttpParse(float $seconds): void
    {
        $this->httpParseCount++;
        $this->httpParseTimeSum += $seconds;
    }

    public function recordConnect(float $seconds): void
    {
        $this->connectCount++;
        $this->connectTimeSum += $seconds;
    }

    public function recordConnectionClosed(int $requestCount, float $lifetimeSeconds): void
    {
        $this->connectionsClosed++;
        $this->connectionRequestSum += $requestCount;
        $this->connectionRequestCount++;
        $this->connectionLifetimeSum += $lifetimeSeconds;
        $this->connectionLifetimeCount++;

        $this->recentConnectionLifetimes[$this->lifetimeCursor] = $lifetimeSeconds;
        $this->lifetimeCursor = ($this->lifetimeCursor + 1) % self::RECENT_BUFFER_SIZE;
    }

    public function recordTlsHandshake(float $durationSeconds, bool $success): void
    {
        $this->tlsHandshakeCount++;
        $this->tlsHandshakeDurationSum += $durationSeconds;
        if (!$success) {
            $this->tlsHandshakeFailed++;
        }

        $this->recentTlsDurations[$this->tlsCursor] = $durationSeconds;
        $this->tlsCursor = ($this->tlsCursor + 1) % self::RECENT_BUFFER_SIZE;
    }

    public function recordTlsVersion(string $version): void
    {
        $this->tlsVersions[$version] = ($this->tlsVersions[$version] ?? 0) + 1;
    }

    public function recordRequestTiming(RequestTiming $timing): void
    {
        $this->requestTimings[$this->timingCursor] = $timing;
        $this->timingCursor = ($this->timingCursor + 1) % self::RECENT_BUFFER_SIZE;
    }

    /**
     * Collected per-request timings (most recent N). Used by diagnostics to break down
     * latency by phase (DNS/TCP/TLS/write/TTFB/read) per URL.
     *
     * @return list<RequestTiming>
     */
    public function requestTimings(): array
    {
        return array_values($this->requestTimings);
    }

    /** @return array<string, float|int> */
    public function snapshot(): array
    {
        return [
                'pool.hit' => $this->poolHit,
                'pool.miss' => $this->poolMiss,
                'pool.hit_rate' => $this->computeRate($this->poolHit, $this->poolHit + $this->poolMiss),
                'pool.reuse_ratio' => $this->computeRate($this->reusedRequests, $this->totalRequests),
                'pool.wait_count' => $this->poolWaitCount,
                'pool.wait_duration_total' => $this->poolWaitDuration,
                'connections.created' => $this->connectionsCreated,
                'connections.closed' => $this->connectionsClosed,
                'connections.requests_avg' => $this->avg($this->connectionRequestSum, $this->connectionRequestCount),
                'connections.lifetime_avg' => $this->avg($this->connectionLifetimeSum, $this->connectionLifetimeCount),
                'connections.lifetime_recent_avg' => $this->avgRecent($this->recentConnectionLifetimes),
                'tls.handshake.count' => $this->tlsHandshakeCount,
                'tls.handshake.duration_avg_ms' => $this->avg(
                    $this->tlsHandshakeDurationSum * 1_000,
                    $this->tlsHandshakeCount
                ),
                'tls.handshake.duration_recent_avg_ms' => $this->avgRecentMs($this->recentTlsDurations),
                'tls.handshake.failed' => $this->tlsHandshakeFailed,
                'tls.versions' => $this->tlsVersions,
                'stream_select.calls' => $this->streamSelectCalls,
                'stream_select.empty_calls' => $this->streamSelectEmpty,
                'stream_select.avg_ready' => $this->avg($this->streamSelectReadySum, $this->streamSelectCalls),
                'dns.lookup_count' => $this->dnsLookupCount,
                'dns.resolve_time_avg_ms' => $this->avg($this->dnsResolveTimeSum * 1_000, $this->dnsLookupCount),
                'http.parse_count' => $this->httpParseCount,
                'http.parse_time_avg_ms' => $this->avg($this->httpParseTimeSum * 1_000, $this->httpParseCount),
                'tcp.connect_count' => $this->connectCount,
                'tcp.connect_time_avg_ms' => $this->avg($this->connectTimeSum * 1_000, $this->connectCount),
            ] + $this->phasePercentiles();
    }

    /**
     * p50/p95/p99 for each request phase, computed from the per-request timing ring buffer.
     *
     * @return array<string, float>
     */
    private function phasePercentiles(): array
    {
        if ($this->requestTimings === []) {
            return [];
        }

        $buckets = ['dns' => [], 'tcp' => [], 'tls' => [], 'write' => [], 'ttfb' => [], 'read' => [], 'total' => []];
        foreach ($this->requestTimings as $t) {
            $d = $t->durations();
            foreach ($buckets as $k => &$_) {
                if ($d[$k] > 0.0) {
                    $buckets[$k][] = $d[$k];
                }
            }
        }
        unset($_);

        $out = [];
        foreach ($buckets as $phase => $samples) {
            if ($samples === []) {
                continue;
            }
            sort($samples);
            $out["phase.{$phase}.p50_ms"] = $this->pct($samples, 50);
            $out["phase.{$phase}.p95_ms"] = $this->pct($samples, 95);
            $out["phase.{$phase}.p99_ms"] = $this->pct($samples, 99);
        }

        return $out;
    }

    /**
     * @param  list<float>  $sorted  Ascending-sorted samples.
     */
    private function pct(array $sorted, int $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        $idx = (int)ceil($p / 100 * $n) - 1;

        return round($sorted[max(0, min($idx, $n - 1))], 2);
    }

    public function reset(): void
    {
        $this->poolHit = 0;
        $this->poolMiss = 0;
        $this->poolWaitCount = 0;
        $this->poolWaitDuration = 0.0;
        $this->totalRequests = 0;
        $this->reusedRequests = 0;
        $this->connectionsCreated = 0;
        $this->connectionsClosed = 0;
        $this->connectionRequestSum = 0;
        $this->connectionRequestCount = 0;
        $this->connectionLifetimeSum = 0.0;
        $this->connectionLifetimeCount = 0;
        $this->tlsHandshakeCount = 0;
        $this->tlsHandshakeDurationSum = 0.0;
        $this->tlsHandshakeFailed = 0;
        $this->tlsVersions = [];
        $this->streamSelectCalls = 0;
        $this->streamSelectEmpty = 0;
        $this->streamSelectReadySum = 0;
        $this->dnsLookupCount = 0;
        $this->dnsResolveTimeSum = 0.0;
        $this->httpParseCount = 0;
        $this->httpParseTimeSum = 0.0;
        $this->connectCount = 0;
        $this->connectTimeSum = 0.0;
        $this->recentConnectionLifetimes = [];
        $this->recentTlsDurations = [];
        $this->requestTimings = [];
        $this->lifetimeCursor = 0;
        $this->tlsCursor = 0;
        $this->timingCursor = 0;
    }

    private function computeRate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : 0.0;
    }

    private function avg(float|int $sum, int $count): float
    {
        return $count > 0 ? round($sum / $count, 4) : 0.0;
    }

    /** @param  array<int, float>  $items */
    private function avgRecent(array $items): float
    {
        $count = count($items);

        return $count > 0 ? round(array_sum($items) / $count, 4) : 0.0;
    }

    /** @param  array<int, float>  $items */
    private function avgRecentMs(array $items): float
    {
        $count = count($items);

        return $count > 0 ? round(array_sum($items) / $count * 1_000, 4) : 0.0;
    }
}
