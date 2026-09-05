<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

final class DnsPendingQuery
{
    public function __construct(
        public readonly string $host,
        public readonly int $queryId,
        public readonly string $rawPacket,
        public int $deadline,
        public int $retries,
        public array $sockets,
    ) {
    }
}
