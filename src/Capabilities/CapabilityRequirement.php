<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Capabilities;

/**
 * Capability dependency contract: a module needs capability X to run. This is
 * distinct from a module dependency (requiresModules) and from infrastructure
 * requirements — the three contracts never mix (16 §52).
 */
final readonly class CapabilityRequirement
{
    public function __construct(
        public string $capabilityId,
        /** Missing optional capability degrades the module to reduced mode instead of blocking it (16 §76). */
        public bool $optional = false,
    ) {
    }
}
