<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

use BAGArt\ASKClient\Contracts\Dns\AskDnsAdapterContract;
use BAGArt\ASKClient\Exceptions\AskConfigException;
use Closure;

/**
 * Instantiates adapters (class → instance) for the {@see AskDnsRegistry}.
 *
 * There is exactly ONE universal creation model:
 * {@see AskDnsAdapterContract::fromConfig()} is mandatory and always works.
 * The optional resolver is an extension reserved for classes that genuinely
 * need container services; it never implies a second way of building adapters.
 * No PSR-11 container dependency — Laravel's container neither implements
 * Psr\Container\ContainerInterface nor has a getContainer() method.
 */
final class AskDnsFactory
{
    /**
     * @var null|Closure(string $class, AskDnsConfig $config): AskDnsAdapterContract
     */
    private readonly ?Closure $resolver;

    /**
     * @param null|callable(string $class, AskDnsConfig $config): AskDnsAdapterContract $resolver
     *        Optional container-backed resolver (e.g. Laravel's $app->make). When null,
     *        adapters are constructed via their uniform static factory fromConfig().
     */
    public function __construct(
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver;
    }

    public function create(string $class, AskDnsConfig $config): AskDnsAdapterContract
    {
        if (!class_exists($class)) {
            throw new AskConfigException("DNS adapter class {$class} not found.");
        }
        if (!is_a($class, AskDnsAdapterContract::class, true)) {
            throw new AskConfigException(
                "DNS adapter class {$class} must implement ".AskDnsAdapterContract::class,
            );
        }

        if ($this->resolver !== null) {
            return ($this->resolver)($class, $config);
        }

        return $class::fromConfig($config);
    }
}
