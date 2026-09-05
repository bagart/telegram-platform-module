<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Tests\Dns;

use BAGArt\ASKClient\Dns\AskDnsConfig;

describe('AskDnsConfig', function () {
    it('validates that timeout is positive', function () {
        expect(fn () => new AskDnsConfig(timeout: 0.0))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new AskDnsConfig(timeout: -1.0))->toThrow(\InvalidArgumentException::class);
    });

    it('validates that ttl is non-negative', function () {
        expect(fn () => new AskDnsConfig(ttl: -0.01))->toThrow(\InvalidArgumentException::class);
    });

    it('validates that failure_ttl is non-negative', function () {
        expect(fn () => new AskDnsConfig(failureTtl: -1.0))->toThrow(\InvalidArgumentException::class);
    });

    it('constructs with all parameters', function () {
        $config = new AskDnsConfig(
            dnsServers: ['1.1.1.1'],
            timeout: 2.0,
            ttl: 100.0,
            failureTtl: 5.0,
            useTls: true,
            forceIPv4: false,
            warmUpHosts: ['api.telegram.org'],
        );

        expect($config->dnsServers())->toBe(['1.1.1.1'])
            ->and($config->timeout())->toBe(2.0)
            ->and($config->ttl())->toBe(100.0)
            ->and($config->failureTtl())->toBe(5.0)
            ->and($config->useTls())->toBeTrue()
            ->and($config->forceIPv4())->toBeFalse()
            ->and($config->warmUpHosts())->toBe(['api.telegram.org']);
    });

    it('defaults match the shipped config', function () {
        $config = new AskDnsConfig();

        expect($config->dnsServers())->toBe(['8.8.8.8', '1.1.1.1'])
            ->and($config->timeout())->toBe(3.0)
            ->and($config->ttl())->toBe(300.0)
            ->and($config->failureTtl())->toBe(10.0)
            ->and($config->useTls())->toBeFalse()
            ->and($config->forceIPv4())->toBeTrue()
            ->and($config->warmUpHosts())->toBe([]);
    });

    it('withTtl returns an immutable copy', function () {
        $config = new AskDnsConfig(ttl: 300.0);
        $copy = $config->withTtl(60.0);

        expect($copy->ttl())->toBe(60.0)
            ->and($config->ttl())->toBe(300.0);
    });

    it('toArray round-trips all settings', function () {
        $config = new AskDnsConfig(
            dnsServers: ['1.1.1.1'],
            timeout: 2.0,
            ttl: 100.0,
            failureTtl: 5.0,
            useTls: true,
            forceIPv4: false,
            warmUpHosts: ['api.telegram.org'],
        );

        expect($config->toArray())->toBe([
            'dns_servers' => ['1.1.1.1'],
            'timeout' => 2.0,
            'ttl' => 100.0,
            'failure_ttl' => 5.0,
            'use_tls' => true,
            'force_ipv4' => false,
            'warm_up_hosts' => ['api.telegram.org'],
        ]);
    });

    it('toCurlDnsServers formats a semicolon-separated host:53 list', function () {
        $config = new AskDnsConfig(dnsServers: ['1.1.1.1', '8.8.8.8:5353']);

        expect($config->toCurlDnsServers())->toBe('1.1.1.1:53;8.8.8.8:5353');
    });

    it('toCurlDnsServers returns null for an empty server list', function () {
        $config = new AskDnsConfig(dnsServers: []);

        expect($config->toCurlDnsServers())->toBeNull();
    });
});
