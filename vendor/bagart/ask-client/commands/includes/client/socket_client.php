<?php

declare(strict_types=1);

use BAGArt\ASKClient\Contracts\Client\AskNetworkClientContract;
use BAGArt\ASKClient\SocketClient\AskHttpSocketClient;
use BAGArt\ASKClient\SocketClient\HttpsSocketClientConfig;

/**
 * Build a raw TCP/TLS socket-based NetworkClientContract (HTTP/1.1).
 *
 * Usage:
 *   $makeClient = require __DIR__.'/../client/socket_client.php';
 *   $client = $makeClient();
 *
 * @return callable(array<string, mixed>): AskNetworkClientContract
 */
return static function (array $options = []): AskNetworkClientContract {
    return new AskHttpSocketClient(
        config: new HttpsSocketClientConfig(
            keepAlive: (bool)($options['keep_alive'] ?? true),
            forceIPv4: (bool)($options['force_ipv4'] ?? true),
            dnsCache: (bool)($options['dns_cache'] ?? true),
            dnsCacheTtl: (float)($options['dns_cache_ttl'] ?? 60.0),
        ),
    );
};
