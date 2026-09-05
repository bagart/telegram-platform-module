<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Tests\Dns;

use BAGArt\ASKClient\Contracts\Dns\AskDnsAdapterContract;
use BAGArt\ASKClient\Dns\Adapters\AsyncDnsAdapter;
use BAGArt\ASKClient\Dns\Adapters\NativeDnsAdapter;
use BAGArt\ASKClient\Dns\AskDnsConfig;
use BAGArt\ASKClient\Dns\AskDnsRegistry;
use BAGArt\ASKClient\Exceptions\AskConfigException;

describe('AskDnsRegistry', function () {
    it('registers the four built-in adapters lazily', function () {
        $registry = new AskDnsRegistry();

        expect($registry->list())
            ->toContain(AsyncDnsAdapter::TYPE)
            ->toContain(NativeDnsAdapter::TYPE)
            ->toContain('react-dns')
            ->toContain('amphp-dns');
    });

    it('lists the built-in types and reports TLS support', function () {
        $registry = AskDnsRegistry::build();

        expect($registry->types())
            ->toContain(AsyncDnsAdapter::TYPE)
            ->toContain(NativeDnsAdapter::TYPE)
            ->toContain('react-dns')
            ->toContain('amphp-dns')
            ->and($registry->supportsTls(AsyncDnsAdapter::TYPE))->toBeTrue()
            ->and($registry->supportsTls(NativeDnsAdapter::TYPE))->toBeFalse();
    });

    it('make(null) resolves the DEFAULT_TYPE adapter', function () {
        $config = new AskDnsConfig();
        $adapter = AskDnsRegistry::build()->make(null, $config);

        expect($adapter)->toBeInstanceOf(AsyncDnsAdapter::class)
            ->and($adapter)->toBeInstanceOf(AskDnsAdapterContract::class);
    });

    it('make() with an empty string resolves the DEFAULT_TYPE adapter', function () {
        $adapter = AskDnsRegistry::build()->make('', new AskDnsConfig());

        expect($adapter)->toBeInstanceOf(AsyncDnsAdapter::class);
    });

    it('builds a known adapter via the uniform fromConfig() factory', function () {
        $config = new AskDnsConfig();
        $adapter = AskDnsRegistry::build()->make(NativeDnsAdapter::TYPE, $config);

        expect($adapter)->toBeInstanceOf(NativeDnsAdapter::class)
            ->and($adapter)->toBeInstanceOf(AskDnsAdapterContract::class);
    });

    it('make() with a FQCN auto-registers and returns an instance', function () {
        $config = new AskDnsConfig();
        $registry = AskDnsRegistry::build();

        $adapter = $registry->make(CustomRegistryAdapter::class, $config);

        expect($adapter)->toBeInstanceOf(CustomRegistryAdapter::class)
            ->and($registry->has(CustomRegistryAdapter::class))->toBeTrue();
    });

    it('throws AskConfigException for an unknown type', function () {
        $registry = AskDnsRegistry::build();

        expect(fn () => $registry->make('does-not-exist', new AskDnsConfig()))
            ->toThrow(AskConfigException::class);
    });

    it('register() with type + class builds the custom adapter', function () {
        $registry = AskDnsRegistry::build()->register('custom', CustomRegistryAdapter::class);

        expect($registry->has('custom'))->toBeTrue()
            ->and($registry->make('custom', new AskDnsConfig()))->toBeInstanceOf(CustomRegistryAdapter::class);
    });

    it('register() with class only uses the TYPE constant', function () {
        $registry = AskDnsRegistry::build()->register(CustomRegistryAdapter::class);

        expect($registry->has(CustomRegistryAdapter::TYPE))->toBeTrue()
            ->and($registry->make(CustomRegistryAdapter::TYPE, new AskDnsConfig()))
            ->toBeInstanceOf(CustomRegistryAdapter::class);
    });

    it('register() validates immediately and rejects unknown classes', function () {
        $registry = AskDnsRegistry::build();

        expect(fn () => $registry->register('valid', CustomRegistryAdapter::class))
            ->not->toThrow(AskConfigException::class)
            ->and(fn () => $registry->register('bad', 'does-not-exist'))
            ->toThrow(AskConfigException::class);
    });

    it('register() rejects classes that do not implement the contract', function () {
        $registry = AskDnsRegistry::build();

        expect(fn () => $registry->register(AskDnsConfig::class, 'config'))
            ->toThrow(AskConfigException::class);
    });

    it('resolve(null) returns the DEFAULT_TYPE class', function () {
        expect(AskDnsRegistry::build()->resolve(null))->toBe(AsyncDnsAdapter::class);
    });

    it('caches an auto-registered FQCN for subsequent O(1) lookups', function () {
        $registry = AskDnsRegistry::build();

        $registry->resolve(CustomRegistryAdapter::class);

        expect($registry->has(CustomRegistryAdapter::class))->toBeTrue();
    });

    it('list() returns every built-in type', function () {
        $list = AskDnsRegistry::build()->list();

        expect($list)
            ->toContain('ask-dns')
            ->toContain('react-dns')
            ->toContain('amphp-dns')
            ->toContain('native');
    });
});

/**
 * Standalone adapter used only to prove the registry can build any registered
 * class through fromConfig() — not one of the four built-ins.
 */
final class CustomRegistryAdapter implements AskDnsAdapterContract
{
    public const string TYPE = 'custom';
    public const bool TLS_SUPPORTED = false;

    public static function fromConfig(AskDnsConfig $config): static
    {
        return new self();
    }

    public function resolve(string $host): ?string
    {
        return null;
    }

    public function tick(): void
    {
    }

    public function getReadSockets(): array
    {
        return [];
    }

    public function isDnsSocket(int $rid): bool
    {
        return false;
    }

    public function processReadable(mixed $socket): bool
    {
        return false;
    }

    public function hasFresh(string $host): bool
    {
        return false;
    }

    public function flushFresh(): array
    {
        return [];
    }

    public function waitUntilResolved(string $host, float $timeout): ?string
    {
        return null;
    }

    public function resolveBlocking(string $host): ?string
    {
        return null;
    }

    public function clearCache(): void
    {
    }

    public function warmUp(string $host): ?string
    {
        return null;
    }
}
