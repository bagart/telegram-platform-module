<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Tests\HttpClient\Adapters;

use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\Dto\ASKHttpResponse;
use BAGArt\ASKClient\HttpClient\Adapters\AskGuzzleClientAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

describe('AskGuzzleClientAdapter', function () {
    it('resolves the promise with ASKHttpResponse', function () {
        $adapter = new AskGuzzleClientAdapter(
            client: new Client([
                'handler' => new MockHandler([
                    new Response(202, ['X-Test' => '1'], 'payload'),
                ]),
            ]),
        );

        $promise = $adapter->request(new ASKHttpRequest(
            url: 'https://example.test/api',
            method: 'GET',
            requestName: 'test-request',
        ));

        foreach ($adapter->tickable() as $tickable) {
            $tickable->tick(0);
        }

        $response = $promise->await();

        expect($response)->toBeInstanceOf(ASKHttpResponse::class)
            ->and($response->getStatusCode())->toBe(202)
            ->and($response->getHeaderLine('x-test'))->toBe('1')
            ->and((string)$response->getBody())->toBe('payload');
    });
});
