<?php

declare(strict_types=1);

use BAGArt\ASKClient\Contracts\Client\AskNetworkClientContract;
use BAGArt\ASKClient\HttpClient\Adapters\AskCurlMultiClientAdapter;

/**
 * Build a cURL-based NetworkClientContract.
 *
 * Usage:
 *   $makeClient = require __DIR__.'/../client/curl_client.php';
 *   $client = $makeClient();
 *
 * @return callable(array<string, mixed>): AskNetworkClientContract
 */
return static function (array $options = []): AskNetworkClientContract {
    return new AskCurlMultiClientAdapter(
        multiHandle: $options['multi_handle'] ?? null,
    );
};
