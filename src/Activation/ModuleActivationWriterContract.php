<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

/**
 * Writer-side activation contract for callers that only need to flip a
 * module's enabled flag (e.g. EngineSettingsWriter). Keeps ModuleActivationService
 * mockable behind a narrow interface instead of the final class.
 */
interface ModuleActivationWriterContract
{
    /**
     * Enable or disable a module for a bot. No-op when $botId is null
     * (platform-level scope has no activation row).
     */
    public function setEnabled(string $moduleId, ?string $botId, bool $enabled, ?string $actorId = null, ?string $actorType = null): void;
}
