<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

final readonly class DnsQuery
{
    public function __construct(
        public int $id,
        public string $host,
        public string $packet,
    ) {
    }
}
