<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Tests\Dns;

use BAGArt\ASKClient\Dns\AskDnsConfigFactory;

describe('AskDnsConfigFactory::fromArray', function () {
    it('builds a config from a valid array', function () {
        $config = AskDnsConfigFactory::fromArray([
            'dns_servers' => ['1.1.1.1', '9.9.9.9'],
            'timeout' => 2.5,
            'ttl' => 120.0,
            'failure_ttl' => 5.0,
            'use_tls' => true,
            'force_ipv4' => false,
            'warm_up_hosts' => ['api.telegram.org'],
        ]);

        expect($config->dnsServers())->toBe(['1.1.1.1', '9.9.9.9'])
            ->and($config->timeout())->toBe(2.5)
            ->and($config->ttl())->toBe(120.0)
            ->and($config->failureTtl())->toBe(5.0)
            ->and($config->useTls())->toBeTrue()
            ->and($config->forceIPv4())->toBeFalse()
            ->and($config->warmUpHosts())->toBe(['api.telegram.org']);
    });

    it('applies defaults for an empty array', function () {
        $config = AskDnsConfigFactory::fromArray([]);

        expect($config->dnsServers())->toBe(['8.8.8.8', '1.1.1.1'])
            ->and($config->timeout())->toBe(3.0)
            ->and($config->ttl())->toBe(300.0)
            ->and($config->failureTtl())->toBe(10.0)
            ->and($config->useTls())->toBeFalse()
            ->and($config->forceIPv4())->toBeTrue()
            ->and($config->warmUpHosts())->toBe([]);
    });
});

describe('AskDnsConfigFactory::fromLaravelConfig', function () {
    it('handles CSV strings for servers and warm-up hosts', function () {
        $config = AskDnsConfigFactory::fromLaravelConfig([
            'servers' => '1.1.1.1, 9.9.9.9',
            'warm_up_hosts' => 'api.telegram.org, google.com',
        ]);

        expect($config->dnsServers())->toBe(['1.1.1.1', '9.9.9.9'])
            ->and($config->warmUpHosts())->toBe(['api.telegram.org', 'google.com']);
    });

    it('applies defaults when values are null', function () {
        $config = AskDnsConfigFactory::fromLaravelConfig([
            'servers' => null,
            'warm_up_hosts' => null,
        ]);

        expect($config->dnsServers())->toBe(['8.8.8.8', '1.1.1.1'])
            ->and($config->ttl())->toBe(300.0)
            ->and($config->useTls())->toBeFalse()
            ->and($config->warmUpHosts())->toBe([]);
    });

    it('keeps explicitly set deep settings', function () {
        $config = AskDnsConfigFactory::fromLaravelConfig([
            'timeout' => 5.0,
            'ttl' => 60.0,
            'failure_ttl' => 2.0,
            'use_tls' => true,
            'force_ipv4' => false,
        ]);

        expect($config->timeout())->toBe(5.0)
            ->and($config->ttl())->toBe(60.0)
            ->and($config->failureTtl())->toBe(2.0)
            ->and($config->useTls())->toBeTrue()
            ->and($config->forceIPv4())->toBeFalse();
    });
});

describe('AskDnsConfigFactory::fromOptions', function () {
    it('builds a config from CLI-style keys', function () {
        $config = AskDnsConfigFactory::fromOptions([
            'dns-servers' => '8.8.4.4,8.8.8.8',
            'dns-timeout' => '1.5',
            'dns-ttl' => '45',
            'dns-failure-ttl' => '3',
            'dns-use-tls' => '1',
            'dns-force-ipv4' => '0',
            'dns-warmup' => ['api.telegram.org'],
        ]);

        expect($config->dnsServers())->toBe(['8.8.4.4', '8.8.8.8'])
            ->and($config->timeout())->toBe(1.5)
            ->and($config->ttl())->toBe(45.0)
            ->and($config->failureTtl())->toBe(3.0)
            ->and($config->useTls())->toBeTrue()
            ->and($config->forceIPv4())->toBeFalse()
            ->and($config->warmUpHosts())->toBe(['api.telegram.org']);
    });

    it('applies defaults for an empty options array', function () {
        $config = AskDnsConfigFactory::fromOptions([]);

        expect($config->dnsServers())->toBe(['8.8.8.8', '1.1.1.1'])
            ->and($config->timeout())->toBe(3.0)
            ->and($config->ttl())->toBe(300.0)
            ->and($config->useTls())->toBeFalse();
    });
});

describe('AskDnsConfigFactory::fromEnv', function () {
    it('reads ASK_DNS_* environment variables', function () {
        putenv('ASK_DNS_SERVERS=9.9.9.9,1.1.1.1');
        putenv('ASK_DNS_TTL=10');
        putenv('ASK_DNS_USE_TLS=1');

        try {
            $config = AskDnsConfigFactory::fromEnv();

            expect($config->dnsServers())->toBe(['9.9.9.9', '1.1.1.1'])
                ->and($config->ttl())->toBe(10.0)
                ->and($config->useTls())->toBeTrue();
        } finally {
            putenv('ASK_DNS_SERVERS');
            putenv('ASK_DNS_TTL');
            putenv('ASK_DNS_USE_TLS');
        }
    });
});
