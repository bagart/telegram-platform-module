<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Transport;

use BAGArt\ASKClient\Contracts\Pipeline\ASKContextContract;
use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;

interface ASKTransportContract
{
    public function execute(
        object $operation,
        ASKContextContract $context,
    ): ASKFutureContract;
}
