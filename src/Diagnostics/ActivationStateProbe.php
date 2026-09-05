<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Diagnostics;

/**
 * Minimal read contract used by the diagnostics presenter to resolve the
 * effective activation state of one module for one bot. Exists so the
 * presenter can be unit tested without a database connection; in production
 * it is backed by Activation\ModuleActivationReader (which satisfies the
 * method signature).
 */
interface ActivationStateProbe
{
    /** Effective enablement of one module for one bot. */
    public function isEffectivelyEnabled(string $botId, string $moduleId): bool;
}
