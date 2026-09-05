<?php

declare(strict_types=1);

use BAGArt\ASKClient\SocketClient\AskHttpSocketClient;
use BAGArt\ASKClient\SocketClient\HttpsSocketClientConfig;

include_once __DIR__ . '/../promise-wrapper/AskClientWithPromiseWrapper.php';

/**
 * Build a Guzzle-based NetworkClientContract whose promises carry tickables,
 * allowing synchronous ->wait() calls.
 *
 * @return callable(array<string, mixed>): AskClientWithPromiseWrapper
 */
return static function (array $options = []): AskClientWithPromiseWrapper {
    return new AskClientWithPromiseWrapper(
        new AskHttpSocketClient(
            config: new HttpsSocketClientConfig(
                keepAlive: (bool)($options['keep_alive'] ?? true),
                forceIPv4: (bool)($options['force_ipv4'] ?? true),
                dnsCache: (bool)($options['dns_cache'] ?? true),
                dnsCacheTtl: (float)($options['dns_cache_ttl'] ?? 60.0),
            ),
        )
    );
};
