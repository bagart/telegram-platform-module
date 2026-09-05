<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns\Adapters;

use BAGArt\ASKClient\Contracts\Dns\AskDnsAdapterContract;
use BAGArt\ASKClient\Dns\AskDnsConfig;

final class NativeDnsAdapter implements AskDnsAdapterContract
{
    public const string TYPE = 'native';

    /** @var array<string, string> */
    private array $fresh = [];

    public static function fromConfig(AskDnsConfig $config): static
    {
        return new self();
    }

    public function resolve(string $host): ?string
    {
        $ip = @gethostbyname($host);
        if ($ip !== '' && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->fresh[$host] = $ip;
            return $ip;
        }

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
        return isset($this->fresh[$host]);
    }

    public function flushFresh(): array
    {
        $result = $this->fresh;
        $this->fresh = [];
        return $result;
    }

    public function waitUntilResolved(string $host, float $timeout): ?string
    {
        return $this->resolve($host);
    }

    public function resolveBlocking(string $host): ?string
    {
        return $this->resolve($host);
    }

    public function clearCache(): void
    {
    }

    public function warmUp(string $host): ?string
    {
        return $this->resolve($host);
    }
}
