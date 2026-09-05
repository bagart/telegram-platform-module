<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Tests\Dto;

use BAGArt\ASKClient\Dto\ASKHttpResponse;
use GuzzleHttp\Psr7\Response;

describe('ASKHttpResponse', function () {
    describe('fromString', function () {
        it('builds a response with defaults', function () {
            $response = ASKHttpResponse::fromString('hello');

            expect($response->getProtocolVersion())->toBe('1.1')
                ->and($response->getStatusCode())->toBe(200)
                ->and($response->getReasonPhrase())->toBe('OK')
                ->and((string)$response->getBody())->toBe('hello');
        });

        it('exposes a readable seekable body', function () {
            $response = ASKHttpResponse::fromString('abcdef');
            $body = $response->getBody();

            expect($body->isReadable())->toBeTrue()
                ->and($body->isSeekable())->toBeTrue()
                ->and($body->read(3))->toBe('abc')
                ->and($body->eof())->toBeFalse()
                ->and($body->read(10))->toBe('def')
                ->and($body->eof())->toBeTrue();

            $body->rewind();

            expect($body->getContents())->toBe('abcdef');
        });

        it('normalizes scalar header values to lists', function () {
            $response = ASKHttpResponse::fromString(
                'body',
                headers: ['x-custom' => 'value'],
            );

            expect($response->getHeader('X-Custom'))->toBe(['value']);
        });
    });

    describe('fromJson', function () {
        it('encodes the payload with a json content type', function () {
            $response = ASKHttpResponse::fromJson(['ok' => true]);

            expect($response->getHeaderLine('content-type'))->toBe('application/json')
                ->and(json_decode((string)$response->getBody(), true))->toBe(['ok' => true]);
        });

        it('round-trips unicode payloads', function () {
            $payload = ['description' => 'Привет, мир'];

            $response = ASKHttpResponse::fromJson($payload);

            expect(json_decode((string)$response->getBody(), true))->toBe($payload);
        });

        it('accepts a non-default status code', function () {
            $response = ASKHttpResponse::fromJson(['ok' => false], 429);

            expect($response->getStatusCode())->toBe(429);
        });
    });

    describe('fromPsr7', function () {
        it('copies status, protocol, headers, reason and body', function () {
            $response = ASKHttpResponse::fromPsr7(
                new Response(418, ['X-Teapot' => 'short'], 'teapot body', '1.0', 'I\'m a teapot'),
            );

            expect($response->getStatusCode())->toBe(418)
                ->and($response->getReasonPhrase())->toBe('I\'m a teapot')
                ->and($response->getProtocolVersion())->toBe('1.0')
                ->and($response->getHeaderLine('x-teapot'))->toBe('short')
                ->and((string)$response->getBody())->toBe('teapot body');
        });
    });
});
