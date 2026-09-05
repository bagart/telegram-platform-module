<?php

declare(strict_types=1);

use BAGArt\ASKClient\Contracts\Client\AskNetworkClientContract;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Promise\ASKPromise;

final class AskClientWithPromiseWrapper implements AskNetworkClientContract
{
    public function __construct(
        private readonly AskNetworkClientContract $inner,
    ) {
    }

    public function request(ASKHttpRequest $request): ASKPromiseContract
    {
        $promise = $this->inner->request($request);

        $wrapped = new ASKPromise(...$this->inner->tickable());

        $promise->then(
            fn (mixed $value): mixed => $wrapped->resolve($value),
            fn (\Throwable $reason): ?\Throwable => $wrapped->reject($reason),
        );

        return $wrapped;
    }

    public function tickable(): array
    {
        return $this->inner->tickable();
    }
}
