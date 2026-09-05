<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Transport\Adapters;

use BAGArt\ASKClient\Contracts\Client\WarmableClientContract;
use BAGArt\ASKClient\Contracts\Transport\HttpTransportContract;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\Dto\ASKHttpResponse;
use BAGArt\ASKClient\SocketClient\AskHttpSocketClient;
use BAGArt\ASKClient\SocketClient\HttpsSocketClientConfig;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Promise\ASKPromise;
use LogicException;
use Throwable;

final class ASKSocketTransportAdapter implements
    HttpTransportContract,
    WarmableClientContract,
    ASKTickableContract
{
    public const string TYPE = 'ask-socket';

    private int $pendingCount = 0;

    public function __construct(
        private readonly AskHttpSocketClient $client = new AskHttpSocketClient(),
    ) {
    }

    public static function withConfig(HttpsSocketClientConfig $config): self
    {
        return new self(new AskHttpSocketClient($config));
    }

    public function request(ASKHttpRequest $request): ASKHttpResponse
    {
        return $this->requestAsync($request)->await();
    }

    public function requestAsync(ASKHttpRequest $request): ASKPromiseContract
    {
        $promise = new ASKPromise(...$this->tickable());

        try {
            $innerPromise = $this->client->request($request);
        } catch (Throwable $e) {
            $promise->reject($e);

            return $promise;
        }

        $this->pendingCount++;

        try {
            $innerPromise->then(
                function (mixed $value) use ($promise): void {
                    try {
                        $this->settleRequest();
                    } finally {
                        $promise->resolve($value);
                    }
                },
                function (Throwable $e) use ($promise): void {
                    try {
                        $this->settleRequest();
                    } finally {
                        $promise->reject($e);
                    }
                },
            );
        } catch (Throwable $e) {
            $this->pendingCount--;
            $promise->reject($e);
        }

        return $promise;
    }

    private function settleRequest(): void
    {
        if ($this->pendingCount <= 0) {
            throw new LogicException(
                'ASKSocketTransportAdapter: pending counter underflow (request settled twice?)',
            );
        }

        --$this->pendingCount;
    }

    public function tick(int $systemPressure): void
    {
        $this->client->tick($systemPressure);
    }

    public function pressure(): int
    {
        return $this->client->pressure();
    }

    public function isIdle(): bool
    {
        return $this->pendingCount === 0 && $this->client->isIdle();
    }

    public function queueSize(): int
    {
        return $this->pendingCount;
    }

    public function tickable(): array
    {
        return $this->client->tickable();
    }

    public function drain(): void
    {
        $this->client->drain();
    }

    public function warmUp(string $host, int $count, int $port = 443): int
    {
        return $this->client->warmUp($host, $count, $port);
    }
}
