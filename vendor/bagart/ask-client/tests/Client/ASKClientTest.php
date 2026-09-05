<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Tests;

use BAGArt\ASKClient\Client\ASKClient;
use BAGArt\ASKClient\Client\ASKContext;
use BAGArt\ASKClient\Client\ASKFuture;
use BAGArt\ASKClient\Client\ASKNextHandler;
use BAGArt\ASKClient\Client\ASKTransport;
use BAGArt\ASKClient\Contracts\Pipeline\ASKContextContract;
use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\ASKClient\Contracts\Pipeline\ASKHandlerContract;

describe('Client', function () {
    it('executes an operation and returns a Future', function () {
        $client = new ASKClient(
            transport: ASKTransport::wrap(fn () => ASKFuture::resolved('result')),
        );

        $future = $client->execute(new \stdClass());

        expect($future)->toBeInstanceOf(ASKFutureContract::class);
        expect($future->await())->toBe('result');
    });

    it('passes context to transport', function () {
        $captured = null;

        $client = new ASKClient(
            transport: ASKTransport::wrap(function (object $op, ASKContextContract $ctx) use (&$captured) {
                $captured = $ctx->get('key');

                return ASKFuture::resolved(null);
            }),
        );

        $client->execute(new \stdClass(), ASKContext::from(['key' => 'value']));

        expect($captured)->toBe('value');
    });

    it('accepts handlers via constructor', function () {
        $handler = new class () implements ASKHandlerContract {
            public function __invoke(
                object $operation,
                ASKContextContract $context,
                ASKNextHandler $next,
            ): ASKFutureContract {
                return $next($operation, $context)->then(fn () => 'handler-applied');
            }
        };

        $client = new ASKClient(
            transport: ASKTransport::wrap(fn () => ASKFuture::resolved('raw')),
            handlers: [$handler],
        );

        expect($client->execute(new \stdClass())->await())->toBe('handler-applied');
    });

    it('can be constructed directly with transport', function () {
        $client = new ASKClient(
            transport: ASKTransport::wrap(fn () => ASKFuture::resolved(null)),
        );

        expect($client->execute(new \stdClass())->await())->toBeNull();
    });
});
