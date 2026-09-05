<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client;

use BAGArt\ASKClient\Contracts\Pipeline\ASKContextContract;
use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\ASKClient\Contracts\Transport\ASKTransportContract;

final class ASKTransport implements ASKTransportContract
{
    public function __construct(
        private readonly \Closure $executor,
    ) {
    }

    public static function wrap(callable $executor): self
    {
        return new self(\Closure::fromCallable($executor));
    }

    public function execute(object $operation, ASKContextContract $context): ASKFutureContract
    {
        return ($this->executor)($operation, $context);
    }
}
