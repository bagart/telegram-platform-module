<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Diagnostics;

/**
 * Tiny in-memory engine observability sink (doc 07 phase 6): counters for
 * activation denials, lookup latency buckets, etc. Process-local by design —
 * no persistence, no locks; safe to call from request/queue/daemon contexts.
 * The diagnostics CLI includes the summary when non-empty.
 */
final class EngineMetrics
{
    /** @var array<string, int> */
    private array $counters = [];

    /** Increment a named counter (e.g. "activation_denied", "registry_lookup"). */
    public function increment(string $name, int $by = 1): void
    {
        if ($by < 1) {
            return;
        }

        $this->counters[$name] = ($this->counters[$name] ?? 0) + $by;
    }

    /** Record one observed operation duration under a named counter key. */
    public function observeDurationMs(string $name, float $ms): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0) + (int) round($ms);
    }

    /** @return array<string, int> counters that were touched, name => value */
    public function summary(): array
    {
        return $this->counters;
    }
}
