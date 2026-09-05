<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

final class DnsCache
{
    private const int MAX_ENTRIES = 10000;
    private const float DEFAULT_TTL = 300.0;
    private const float DEFAULT_NEGATIVE_TTL = 10.0;

    /** @var array<string, array{ip: string, expiresAt: float}> */
    private array $cache = [];

    /** @var array<string, array{expiresAt: float}> */
    private array $negativeCache = [];

    /** @var list<string> FIFO insertion order — O(1) push, O(1) shift for eviction. */
    private array $insertionOrder = [];

    /** @var list<string> FIFO insertion order for negative cache entries. */
    private array $negativeInsertionOrder = [];

    private int $maxEntries;
    private float $ttl;
    private float $negativeTtl;

    public function __construct(
        ?int $maxEntries = null,
        ?float $ttl = null,
        ?float $negativeTtl = null,
    ) {
        $this->maxEntries = $maxEntries ?? self::MAX_ENTRIES;
        $this->ttl = $ttl ?? self::DEFAULT_TTL;
        $this->negativeTtl = $negativeTtl ?? self::DEFAULT_NEGATIVE_TTL;
    }

    public function get(string $host): ?string
    {
        $now = microtime(true);

        $entry = $this->cache[$host] ?? null;
        if ($entry !== null) {
            if ($entry['expiresAt'] > $now) {
                return $entry['ip'];
            }
            unset($this->cache[$host]);
        }

        $failed = $this->negativeCache[$host] ?? null;
        if ($failed !== null) {
            if ($failed['expiresAt'] > $now) {
                return null;
            }
            unset($this->negativeCache[$host]);
        }

        return null;
    }

    public function put(string $host, string $ip, ?int $ttl = null): void
    {
        $effectiveTtl = $ttl !== null ? min($ttl, (int)$this->ttl, 3600) : $this->ttl;
        $this->cache[$host] = [
            'ip' => $ip,
            'expiresAt' => microtime(true) + $effectiveTtl,
        ];
        $this->insertionOrder[] = $host;
        $this->evict();
    }

    public function putNegative(string $host): void
    {
        $this->negativeCache[$host] = [
            'expiresAt' => microtime(true) + $this->negativeTtl,
        ];
        $this->negativeInsertionOrder[] = $host;
        $this->evict();
    }

    public function has(string $host): bool
    {
        return isset($this->cache[$host]) || isset($this->negativeCache[$host]);
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->negativeCache = [];
        $this->insertionOrder = [];
        $this->negativeInsertionOrder = [];
    }

    public function count(): int
    {
        return count($this->cache) + count($this->negativeCache);
    }

    private function evict(): void
    {
        $total = count($this->cache) + count($this->negativeCache);
        if ($total <= $this->maxEntries) {
            return;
        }

        $toRemove = $total - $this->maxEntries;

        // Evict oldest positive cache entries first (FIFO)
        while ($toRemove > 0 && $this->insertionOrder !== []) {
            $oldest = array_shift($this->insertionOrder);
            if (isset($this->cache[$oldest])) {
                unset($this->cache[$oldest]);
                $toRemove--;
            }
        }

        // Then evict oldest negative cache entries
        while ($toRemove > 0 && $this->negativeInsertionOrder !== []) {
            $oldest = array_shift($this->negativeInsertionOrder);
            if (isset($this->negativeCache[$oldest])) {
                unset($this->negativeCache[$oldest]);
                $toRemove--;
            }
        }
    }
}
