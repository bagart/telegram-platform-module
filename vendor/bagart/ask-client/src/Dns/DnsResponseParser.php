<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

final class DnsResponseParser
{
    public function parse(int $expectedId, string $data): ?DnsResult
    {
        $len = strlen($data);

        if ($len < 12) {
            return null;
        }

        $id = unpack('n', $data)[1];
        if ($id !== $expectedId) {
            return null;
        }

        $flags = unpack('n', substr($data, 2, 2))[1];
        if (($flags & 0x000F) !== 0) {
            return null;
        }

        $qdcount = unpack('n', substr($data, 4, 2))[1];
        $ancount = unpack('n', substr($data, 6, 2))[1];

        $offset = 12;

        for ($i = 0; $i < $qdcount; $i++) {
            $offset = $this->skipName($data, $offset);

            if ($offset === false || $offset + 4 > $len) {
                return null;
            }

            $offset += 4;
        }

        for ($i = 0; $i < $ancount; $i++) {
            $offset = $this->skipName($data, $offset);

            if ($offset === false || $offset + 10 > $len) {
                return null;
            }

            $type = unpack('n', substr($data, $offset, 2))[1];
            $ttl = unpack('N', substr($data, $offset + 4, 4))[1];
            $rdlength = unpack('n', substr($data, $offset + 8, 2))[1];

            $offset += 10;

            if ($offset + $rdlength > $len) {
                return null;
            }

            if ($type === 1 && $rdlength === 4) {
                $ip = @inet_ntop(substr($data, $offset, 4));

                if ($ip !== false) {
                    return new DnsResult(ip: $ip, ttl: $ttl);
                }
            }

            $offset += $rdlength;
        }

        return null;
    }

    public static function extractQueryId(string $packet): int
    {
        return unpack('n', $packet)[1];
    }

    private function skipName(string $data, int $offset): int|false
    {
        $len = strlen($data);

        while ($offset < $len) {
            $byte = ord($data[$offset]);

            if ($byte === 0) {
                return $offset + 1;
            }

            if (($byte & 0xC0) === 0xC0) {
                return $offset + 2;
            }

            $offset += 1 + $byte;
        }

        return false;
    }
}
