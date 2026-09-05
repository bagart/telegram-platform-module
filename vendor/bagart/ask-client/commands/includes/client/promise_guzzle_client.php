<?php

declare(strict_types=1);

use BAGArt\ASKClient\HttpClient\Adapters\AskGuzzleClientAdapter;

include_once __DIR__ . '/../promise-wrapper/AskClientWithPromiseWrapper.php';

/**
 * Build a Guzzle-based NetworkClientContract whose promises carry tickables,
 * allowing synchronous ->wait() calls.
 *
 * @return callable(array<string, mixed>): AskClientWithPromiseWrapper
 */
return static function (array $options = []): AskClientWithPromiseWrapper {
    return new AskClientWithPromiseWrapper(
        new AskGuzzleClientAdapter(
            curlMultiHandler: $options['curl_multi_handler'] ?? null,
        )
    );
};
