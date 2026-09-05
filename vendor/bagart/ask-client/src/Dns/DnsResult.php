<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

final readonly class DnsResult
{
    public function __construct(
        public string $ip,
        public int $ttl,
    ) {
    }
}
