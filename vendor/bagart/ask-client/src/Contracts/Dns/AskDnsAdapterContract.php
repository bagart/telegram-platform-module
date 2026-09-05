<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Dns;

use BAGArt\ASKClient\Dns\AskDnsConfig;

/**
 * Unified DNS adapter contract.
 *
 * One interface serves both the pluggable registry (selected via a TYPE string
 * from {@see AskDnsRegistry}) and the socket transport {@see AskDnsAdapterContract}
 * consumers that integrate DNS query sockets into their own event loop.
 */
interface AskDnsAdapterContract
{
    public const string TYPE = '';
    public const bool TLS_SUPPORTED = false;

    /**
     * Uniform factory used by {@see \BAGArt\ASKClient\Dns\AskDnsRegistry::make()}
     * so any registered adapter is constructable without a hardcoded match arm.
     */
    public static function fromConfig(AskDnsConfig $config): static;

    /**
     * Returns the cached IP if already resolved, dispatches a query otherwise.
     * Returns null while the query is in-flight or on failure.
     */
    public function resolve(string $host): ?string;

    /**
     * Advances pending queries: fires retries past their deadline and expires
     * queries that exhausted their retries into the negative cache.
     */
    public function tick(): void;

    /**
     * Sockets the caller must poll for readability and feed back through
     * {@see processReadable()}.
     *
     * @return array<int, resource>
     */
    public function getReadSockets(): array;

    /**
     * True when $rid belongs to a DNS query socket owned by this adapter, so the
     * caller can route readable events to {@see processReadable()} instead of
     * treating them as connection sockets.
     */
    public function isDnsSocket(int $rid): bool;

    /**
     * Reads a DNS response off $socket, caches the result, and exposes it via
     * the fresh slot. Returns false if the socket is unknown to this adapter.
     */
    public function processReadable(mixed $socket): bool;

    /**
     * True when $host has a freshly resolved IP available for immediate
     * consumption (not yet pulled by resolve()).
     */
    public function hasFresh(string $host): bool;

    /**
     * Drains and returns the freshly resolved hosts, clearing the fresh slot.
     *
     * @return array<string, ?string> Map of host => resolved IP (or null on failure).
     */
    public function flushFresh(): array;

    /**
     * Blocks until $host resolves or $timeout elapses, driving the adapter's
     * own tick loop internally. Intended for warmup/startup only.
     */
    public function waitUntilResolved(string $host, float $timeout): ?string;

    /**
     * Synchronous blocking resolution against the configured servers with a
     * gethostbyname() fallback. Intended for warmup/startup only.
     */
    public function resolveBlocking(string $host): ?string;

    /**
     * Clears this adapter's DNS cache. Instance method so adapters wrapping an
     * internal resolver (e.g. {@see \BAGArt\ASKClient\Dns\AsyncDnsResolver})
     * can delegate to the wrapped instance.
     */
    public function clearCache(): void;

    /**
     * Resolves $host synchronously for warmup, returning the IP or null.
     */
    public function warmUp(string $host): ?string;
}
