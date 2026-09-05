<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Client;

use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\WithASKTickableContract;

interface AskNetworkClientContract extends WithASKTickableContract
{
    /**
     * The returned promise always resolves with ASKHttpResponse —
     * each implementation must normalize its native response type itself.
     */
    public function request(ASKHttpRequest $request): ASKPromiseContract;
}
