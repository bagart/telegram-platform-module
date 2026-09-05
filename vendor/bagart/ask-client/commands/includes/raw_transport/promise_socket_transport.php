<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\ASKFuture;
use BAGArt\ASKClient\Client\ASKTransport;
use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\Transport\Adapters\ASKSocketTransportAdapter;

/**
 * Build a promise-based socket transport (HTTP/1.1).
 *
 * Uses ASKSocketTransportAdapter internally and returns ASKFuture that resolves when
 * the underlying promise settles.
 *
 * @return callable(array<string, mixed>): ASKTransport
 */
return static function (array $options = []): ASKTransport {
    $socketTransport = new ASKSocketTransportAdapter();

    return ASKTransport::wrap(
        static function (ASKHttpRequest $operation) use ($socketTransport): ASKFutureContract {
            $promise = $socketTransport->requestAsync($operation);

            return ASKFuture::pending(function () use ($promise): mixed {
                return $promise->wait();
            });
        },
    );
};
