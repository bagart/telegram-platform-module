<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns\Adapters;

use BAGArt\ASKClient\Contracts\Dns\AskDnsAdapterContract;
use BAGArt\ASKClient\Dns\AskDnsConfig;
use BAGArt\ASKClient\Dns\AsyncDnsResolver;

final readonly class AsyncDnsAdapter implements AskDnsAdapterContract
{
    public const string TYPE = 'ask-dns';
    public const bool TLS_SUPPORTED = true;

    private AsyncDnsResolver $resolver;

    private float $timeout;

    public function __construct(AskDnsConfig $config, ?AsyncDnsResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new AsyncDnsResolver(
            ttl: $config->ttl(),
            failureTtl: $config->failureTtl(),
            dnsServers: $config->dnsServers(),
            useTls: $config->useTls(),
        );
        $this->timeout = $config->timeout();
    }

    public static function fromConfig(AskDnsConfig $config): static
    {
        return new self($config);
    }

    public function resolve(string $host): ?string
    {
        return $this->resolver->resolve($host);
    }

    public function tick(): void
    {
        $this->resolver->tick();
    }

    public function getReadSockets(): array
    {
        return $this->resolver->getReadSockets();
    }

    public function isDnsSocket(int $rid): bool
    {
        return $this->resolver->isDnsSocket($rid);
    }

    public function processReadable(mixed $socket): bool
    {
        return $this->resolver->processReadable($socket);
    }

    public function hasFresh(string $host): bool
    {
        return $this->resolver->hasFresh($host);
    }

    public function flushFresh(): array
    {
        return $this->resolver->flushFresh();
    }

    public function waitUntilResolved(string $host, float $timeout): ?string
    {
        return $this->resolver->waitUntilResolved($host, $timeout);
    }

    public function resolveBlocking(string $host): ?string
    {
        return $this->resolver->resolveBlocking($host);
    }

    public function clearCache(): void
    {
        $this->resolver->clearCache();
    }

    public function warmUp(string $host): ?string
    {
        return $this->resolver->waitUntilResolved($host, $this->timeout);
    }
}
