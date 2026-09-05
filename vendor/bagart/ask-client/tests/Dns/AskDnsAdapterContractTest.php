<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Tests\Dns;

use BAGArt\ASKClient\Contracts\Dns\AskDnsAdapterContract;
use BAGArt\ASKClient\Dns\Adapters\AsyncDnsAdapter;
use BAGArt\ASKClient\Dns\Adapters\NativeDnsAdapter;
use BAGArt\ASKClient\Dns\AskDnsConfig;
use BAGArt\ASKClient\SocketClient\AskHttpSocketClient;
use BAGArt\ASKClient\SocketClient\HttpsSocketClientConfig;
use phpDocumentor\Reflection\PseudoTypes\ClassString;

describe('AskDnsAdapterContract', function () {
    it('is implemented by every built-in adapter', function () {
        $config = new AskDnsConfig();

        expect(AsyncDnsAdapter::fromConfig($config))->toBeInstanceOf(AskDnsAdapterContract::class)
            ->and(NativeDnsAdapter::fromConfig($config))->toBeInstanceOf(AskDnsAdapterContract::class);
    });

    it('declares the unified method surface', function () {
        $expected = [
            'fromConfig',
            'resolve',
            'tick',
            'getReadSockets',
            'isDnsSocket',
            'processReadable',
            'hasFresh',
            'flushFresh',
            'waitUntilResolved',
            'resolveBlocking',
            'clearCache',
            'warmUp',
        ];

        expect(get_class_methods(AskDnsAdapterContract::class))
            ->toEqualCanonicalizing($expected);
    });

    it('has a uniform fromConfig() factory on each adapter', function () {
        $config = new AskDnsConfig();

        foreach ([AsyncDnsAdapter::class, NativeDnsAdapter::class] as $class) {
            /** @var class-string<AsyncDnsAdapter|NativeDnsAdapter> $class */
            expect($class::fromConfig($config))->toBeInstanceOf($class);
        }
    });
});

describe('adapter capability methods', function () {
    it('NativeDnsAdapter reports no DNS sockets and reflects fresh state', function () {
        $adapter = NativeDnsAdapter::fromConfig(new AskDnsConfig());

        expect($adapter->isDnsSocket(9999))->toBeFalse()
            ->and($adapter->getReadSockets())->toBe([])
            ->and($adapter->hasFresh('nonexistent.test'))->toBeFalse()
            ->and($adapter->flushFresh())->toBe([]);
    });

    it('AsyncDnsAdapter delegates isDnsSocket to the wrapped resolver', function () {
        $adapter = AsyncDnsAdapter::fromConfig(new AskDnsConfig());

        // No query dispatched yet — no live DNS sockets.
        expect($adapter->isDnsSocket(1))->toBeFalse()
            ->and($adapter->getReadSockets())->toBe([]);
    });
});

describe('AskHttpSocketClient DI', function () {
    it('uses the injected adapter to resolve hosts', function () {
        $fake = new FakeAdapter();
        $client = new AskHttpSocketClient(
            new HttpsSocketClientConfig(),
            null,
            $fake,
        );

        $client->request(new \BAGArt\ASKClient\Dto\ASKHttpRequest('https://injected.test/', 'GET'));
        $client->tick(0);

        expect($fake->resolved)->toContain('injected.test');
    });

    it('constructs a default adapter when none is provided', function () {
        $client = new AskHttpSocketClient(new HttpsSocketClientConfig());

        expect($client)->toBeInstanceOf(AskHttpSocketClient::class);
    });

    it('no-injection fallback resolves the registry DEFAULT_TYPE adapter', function () {
        $client = new AskHttpSocketClient(new HttpsSocketClientConfig());

        $resolver = (new \ReflectionProperty($client, 'dnsResolver'))->getValue($client);

        // The fallback goes through AskDnsRegistry, which maps null → DEFAULT_TYPE
        // ('ask-dns' → AsyncDnsAdapter). No concrete adapter is hardcoded in the client.
        expect($resolver)->toBeInstanceOf(AskDnsAdapterContract::class)
            ->and($resolver)->toBeInstanceOf(AsyncDnsAdapter::class);
    });
});

/**
 * In-memory fake adapter: records resolve() calls and answers none, so the
 * contract can be exercised without touching the network.
 */
final class FakeAdapter implements AskDnsAdapterContract
{
    public const string TYPE = 'fake';
    public const bool TLS_SUPPORTED = false;

    /** @var list<string> */
    public array $resolved = [];

    public static function fromConfig(AskDnsConfig $config): static
    {
        return new self();
    }

    public function resolve(string $host): ?string
    {
        $this->resolved[] = $host;

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
        $this->resolved[] = $host;

        return null;
    }

    public function resolveBlocking(string $host): ?string
    {
        $this->resolved[] = $host;

        return null;
    }

    public function clearCache(): void
    {
    }

    public function warmUp(string $host): ?string
    {
        return $this->resolve($host);
    }
}
