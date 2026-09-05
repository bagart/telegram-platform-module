<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Tests\Dns;

use BAGArt\ASKClient\Dns\DnsQueryBuilder;
use BAGArt\ASKClient\Dns\DnsResponseParser;

describe('DnsResponseParser', function () {
    it('returns null for data shorter than DNS header', function () {
        $parser = new DnsResponseParser();
        expect($parser->parse(1, 'short'))->toBeNull();
    });

    it('returns null on query ID mismatch', function () {
        $parser = new DnsResponseParser();

        $response = pack('n', 42)
            .pack('n', 0x8180)
            .pack('nnnn', 1, 1, 0, 0)
            .str_repeat("\x00", 100);

        expect($parser->parse(1, $response))->toBeNull();
    });

    it('returns null on DNS error (RCODE != 0)', function () {
        $parser = new DnsResponseParser();

        $response = pack('n', 1)
            .pack('n', 0x8183)
            .pack('nnnn', 1, 0, 0, 0)
            .str_repeat("\x00", 100);

        expect($parser->parse(1, $response))->toBeNull();
    });

    it('parses a valid A record response', function () {
        $parser = new DnsResponseParser();
        $builder = new DnsQueryBuilder();

        $query = $builder->build('example.com');

        $labels = "\x07example\x03com\x00";
        $question = $labels.pack('nn', 1, 1);
        $answerName = "\xC0\x0C";
        $answer = $answerName.pack('nnNn', 1, 1, 300, 4).inet_pton('93.184.216.34');

        $header = pack('nnnnnn', $query->id, 0x8180, 1, 1, 0, 0);
        $response = $header.$question.$answer;

        $result = $parser->parse($query->id, $response);

        expect($result)->not->toBeNull();
        expect($result->ip)->toBe('93.184.216.34');
        expect($result->ttl)->toBe(300);
    });

    it('parses A record with compressed name pointer', function () {
        $parser = new DnsResponseParser();
        $builder = new DnsQueryBuilder();

        $query = $builder->build('test.example.com');

        $labels = "\x04test\x07example\x03com\x00";
        $question = $labels.pack('nn', 1, 1);
        $answer = "\xC0\x0C".pack('nnNn', 1, 1, 120, 4).inet_pton('1.2.3.4');

        $header = pack('nnnnnn', $query->id, 0x8180, 1, 1, 0, 0);
        $response = $header.$question.$answer;

        $result = $parser->parse($query->id, $response);
        expect($result)->not->toBeNull();
        expect($result->ip)->toBe('1.2.3.4');
        expect($result->ttl)->toBe(120);
    });

    it('skips non-A records and finds the A record', function () {
        $parser = new DnsResponseParser();
        $builder = new DnsQueryBuilder();

        $query = $builder->build('example.com');
        $labels = "\x07example\x03com\x00";
        $question = $labels.pack('nn', 1, 1);

        $cnameName = "\x07example\x04test\x03com\x00";
        $cname = "\xC0\x0C".pack('nnNn', 5, 1, 300, strlen($cnameName)).$cnameName;
        $aRecord = "\xC0\x0C".pack('nnNn', 1, 1, 200, 4).inet_pton('10.0.0.1');

        $header = pack('nnnnnn', $query->id, 0x8180, 1, 2, 0, 0);
        $response = $header.$question.$cname.$aRecord;

        $result = $parser->parse($query->id, $response);
        expect($result)->not->toBeNull();
        expect($result->ip)->toBe('10.0.0.1');
        expect($result->ttl)->toBe(200);
    });

    it('extractQueryId returns the correct ID from a packet', function () {
        $packet = pack('n', 12345).str_repeat("\x00", 10);
        expect(DnsResponseParser::extractQueryId($packet))->toBe(12345);
    });

    it('returns null for response without answer section', function () {
        $parser = new DnsResponseParser();

        $response = pack('nnnnnn', 1, 0x8180, 1, 0, 0, 0)
            ."\x07example\x03com\x00".pack('nn', 1, 1);

        expect($parser->parse(1, $response))->toBeNull();
    });

    it('returns null for short truncated response', function () {
        $parser = new DnsResponseParser();

        $response = pack('nnnnnn', 1, 0x8180, 1, 1, 0, 0)
            ."\x07example\x03com\x00".pack('nn', 1, 1)
            ."\xC0\x0C".pack('nnNn', 1, 1, 300, 4).substr(inet_pton('1.2.3.4'), 0, 2);

        expect($parser->parse(1, $response))->toBeNull();
    });
});
