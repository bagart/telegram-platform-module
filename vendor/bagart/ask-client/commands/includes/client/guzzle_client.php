<?php

declare(strict_types=1);

use BAGArt\ASKClient\Contracts\Client\AskNetworkClientContract;
use BAGArt\ASKClient\HttpClient\Adapters\AskGuzzleClientAdapter;

/**
 * Build a Guzzle-based NetworkClientContract.
 *
 * Usage:
 *   $makeClient = require __DIR__.'/../client/guzzle_client.php';
 *   $client = $makeClient();
 *
 * @return callable(array<string, mixed>): AskNetworkClientContract
 */
return static function (array $options = []): AskNetworkClientContract {
    return new AskGuzzleClientAdapter(
        curlMultiHandler: $options['curl_multi_handler'] ?? null,
    );
};
