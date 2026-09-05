<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

use function chr;
use function pack;
use function strlen;
use function substr;

final class DnsQueryBuilder
{
    private int $nextQueryId = 1;

    public function build(string $host): DnsQuery
    {
        $id = $this->nextId();

        return new DnsQuery(
            id: $id,
            host: $host,
            packet: self::buildPacket($id, $host),
        );
    }

    private function nextId(): int
    {
        $id = $this->nextQueryId++;
        if ($this->nextQueryId > 0xFFFF) {
            $this->nextQueryId = 1;
        }

        return $id;
    }

    private static function buildPacket(int $queryId, string $host): string
    {
        $header = pack('nnnnnn', $queryId, 0x0100, 1, 0, 0, 0,);

        $labels = '';
        $len = strlen($host);
        $start = 0;
        for ($i = 0; $i <= $len; $i++) {
            if ($i === $len || $host[$i] === '.') {
                $labelLen = $i - $start;
                if ($labelLen > 0) {
                    $labels .= chr($labelLen)
                        .substr($host, $start, $labelLen);
                }
                $start = $i + 1;
            }
        }
        $labels .= "\x00";

        $question = $labels.pack('nn', 1, 1);

        return $header.$question;
    }
}
