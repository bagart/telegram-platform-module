<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client;

use BAGArt\ASKClient\Contracts\Client\ASKClientContract;
use BAGArt\ASKClient\Contracts\Pipeline\ASKContextContract;
use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\ASKClient\Contracts\Pipeline\ASKHandlerContract;
use BAGArt\ASKClient\Contracts\Transport\ASKTransportContract;

final class ASKClient implements ASKClientContract
{
    /** @var list<ASKHandlerContract> */
    private readonly array $handlers;

    /**
     * @param  ASKHandlerContract[]  $handlers
     */
    public function __construct(
        private readonly ASKTransportContract $transport,
        array $handlers = [],
    ) {
        $this->handlers = array_values($handlers);
    }

    public function execute(object $operation, ?ASKContextContract $context = null): ASKFutureContract
    {
        $context ??= ASKContext::empty();

        $handler = ASKNextHandler::wrap(
            fn (object $op, ASKContextContract $ctx): ASKFutureContract => $this->transport->execute($op, $ctx),
        );

        foreach (array_reverse($this->handlers) as $h) {
            $handler = ASKNextHandler::wrap(
                fn (object $op, ASKContextContract $ctx): ASKFutureContract => $h($op, $ctx, $handler),
            );
        }

        return $handler($operation, $context);
    }
}
