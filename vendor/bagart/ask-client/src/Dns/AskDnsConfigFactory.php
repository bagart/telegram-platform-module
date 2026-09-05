<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

/**
 * Boundary between external configuration representations and the pure
 * {@see AskDnsConfig} DTO.
 *
 * All parsing (CSV strings, bool coercion, defaults) lives here — never in the
 * DTO. One normalized AskDnsConfig flows into the registry regardless of origin
 * (Laravel config array, CLI options, env, YAML).
 */
final readonly class AskDnsConfigFactory
{
    /**
     * From a pre-parsed associative array (Laravel config, YAML, etc.).
     * Keys: dns_servers (array), timeout, ttl, failure_ttl, use_tls, force_ipv4, warm_up_hosts (array).
     */
    public static function fromArray(array $data): AskDnsConfig
    {
        return new AskDnsConfig(
            dnsServers: (array)($data['dns_servers'] ?? ['8.8.8.8', '1.1.1.1']),
            timeout: (float)($data['timeout'] ?? 3.0),
            ttl: (float)($data['ttl'] ?? 300.0),
            failureTtl: (float)($data['failure_ttl'] ?? 10.0),
            useTls: (bool)($data['use_tls'] ?? false),
            forceIPv4: (bool)($data['force_ipv4'] ?? true),
            warmUpHosts: (array)($data['warm_up_hosts'] ?? []),
        );
    }

    /**
     * From a Laravel tg-outbound-daemon.daemon.dns config array.
     * Handles CSV string → array conversion for servers/warm_up_hosts.
     */
    public static function fromLaravelConfig(array $dns): AskDnsConfig
    {
        return self::fromArray([
            'dns_servers' => self::csvToArray($dns['servers'] ?? null) ?: ['8.8.8.8', '1.1.1.1'],
            'timeout' => $dns['timeout'] ?? 3.0,
            'ttl' => $dns['ttl'] ?? 300.0,
            'failure_ttl' => $dns['failure_ttl'] ?? 10.0,
            'use_tls' => $dns['use_tls'] ?? false,
            'force_ipv4' => $dns['force_ipv4'] ?? true,
            'warm_up_hosts' => self::csvToArray($dns['warm_up_hosts'] ?? null),
        ]);
    }

    /**
     * From a CLI options array (--dns-servers, --dns-timeout, etc.) with an
     * ASK_DNS_* env fallback. Standalone mode only — see the single config
     * boundary note.
     */
    public static function fromOptions(array $options): AskDnsConfig
    {
        $servers = $options['dns-servers'] ?? getenv('ASK_DNS_SERVERS');
        $useTls = $options['dns-use-tls'] ?? getenv('ASK_DNS_USE_TLS');

        return new AskDnsConfig(
            dnsServers: self::normalizeServers($servers),
            timeout: (float)($options['dns-timeout'] ?? getenv('ASK_DNS_TIMEOUT') ?: 3.0),
            ttl: (float)($options['dns-ttl'] ?? getenv('ASK_DNS_TTL') ?: 300.0),
            failureTtl: (float)($options['dns-failure-ttl'] ?? getenv('ASK_DNS_FAILURE_TTL') ?: 10.0),
            useTls: self::parseBool($useTls, false),
            forceIPv4: self::parseBool($options['dns-force-ipv4'] ?? null, true),
            warmUpHosts: (array)($options['dns-warmup'] ?? []),
        );
    }

    /**
     * From ASK_DNS_* environment variables. Standalone fallback only — see the
     * single config boundary note: fromEnv() must not silently become a second
     * live source next to Laravel's normalized config.
     */
    public static function fromEnv(): AskDnsConfig
    {
        return self::fromOptions([]);
    }

    private static function csvToArray(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }

    private static function normalizeServers(mixed $servers): array
    {
        if (is_array($servers)) {
            return $servers;
        }
        if (is_string($servers) && trim($servers) !== '') {
            return array_map('trim', explode(',', $servers));
        }
        $env = getenv('ASK_DNS_SERVERS');
        if ($env !== false && $env !== '') {
            return array_map('trim', explode(',', $env));
        }

        return ['8.8.8.8', '1.1.1.1'];
    }

    private static function parseBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            $env = getenv($default ? 'ASK_DNS_FORCE_IPV4' : 'ASK_DNS_USE_TLS');

            return $env !== false ? filter_var($env, FILTER_VALIDATE_BOOLEAN) : $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
