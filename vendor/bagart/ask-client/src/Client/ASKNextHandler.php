<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client;

use BAGArt\ASKClient\Contracts\Pipeline\ASKContextContract;
use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;

final class ASKNextHandler
{
    private function __construct(
        private readonly \Closure $handler,
    ) {
    }

    public static function wrap(callable $handler): self
    {
        return new self(\Closure::fromCallable($handler));
    }

    public function __invoke(object $operation, ASKContextContract $context): ASKFutureContract
    {
        return ($this->handler)($operation, $context);
    }
}
