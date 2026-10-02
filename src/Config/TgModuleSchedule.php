<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Config;

/**
 * Declarative scheduler entry for one module (config/tg_modules.php):
 * replaces the legacy telegram.modules_schedule Config::set side-channel
 * that module providers pushed at boot.
 *
 * User-level overrides (expression / disabled) are applied by the engine
 * from config/schedule-overrides.php, keyed by command name — same contract
 * the retired host ModuleTaskScheduler served.
 */
final readonly class TgModuleSchedule
{
    public function __construct(
        public string $command,
        public string $expression,
        public bool $enabled = true,
    ) {
    }
}
