<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\HttpClient\Adapters;

use BAGArt\ASKClient\Contracts\Client\AskNetworkClientContract;
use BAGArt\ASKClient\Drivers\CurlTickableDriver;
use BAGArt\ASKClient\Dns\AskDnsConfig;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Promise\ASKDeferred;
use CurlHandle;
use CurlMultiHandle;

final class AskCurlMultiClientAdapter implements AskNetworkClientContract
{
    private readonly CurlTickableDriver $driver;

    private readonly ?string $dnsServers;

    public function __construct(
        ?CurlMultiHandle $multiHandle = null,
        ?AskDnsConfig $dnsConfig = null,
    ) {
        $this->driver = new CurlTickableDriver($multiHandle);

        // CURLOPT_DNS_SERVERS only takes effect under a c-ares libcurl build.
        // Resolve once at construction so the per-request hot path is a no-op
        // when no custom servers are configured or c-ares is unavailable.
        $this->dnsServers = ($dnsConfig !== null && AskDnsConfig::libcurlSupportsCares())
            ? $dnsConfig->toCurlDnsServers()
            : null;
    }

    public function request(ASKHttpRequest $request): ASKPromiseContract
    {
        $ch = curl_init();

        if (!$ch instanceof CurlHandle) {
            throw new ASKNetworkException('Failed to initialize curl handle.');
        }

        $method = strtoupper($request->method);

        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

            if ($request->body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request->body);
            }
        }

        if ($request->body !== null && !isset($request->headers['Content-Type'])) {
            $request->headers['Content-Type'] = 'application/json';
        }

        $options = [
            CURLOPT_URL => $request->getUrlWithQuery(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_FAILONERROR => false,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $request->formattedHeaders(),
        ];

        if ($this->dnsServers !== null) {
            $options[CURLOPT_DNS_SERVERS] = $this->dnsServers;
        }

        curl_setopt_array($ch, $options);

        foreach ($request->curlOptions as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        $this->driver->addHandle($ch);

        $id = spl_object_id($ch);

        $deferred = new ASKDeferred();
        $this->driver->registerDeferred($id, $deferred);

        return $deferred->promise();
    }

    public function tickable(): array
    {
        return [$this->driver];
    }

    public function tick(int $systemPressure = 0): void
    {
        $this->driver->tick($systemPressure);
    }

    public function isIdle(): bool
    {
        return $this->driver->isIdle();
    }

    public function queueSize(): int
    {
        return $this->driver->queueSize();
    }

    public function execute(bool $repeatUntilPerform = false, int &$active = 0): int
    {
        return $this->driver->execute($repeatUntilPerform, $active);
    }

    /**
     * @return \Generator<int, CurlHandle>
     */
    public function readCompletedHandles(): \Generator
    {
        return $this->driver->readCompletedHandles();
    }

    public function readCompletedHandle(): ?CurlHandle
    {
        return $this->driver->readCompletedHandle();
    }

    public function remove(CurlHandle $ch): void
    {
        $this->driver->removeHandle($ch);
    }

    public function select(float $timeoutSec = 0.1): int
    {
        return $this->driver->select($timeoutSec);
    }

    public function close(): void
    {
        $this->driver->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
