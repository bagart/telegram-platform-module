<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\ASKFuture;
use BAGArt\ASKClient\Client\ASKTransport;
use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\Transport\Adapters\CurlMultiTransportAdapter;

/**
 * Build a promise-based curl transport.
 *
 * Uses CurlMultiTransportAdapter internally and returns ASKFuture that resolves when
 * the underlying promise settles.
 *
 * @return callable(array<string, mixed>): ASKTransport
 */
return static function (array $options = []): ASKTransport {
    $curlTransport = new CurlMultiTransportAdapter();

    return ASKTransport::wrap(
        static function (ASKHttpRequest $operation) use ($curlTransport): ASKFutureContract {
            $promise = $curlTransport->requestAsync($operation);

            return ASKFuture::pending(function () use ($promise): mixed {
                return $promise->wait();
            });
        },
    );
};
