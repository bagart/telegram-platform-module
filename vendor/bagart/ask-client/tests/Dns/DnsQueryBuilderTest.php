<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Tests\Dns;

use BAGArt\ASKClient\Dns\DnsQueryBuilder;

describe('DnsQueryBuilder', function () {
    it('builds a valid DNS query packet with unique IDs', function () {
        $builder = new DnsQueryBuilder();

        $query1 = $builder->build('example.com');
        $query2 = $builder->build('example.com');

        expect($query1->host)->toBe('example.com');
        expect($query2->host)->toBe('example.com');
        expect($query1->id)->not->toBe($query2->id);
        expect($query1->packet)->not->toBe($query2->packet);
    });

    it('produces a parseable DNS header', function () {
        $builder = new DnsQueryBuilder();
        $query = $builder->build('google.com');

        $packet = $query->packet;
        expect(strlen($packet))->toBeGreaterThan(12);

        $id = unpack('n', $packet)[1];
        expect($id)->toBe($query->id);

        $flags = unpack('n', substr($packet, 2, 2))[1];
        expect($flags & 0x8000)->toBe(0);
        expect($flags & 0x0100)->not->toBe(0);
    });

    it('handles multiple labels', function () {
        $builder = new DnsQueryBuilder();
        $query = $builder->build('sub.domain.example.com');

        $packet = $query->packet;
        $labels = substr($packet, 12);

        $expected = "\x03sub\x06domain\x07example\x03com\x00";
        expect(substr($labels, 0, strlen($expected)))->toBe($expected);
    });

    it('wraps query ID at 0xFFFF', function () {
        $builder = new DnsQueryBuilder();

        $ref = new \ReflectionClass($builder);
        $prop = $ref->getProperty('nextQueryId');
        $prop->setAccessible(true);
        $prop->setValue($builder, 0xFFFF);

        $q1 = $builder->build('test.com');
        $q2 = $builder->build('test.com');

        expect($q1->id)->toBe(0xFFFF);
        expect($q2->id)->toBe(1);
    });

    it('generates consecutive IDs', function () {
        $builder = new DnsQueryBuilder();

        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $builder->build('test.com')->id;
        }

        for ($i = 1, $iMax = count($ids); $i < $iMax; $i++) {
            expect($ids[$i])->toBe($ids[$i - 1] + 1);
        }
    });
});
