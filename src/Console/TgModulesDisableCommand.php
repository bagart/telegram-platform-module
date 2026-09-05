<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Console;

use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use Illuminate\Console\Command;

/**
 * Disable counterpart of tg:modules:enable.
 */
final class TgModulesDisableCommand extends Command
{
    protected $signature = 'tg:modules:disable {botId : Bot id} {moduleId : Module id from config/tg_modules.php} {--revision= : Expected revision for optimistic locking}';

    protected $description = 'Disable a module for a specific bot (engine activation store)';

    public function handle(ModuleActivationService $activations): int
    {
        return TgModulesEnableCommand::report(
            $this->output,
            $activations->disable(
                botId: (string) $this->argument('botId'),
                moduleId: (string) $this->argument('moduleId'),
                expectedRevision: $this->option('revision') !== null ? (int) $this->option('revision') : null,
            ),
        );
    }
}
