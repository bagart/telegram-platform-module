<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\SocketClient\Metrics;

/**
 * Per-request lifecycle timestamps captured by the socket transport.
 *
 * All values are absolute microtime(true) seconds. The first non-null anchor
 * (dnsStart, or connectStart when the host is already an IP) marks the effective
 * request origin; durations() derive relative millisecond deltas from there.
 *
 * Intended as a diagnostics side-channel: recorded into MetricsCollector and
 * surfaced to the benchmark, never attached to the PSR-7 response.
 */
final class RequestTiming
{
    public ?float $dnsStart = null;
    public ?float $dnsEnd = null;
    public ?float $connectStart = null;
    public ?float $connectEnd = null;
    public ?float $tlsStart = null;
    public ?float $tlsEnd = null;
    public ?float $writeStart = null;
    public ?float $writeEnd = null;
    public ?float $firstByte = null;
    public ?float $headersEnd = null;
    public ?float $bodyEnd = null;

    /** URL of the request this timing belongs to; null until assigned to a connection. */
    public ?string $url = null;

    /**
     * Relative phase durations in milliseconds, keyed for rendering.
     *
     * @return array{dns: float, tcp: float, tls: float, write: float, ttfb: float, read: float, total: float}
     */
    public function durations(): array
    {
        $origin = $this->dnsStart ?? $this->connectStart ?? $this->tlsStart ?? 0.0;

        return [
            'dns' => $this->spanMs($this->dnsStart, $this->dnsEnd),
            'tcp' => $this->spanMs($this->connectStart, $this->connectEnd),
            'tls' => $this->spanMs($this->tlsStart, $this->tlsEnd),
            'write' => $this->spanMs($this->writeStart, $this->writeEnd),
            'ttfb' => $this->spanMs($this->writeEnd ?? $this->tlsEnd ?? $this->connectEnd, $this->firstByte),
            'read' => $this->spanMs($this->firstByte, $this->bodyEnd),
            'total' => $origin > 0.0 && $this->bodyEnd !== null
                ? round(($this->bodyEnd - $origin) * 1_000, 3)
                : 0.0,
        ];
    }

    private function spanMs(?float $start, ?float $end): float
    {
        if ($start === null || $end === null || $end < $start) {
            return 0.0;
        }

        return round(($end - $start) * 1_000, 3);
    }
}
