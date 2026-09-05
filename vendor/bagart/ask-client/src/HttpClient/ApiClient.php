<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\HttpClient;

use BAGArt\ASKClient\Contracts\Client\ApiClientContract;
use BAGArt\ASKClient\Contracts\Client\AskNetworkClientContract;
use BAGArt\ASKClient\Contracts\RateLimiter\RateLimiterContract;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\Dto\ASKHttpResponse;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\AsyncKernel\ASK;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Promise\ASKPromiseResolver;

final class ApiClient implements ApiClientContract
{
    public function __construct(
        private readonly AskNetworkClientContract $transport,
        private readonly RateLimiterContract $rateLimiter,
        private readonly ASKPromiseResolver $promiseResolver,
    ) {
    }

    /** @return ASKTickableContract[] */
    public function tickable(): array
    {
        return [
            ...$this->transport->tickable(),
            $this->promiseResolver,
        ];
    }

    public function request(ASKHttpRequest $request): ASKHttpResponse
    {
        return $this->await($this->requestAsync($request));
    }

    public function requestAsync(ASKHttpRequest $request): ASKPromiseContract
    {
        $key = $this->rateLimitKey($request);

        $delay = $this->rateLimiter->getRetryDelay($key);
        if ($delay > 0) {
            // $delay is in seconds (float); convert to ms for the cooperative
            // timer. ASK::sleep suspends the Fiber under a kernel, or busy-pumps
            // in sync context — never blocks the whole event loop.
            ASK::sleep((int)ceil($delay * 1_000))->await();
        }

        $promise = $this->transport->requestAsync($request);
        $this->rateLimiter->markSent($key);

        return $promise;
    }

    public function await(ASKPromiseContract $promise, int $timeout = 0): ASKHttpResponse
    {
        try {
            $result = $this->promiseResolver->await($promise, $timeout);
        } catch (ASKNetworkException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ASKNetworkException(
                message: $e->getMessage(),
                previous: $e,
            );
        }

        if ($result instanceof ASKHttpResponse) {
            return $result;
        }

        throw new ASKNetworkException(sprintf(
            'Transport %s violated the response contract: expected %s, got %s',
            $this->transport::class,
            ASKHttpResponse::class,
            get_debug_type($result),
        ));
    }

    private function rateLimitKey(ASKHttpRequest $request): string
    {
        return $request->requestName;
    }
}
