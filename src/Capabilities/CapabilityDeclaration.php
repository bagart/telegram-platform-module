<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Capabilities;

use BAGArt\TelegramBot\Modules\TgModuleCapability;

/**
 * One capability a module declares it provides. Immutable declaration only —
 * the registry never executes a capability (16 §8-10).
 */
final readonly class CapabilityDeclaration
{
    public function __construct(
        public string $moduleId,
        /** Stable capability id, e.g. "mafia.menu" or "llm.text-generation" (16 §7). */
        public string $capabilityId,
        public TgModuleCapability $kind,
        /** Exclusive capabilities may be claimed by at most one active module (16 §61). */
        public bool $exclusive = false,
    ) {
    }
}
