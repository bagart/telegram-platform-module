<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

final readonly class AskDnsConfig
{
    public function __construct(
        private array $dnsServers = ['8.8.8.8', '1.1.1.1'],
        private float $timeout = 3.0,
        private float $ttl = 300.0,
        private float $failureTtl = 10.0,
        private bool $useTls = false,
        private bool $forceIPv4 = true,
        private array $warmUpHosts = [],
    ) {
        if ($timeout <= 0) {
            throw new \InvalidArgumentException("DNS timeout must be > 0, got {$timeout}.");
        }
        if ($ttl < 0) {
            throw new \InvalidArgumentException("DNS ttl must be >= 0, got {$ttl}.");
        }
        if ($failureTtl < 0) {
            throw new \InvalidArgumentException("DNS failure_ttl must be >= 0, got {$failureTtl}.");
        }
    }

    public function toArray(): array
    {
        return [
            'dns_servers' => $this->dnsServers,
            'timeout' => $this->timeout,
            'ttl' => $this->ttl,
            'failure_ttl' => $this->failureTtl,
            'use_tls' => $this->useTls,
            'force_ipv4' => $this->forceIPv4,
            'warm_up_hosts' => $this->warmUpHosts,
        ];
    }

    public function dnsServers(): array
    {
        return $this->dnsServers;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function ttl(): float
    {
        return $this->ttl;
    }

    public function failureTtl(): float
    {
        return $this->failureTtl;
    }

    /**
     * Immutable copy with an overridden TTL — used by the socket client to align
     * the resolver's cache TTL with {@see HttpsSocketClientConfig::$dnsCacheTtl}
     * without touching the rest of the config.
     */
    public function withTtl(float $ttl): self
    {
        return new self(
            dnsServers: $this->dnsServers,
            timeout: $this->timeout,
            ttl: $ttl,
            failureTtl: $this->failureTtl,
            useTls: $this->useTls,
            forceIPv4: $this->forceIPv4,
            warmUpHosts: $this->warmUpHosts,
        );
    }

    public function useTls(): bool
    {
        return $this->useTls;
    }

    public function forceIPv4(): bool
    {
        return $this->forceIPv4;
    }

    public function warmUpHosts(): array
    {
        return $this->warmUpHosts;
    }

    /**
     * Formats the configured servers for libcurl's CURLOPT_DNS_SERVERS option
     * (semicolon-separated "host:53" list), or returns null when the config
     * relies on the system resolver.
     */
    public function toCurlDnsServers(): ?string
    {
        if ($this->dnsServers === []) {
            return null;
        }

        $servers = [];
        foreach ($this->dnsServers as $server) {
            $servers[] = str_contains($server, ':') ? $server : $server.':53';
        }

        return implode(';', $servers);
    }

    /**
     * Whether the running libcurl was built with the c-ares async resolver,
     * which CURLOPT_DNS_SERVERS requires. Without c-ares the option is ignored
     * and libcurl falls back to the OS resolver regardless of the server list.
     */
    public static function libcurlSupportsCares(): bool
    {
        $features = curl_version()['features'] ?? 0;

        return ($features & CURL_VERSION_ASYNCHDNS) !== 0;
    }
}
