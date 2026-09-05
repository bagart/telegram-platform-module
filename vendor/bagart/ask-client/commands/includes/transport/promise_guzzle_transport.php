<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\ASKFuture;
use BAGArt\ASKClient\Client\ASKTransport;
use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\Transport\Adapters\GuzzleTransportAdapter;

/**
 * Build a promise-based guzzle transport.
 *
 * Uses GuzzleTransportAdapter internally and returns ASKFuture that resolves when
 * the underlying promise settles.
 *
 * @return callable(array<string, mixed>): ASKTransport
 */
return static function (array $options = []): ASKTransport {
    $guzzleTransport = new GuzzleTransportAdapter();

    return ASKTransport::wrap(
        static function (ASKHttpRequest $operation) use ($guzzleTransport): ASKFutureContract {
            $promise = $guzzleTransport->requestAsync($operation);

            return ASKFuture::pending(function () use ($promise): mixed {
                return $promise->wait();
            });
        },
    );
};
