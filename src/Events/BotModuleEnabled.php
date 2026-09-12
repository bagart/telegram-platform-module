<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a module is enabled for a bot via ModuleActivationService.
 */
final class BotModuleEnabled
{
    use Dispatchable;

    public function __construct(
        public readonly string $botId,
        public readonly string $moduleId,
        public readonly int $revision,
    ) {}
}
