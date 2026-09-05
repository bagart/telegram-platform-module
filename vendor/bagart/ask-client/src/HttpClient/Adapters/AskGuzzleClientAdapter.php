<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\HttpClient\Adapters;

use BAGArt\ASKClient\Contracts\Client\AskNetworkClientContract;
use BAGArt\ASKClient\Drivers\GuzzleNetworkTickableDriver;
use BAGArt\ASKClient\Dns\AskDnsConfig;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\Dto\ASKHttpResponse;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Promise\GuzzlePromiseAdapter;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class AskGuzzleClientAdapter implements AskNetworkClientContract
{
    private readonly Client $client;
    private readonly GuzzleNetworkTickableDriver $tickableDriver;

    private int $activeRequestsCount = 0;

    private readonly ?string $dnsServers;

    public function __construct(
        ?CurlMultiHandler $curlMultiHandler = null,
        ?AskDnsConfig $dnsConfig = null,
        ?Client $client = null,
    ) {
        $this->dnsServers = ($dnsConfig !== null && AskDnsConfig::libcurlSupportsCares())
            ? $dnsConfig->toCurlDnsServers()
            : null;

        if ($client !== null) {
            $this->client = $client;
            $this->tickableDriver = new GuzzleNetworkTickableDriver();

            return;
        }

        $curlMultiHandler ??= new CurlMultiHandler(['select_timeout' => 0]);

        $stack = HandlerStack::create($curlMultiHandler);

        $stack->push($this->createTrackingMiddleware(), 'request_tracker');

        $clientConfig = [
            'handler' => $stack,
        ];

        if ($this->dnsServers !== null) {
            $clientConfig['curl'][CURLOPT_DNS_SERVERS] = $this->dnsServers;
        }

        $this->client = new Client($clientConfig);

        $this->tickableDriver = new GuzzleNetworkTickableDriver(
            curlMultiHandler: $curlMultiHandler,
            activeRequestsCount: $this->activeRequestsCount,
        );
    }

    public function request(ASKHttpRequest $request): ASKPromiseContract
    {
        $options = [
            'headers' => $request->headers,
            'body' => $request->body,
            'http_errors' => false,
        ];

        if ($request->curlOptions !== []) {
            $options['curl'] = $request->curlOptions + ($this->dnsServers !== null
                ? [CURLOPT_DNS_SERVERS => $this->dnsServers]
                : []);
        } elseif ($this->dnsServers !== null) {
            $options['curl'] = [CURLOPT_DNS_SERVERS => $this->dnsServers];
        }

        $guzzlePromise = $this->client->requestAsync(
            $request->method,
            $request->getUrlWithQuery(),
            $options,
        );

        return GuzzlePromiseAdapter::wrap($guzzlePromise)->then(
            fn (ResponseInterface $value): ASKHttpResponse => ASKHttpResponse::fromPsr7($value),
        );
    }

    public function tickable(): array
    {
        return [$this->tickableDriver];
    }

    private function createTrackingMiddleware(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->activeRequestsCount++;

                return $handler($request, $options)->then(
                    function (mixed $value): mixed {
                        $this->activeRequestsCount--;
                        return $value;
                    },
                    function (mixed $reason): never {
                        $this->activeRequestsCount--;
                        throw $reason instanceof \Throwable
                            ? $reason
                            : new ASKNetworkException((string)$reason);
                    }
                );
            };
        };
    }
}
