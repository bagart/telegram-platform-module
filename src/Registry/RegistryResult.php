<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

/**
 * Immutable outcome of building the module registry: the (possibly partial)
 * registry plus every validation error found. In degraded (non-strict) mode
 * valid modules are registered alongside collected errors; in strict mode
 * the builder throws before a result exists.
 */
final readonly class RegistryResult
{
    /**
     * @param  list<RegistryError>  $errors
     */
    public function __construct(
        public EngineModuleRegistry $registry,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
