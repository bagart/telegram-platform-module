<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

use BAGArt\ASKClient\Contracts\Dns\AskDnsAdapterContract;
use BAGArt\ASKClient\Dns\Adapters\AmpDnsAdapter;
use BAGArt\ASKClient\Dns\Adapters\AsyncDnsAdapter;
use BAGArt\ASKClient\Dns\Adapters\NativeDnsAdapter;
use BAGArt\ASKClient\Dns\Adapters\ReactDnsAdapter;
use BAGArt\ASKClient\Exceptions\AskConfigException;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;

/**
 * Pluggable DNS adapter registry — maps TYPE strings (and FQCNs) to adapter
 * classes and constructs one instance via {@see AskDnsFactory}.
 *
 * null semantics — authoritative: `make(null)` ALWAYS means DEFAULT_TYPE.
 * The registry knows nothing about transports; per-transport null semantics
 * (ask-socket → 'ask-dns', curl/guzzle → no PHP adapter) are resolved by the
 * transport wiring BEFORE the registry is invoked.
 */
final class AskDnsRegistry
{
    public const string DEFAULT_TYPE = AsyncDnsAdapter::TYPE; // 'ask-dns'

    /** @var array<string, class-string<AskDnsAdapterContract>> */
    private static array $builtin = [
        AsyncDnsAdapter::TYPE => AsyncDnsAdapter::class,   // 'ask-dns'
        ReactDnsAdapter::TYPE => ReactDnsAdapter::class,   // 'react-dns'
        AmpDnsAdapter::TYPE => AmpDnsAdapter::class,       // 'amphp-dns'
        NativeDnsAdapter::TYPE => NativeDnsAdapter::class, // 'native'
    ];

    /** @var array<string, class-string<AskDnsAdapterContract>> */
    private array $adapters = [];

    private bool $builtinsRegistered = false;

    private readonly ?ASKLogWrapper $logger;
    private readonly AskDnsFactory $factory;

    public function __construct(
        ?ASKLogWrapper $logger = null,
        ?AskDnsFactory $factory = null,
    ) {
        $this->logger = $logger;
        $this->factory = $factory ?? new AskDnsFactory();
    }

    /** @deprecated Use the constructor — kept for backward compatibility. */
    public static function build(
        ?ASKLogWrapper $logger = null,
        ?AskDnsFactory $factory = null,
    ): self {
        return new self($logger, $factory);
    }

    /**
     * Register an adapter. Accepts:
     * - register('ask-dns', AsyncDnsAdapter::class) — type + class
     * - register(AsyncDnsAdapter::class)             — class only, uses TYPE constant
     * Validates immediately: the class must exist and implement AskDnsAdapterContract.
     */
    public function register(string $typeOrClass, ?string $class = null): self
    {
        if ($class !== null) {
            $this->validateClass($class);
            $this->adapters[$typeOrClass] = $class;
        } else {
            $this->validateClass($typeOrClass);
            $this->adapters[$typeOrClass::TYPE] = $typeOrClass;
        }

        return $this;
    }

    public function has(string $type): bool
    {
        $this->ensureBuiltins();

        return isset($this->adapters[$type]);
    }

    /**
     * Resolve an adapter type to its class-string.
     *
     * Resolution order:
     * 1. Registered type (built-in or custom)
     * 2. FQCN — auto-register + cache intentionally; subsequent resolve()/make()
     *    calls for the same FQCN become O(1) because the mapping is stored in
     *    $adapters (mutation-on-read is deliberate, not a surprise).
     * 3. null/empty → DEFAULT_TYPE class
     */
    public function resolve(string|null $type): string
    {
        $this->ensureBuiltins();

        if ($type === null || $type === '') {
            return $this->adapters[self::DEFAULT_TYPE];
        }

        if (isset($this->adapters[$type])) {
            return $this->adapters[$type];
        }

        if (class_exists($type) && is_a($type, AskDnsAdapterContract::class, true)) {
            $this->adapters[$type] = $type;

            return $type;
        }

        throw new AskConfigException(
            "DNS adapter type '{$type}' is not registered and is not a valid FQCN "
            .'implementing '.AskDnsAdapterContract::class.'.'
            .' Registered types: '.implode(', ', array_keys($this->adapters)).'.',
        );
    }

    /**
     * Make an adapter instance. Single entry point — handles everything:
     * builtin TYPE, custom registered type, FQCN, and null (→ DEFAULT_TYPE).
     */
    public function make(string|null $type, AskDnsConfig $config): AskDnsAdapterContract
    {
        $class = $this->resolve($type);

        if ($this->logger !== null && $config->useTls() && !$class::TLS_SUPPORTED) {
            $this->logger->warning("DNS adapter '{$type}' does not support TLS. Falling back to UDP.");
        }

        return $this->factory->create($class, $config);
    }

    /**
     * List all registered type strings. Useful for CLI help and documentation.
     *
     * @return list<string>
     */
    public function list(): array
    {
        $this->ensureBuiltins();

        return array_keys($this->adapters);
    }

    public function supportsTls(string $type): bool
    {
        $this->ensureBuiltins();

        $class = $this->adapters[$type] ?? null;

        return $class !== null && $class::TLS_SUPPORTED;
    }

    /** @return list<string> */
    public function types(): array
    {
        return $this->list();
    }

    private function ensureBuiltins(): void
    {
        if ($this->builtinsRegistered) {
            return;
        }
        foreach (self::$builtin as $type => $class) {
            if (!isset($this->adapters[$type])) {
                $this->adapters[$type] = $class;
            }
        }
        $this->builtinsRegistered = true;
    }

    private function validateClass(string $class): void
    {
        if (!class_exists($class) || !is_a($class, AskDnsAdapterContract::class, true)) {
            throw new AskConfigException(
                "DNS adapter class {$class} does not exist or does not implement "
                .AskDnsAdapterContract::class.'.',
            );
        }
    }
}