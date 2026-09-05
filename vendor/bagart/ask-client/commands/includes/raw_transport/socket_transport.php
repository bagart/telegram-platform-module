<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\ASKFuture;
use BAGArt\ASKClient\Client\ASKTransport;
use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\SocketClient\AskHttpSocketClient;
use BAGArt\ASKClient\SocketClient\HttpsSocketClientConfig;

/**
 * Build a raw socket-based ASKClient transport (HTTP/1.1, one-shot requests).
 *
 * Usage:
 *   $makeTransport = require __DIR__.'/../raw_transport/socket_transport.php';
 *   $transport = $makeTransport();
 *
 * Response array: ['status', 'body', 'http_version'] (compatible with
 * currency-sources.php parsers).
 *
 * @param  array<string, mixed>  $options  extra/override HttpsSocketClientConfig keys
 * @return callable(array<string, mixed>): ASKTransport
 */
return static function (array $options = []): ASKTransport {
    return ASKTransport::wrap(
        static function (ASKHttpRequest $operation) use ($options): ASKFutureContract {
            $client = new AskHttpSocketClient(
                config: new HttpsSocketClientConfig(
                    keepAlive: (bool)($options['keep_alive'] ?? true),
                    forceIPv4: (bool)($options['force_ipv4'] ?? true),
                    dnsCache: (bool)($options['dns_cache'] ?? true),
                    dnsCacheTtl: (float)($options['dns_cache_ttl'] ?? 60.0),
                ),
            );

            $promise = $client->request($operation);

            while ($promise->getState() === \BAGArt\AsyncKernel\Contracts\ASKPromiseContract::PENDING) {
                $client->tick(0);
                usleep(1_000);
            }

            if ($promise->getState() === \BAGArt\AsyncKernel\Contracts\ASKPromiseContract::FULFILLED) {
                $response = $promise->getValue();

                return ASKFuture::resolved([
                    'status' => $response->getStatusCode(),
                    'body' => (string)$response->getBody(),
                    'http_version' => (float)$response->getProtocolVersion(),
                ]);
            }

            return ASKFuture::failed(
                $promise->getReason() ?? new ASKNetworkException('Socket request failed'),
            );
        },
    );
};
