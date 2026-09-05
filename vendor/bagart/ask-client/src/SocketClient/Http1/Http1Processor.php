<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\SocketClient\Http1;

use BAGArt\ASKClient\Contracts\Client\ProtocolProcessorContract;
use BAGArt\ASKClient\Dto\ASKHttpResponse;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use Psr\Http\Message\ResponseInterface;

final class Http1Processor implements ProtocolProcessorContract
{
    /** Resident bytes kept in RAM by php://temp before spilling to a temp file. */
    private const int BODY_TEMP_MEMORY_BYTES = 2_097_152;

    private bool $headersParsed = false;
    private int $contentLength = -1;
    private bool $isChunked = false;

    /** @var resource|null php://temp stream accumulating the decoded chunked body. */
    private mixed $bodyStream = null;
    private int $bufferOffset = 0;

    private string $currentProtocol = '1.1';
    private int $currentStatus = 200;
    private string $currentReason = 'OK';
    private array $currentHeaders = [];

    public function handleBuffer(string &$buffer): ?ResponseInterface
    {
        return $this->processResponse($buffer);
    }

    public function drainOutbound(): string
    {
        return '';
    }

    private function processResponse(string &$buffer): ?ResponseInterface
    {
        if (!$this->headersParsed) {
            $separator = \strpos($buffer, "\r\n\r\n", $this->bufferOffset);
            if ($separator === false) {
                return null;
            }

            $headerBlockEnd = $separator;
            $bodyStart = $separator + 4;
            $headerBlock = \substr($buffer, $this->bufferOffset, $headerBlockEnd - $this->bufferOffset);

            $firstCrlf = \strpos($headerBlock, "\r\n");
            if ($firstCrlf === false) {
                throw new ASKNetworkException(
                    'Invalid HTTP protocol structure'
                );
            }

            $statusLine = \substr($headerBlock, 0, $firstCrlf);

            // Parse "HTTP/<ver> <code> <reason>" without the regex engine. We locate the two
            // spaces that delimit the fields rather than assuming fixed offsets, so this stays
            // correct across "HTTP/1.0", "HTTP/1.1", "HTTP/2.0" and any status/reason length.
            if (!\str_starts_with($statusLine, 'HTTP/')) {
                throw new ASKNetworkException(
                    'Malformed HTTP status line'
                );
            }

            $firstSpace = \strpos($statusLine, ' ', 5);
            if ($firstSpace === false) {
                throw new ASKNetworkException(
                    'Malformed HTTP status line'
                );
            }

            $this->currentProtocol = \substr($statusLine, 5, $firstSpace - 5);

            $secondSpace = \strpos($statusLine, ' ', $firstSpace + 1);
            if ($secondSpace === false) {
                $this->currentStatus = (int)\substr($statusLine, $firstSpace + 1);
                $this->currentReason = '';
            } else {
                $this->currentStatus = (int)\substr($statusLine, $firstSpace + 1, $secondSpace - $firstSpace - 1);
                $this->currentReason = \substr($statusLine, $secondSpace + 1);
            }

            $headerLines = \substr($headerBlock, $firstCrlf + 2);
            $this->currentHeaders = [];

            // Single-pass strpos walk over the header block: avoids allocating the array that
            // explode("\r\n", $headerLines) would create on every response. The final header
            // line has no trailing CRLF inside the block (it is terminated by the blank-line
            // separator), so it is processed after the loop — dropping it would lose framing
            // headers that servers place last (GFE sends "Transfer-Encoding: chunked" last).
            $offset = 0;
            $processLine = function (string $line): void {
                $colon = \strpos($line, ':');
                if ($colon !== false) {
                    $name = \strtolower(\substr($line, 0, $colon));
                    $value = \substr($line, $colon + 1);
                    if ($value !== '' && (\ord($value[0]) === 32 || \ord($value[0]) === 9)) {
                        $value = self::trimHeader($value);
                    }
                    $this->currentHeaders[$name][] = $value;
                }
            };
            while (($pos = \strpos($headerLines, "\r\n", $offset)) !== false) {
                $processLine(\substr($headerLines, $offset, $pos - $offset));
                $offset = $pos + 2;
            }
            $processLine(\substr($headerLines, $offset));

            $te = $this->currentHeaders['transfer-encoding'][0] ?? '';
            $this->isChunked = \str_contains(\strtolower($te), 'chunked');
            $this->contentLength = isset($this->currentHeaders['content-length'][0])
                ? (int)$this->currentHeaders['content-length'][0]
                : -1;
            $this->headersParsed = true;
            $this->bufferOffset = $bodyStart;
        }

        if ($this->isChunked) {
            return $this->parseChunkedStream($buffer);
        }

        if ($this->contentLength !== -1) {
            $remaining = \strlen($buffer) - $this->bufferOffset;
            if ($remaining < $this->contentLength) {
                return null;
            }
            $body = \substr($buffer, $this->bufferOffset, $this->contentLength);
            $this->bufferOffset += $this->contentLength;
            $this->maybeShrinkBuffer($buffer);

            return $this->emitResponse($body);
        }

        $connectionHeader = \strtolower($this->currentHeaders['connection'][0] ?? '');
        $connectionClose = \str_contains($connectionHeader, 'close');
        if ($this->currentProtocol === '1.0' || $connectionClose) {
            $body = \substr($buffer, $this->bufferOffset);
            $this->bufferOffset = \strlen($buffer);
            $this->maybeShrinkBuffer($buffer);

            return $this->emitResponse($body);
        }

        throw new ASKNetworkException(
            'HTTP/1.1 response without Content-Length or Transfer-Encoding'
        );
    }

    private function maybeShrinkBuffer(string &$buffer): void
    {
        if ($this->bufferOffset > 8192) {
            $buffer = \substr($buffer, $this->bufferOffset);
            $this->bufferOffset = 0;
        }
    }

    private static function trimHeader(string $value): string
    {
        $len = \strlen($value);
        $start = 0;
        while ($start < $len) {
            $c = \ord($value[$start]);
            if ($c !== 32 && $c !== 9) {
                break;
            }
            $start++;
        }
        $end = $len - 1;
        while ($end >= $start) {
            $c = \ord($value[$end]);
            if ($c !== 32 && $c !== 9) {
                break;
            }
            $end--;
        }

        return $start > $end ? '' : \substr($value, $start, $end - $start + 1);
    }

    private function parseChunkedStream(string &$buffer): ?ResponseInterface
    {
        $pos = $this->bufferOffset;

        while (true) {
            $crlf = \strpos($buffer, "\r\n", $pos);
            if ($crlf === false) {
                $this->bufferOffset = $pos;

                return null;
            }

            $hexLen = $crlf - $pos;
            $hex = \substr($buffer, $pos, $hexLen);
            $chunkSize = \hexdec(\rtrim($hex, "\r\n"));

            if ($chunkSize === 0) {
                $this->bufferOffset = $crlf + 4;
                $this->maybeShrinkBuffer($buffer);

                $body = $this->bodyStream !== null
                    ? $this->drainBodyStream($this->bodyStream)
                    : '';
                $this->bodyStream = null;

                return $this->emitResponse($body);
            }

            $chunkStart = $crlf + 2;
            $chunkEnd = $chunkStart + $chunkSize;

            if (\strlen($buffer) < $chunkEnd + 2) {
                $this->bufferOffset = $pos;

                return null;
            }

            $this->ensureBodyStream();
            \fwrite($this->bodyStream, \substr($buffer, $chunkStart, $chunkSize));
            $pos = $chunkEnd + 2;
            $this->bufferOffset = $pos;
            $this->maybeShrinkBuffer($buffer);
            $pos = $this->bufferOffset;
        }
    }

    /**
     * Lazily open the php://temp accumulator on first decoded chunk.
     *
     * @return resource
     */
    private function ensureBodyStream(): mixed
    {
        if ($this->bodyStream === null) {
            $this->bodyStream = \fopen(
                'php://temp/maxmemory:'.self::BODY_TEMP_MEMORY_BYTES,
                'r+b',
            );
        }

        return $this->bodyStream;
    }

    /**
     * Read the php://temp accumulator fully into a string and close it,
     * materializing the assembled body once.
     *
     * @param  resource  $stream
     */
    private function drainBodyStream($stream): string
    {
        \rewind($stream);
        $body = \stream_get_contents($stream);
        \fclose($stream);

        return $body;
    }

    private function emitResponse(string $body): ResponseInterface
    {
        $response = ASKHttpResponse::fromString(
            $body,
            $this->currentStatus,
            $this->currentReason,
            $this->currentHeaders,
            $this->currentProtocol,
        );

        $this->headersParsed = false;
        if ($this->bodyStream !== null) {
            \fclose($this->bodyStream);
            $this->bodyStream = null;
        }
        $this->currentHeaders = [];
        $this->bufferOffset = 0;

        return $response;
    }
}
