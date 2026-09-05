<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Tests\Dns;

use BAGArt\ASKClient\Dns\DnsCache;

describe('DnsCache', function () {
    it('stores and retrieves entries', function () {
        $cache = new DnsCache(maxEntries: 100, ttl: 60.0, negativeTtl: 10.0);

        $cache->put('example.com', '93.184.216.34', 300);

        expect($cache->get('example.com'))->toBe('93.184.216.34');
        expect($cache->has('example.com'))->toBeTrue();
    });

    it('returns null for expired entries', function () {
        $cache = new DnsCache(maxEntries: 100, ttl: 0.0, negativeTtl: 0.0);

        $cache->put('example.com', '93.184.216.34');
        usleep(1000);

        expect($cache->get('example.com'))->toBeNull();
    });

    it('respects negative cache', function () {
        $cache = new DnsCache(maxEntries: 100, ttl: 60.0, negativeTtl: 10.0);

        $cache->putNegative('nxdomain.test');

        expect($cache->get('nxdomain.test'))->toBeNull();
        expect($cache->has('nxdomain.test'))->toBeTrue();
    });

    it('clear removes all entries', function () {
        $cache = new DnsCache(maxEntries: 100, ttl: 60.0, negativeTtl: 10.0);

        $cache->put('a.com', '1.2.3.4');
        $cache->putNegative('b.com');
        $cache->clear();

        expect($cache->get('a.com'))->toBeNull();
        expect($cache->get('b.com'))->toBeNull();
        expect($cache->count())->toBe(0);
    });

    it('evicts LRU entries when over capacity', function () {
        $cache = new DnsCache(maxEntries: 5, ttl: 60.0, negativeTtl: 10.0);

        for ($i = 0; $i < 10; $i++) {
            $cache->put("host{$i}.test", "10.0.0.{$i}");
        }

        expect($cache->count())->toBeLessThanOrEqual(5);
        expect($cache->get('host0.test'))->toBeNull();
        expect($cache->get('host9.test'))->not->toBeNull();
    });

    it('touch refreshes LRU position', function () {
        $cache = new DnsCache(maxEntries: 3, ttl: 60.0, negativeTtl: 10.0);

        $cache->put('a.test', '1.1.1.1');
        $cache->put('b.test', '2.2.2.2');
        $cache->put('c.test', '3.3.3.3');

        $cache->get('a.test');
        $cache->put('d.test', '4.4.4.4');

        expect($cache->get('a.test'))->not->toBeNull();
        expect($cache->get('b.test'))->toBeNull();
    });

    it('put with null TTL uses default', function () {
        $cache = new DnsCache(maxEntries: 100, ttl: 60.0, negativeTtl: 10.0);

        $cache->put('test.com', '1.2.3.4');

        expect($cache->get('test.com'))->toBe('1.2.3.4');
    });

    it('count returns total entries including negative', function () {
        $cache = new DnsCache(maxEntries: 100, ttl: 60.0, negativeTtl: 10.0);

        $cache->put('positive.test', '1.1.1.1');
        $cache->putNegative('negative.test');

        expect($cache->count())->toBe(2);
    });
});
