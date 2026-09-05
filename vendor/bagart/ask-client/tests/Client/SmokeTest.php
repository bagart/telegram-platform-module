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

describe('Smoke: full pipeline', function () {
    it('flows client → handler → transport → future → await()', function () {
        $log = new \stdClass();
        $log->entries = [];

        $handler = new class ($log) implements ASKHandlerContract {
            public function __construct(
                private readonly \stdClass $log,
            ) {
            }

            public function __invoke(
                object $operation,
                ASKContextContract $context,
                ASKNextHandler $next
            ): ASKFutureContract {
                $this->log->entries[] = 'handler';

                return $next($operation, $context->with('ctx', true));
            }
        };

        $transport = ASKTransport::wrap(function (object $op, ASKContextContract $ctx) use ($log) {
            $log->entries[] = 'transport';

            return ASKFuture::resolved(['ctx' => $ctx->get('ctx')]);
        });

        $client = new ASKClient(
            transport: $transport,
            handlers: [$handler],
        );

        $future = $client->execute(new \stdClass(), ASKContext::from(['initial' => true]));

        expect($future)->toBeInstanceOf(ASKFutureContract::class);

        $result = $future->await();

        expect($log->entries)->toBe(['handler', 'transport']);
        expect($result)->toBe(['ctx' => true]);
    });

    it('supports then/catch/recover/finally chain on execute() result', function () {
        $client = new ASKClient(
            transport: ASKTransport::wrap(fn () => ASKFuture::resolved(10)),
        );

        $result = $client
            ->execute(new \stdClass())
            ->then(fn ($x) => $x + 5)
            ->then(fn ($x) => $x * 2)
            ->await();

        expect($result)->toBe(30);

        $errorClient = new ASKClient(
            transport: ASKTransport::wrap(fn () => ASKFuture::failed(new \RuntimeException('fail'))),
        );

        $recovered = $errorClient
            ->execute(new \stdClass())
            ->recover(fn (\Throwable $e) => 'recovered')
            ->await();

        expect($recovered)->toBe('recovered');
    });
});
