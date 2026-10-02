<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Console;

use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use Illuminate\Console\Command;

/**
 * Shows the effective enabled/disabled state of every configured module,
 * including env var overrides that differ from the config-file default.
 */
final class TgModulesStatusCommand extends Command
{
    protected $signature = 'tg:modules:status';

    protected $description = 'Show effective module states with env override detection';

    public function handle(): int
    {
        $result = $this->laravel->make(ModuleRegistryBuilder::class)->build();

        foreach ($result->errors as $error) {
            $this->warn(sprintf('[%s] %s: %s', $error->code->value, $error->moduleKey, $error->message));
        }

        if ($result->registry->count() === 0) {
            $this->info('No modules configured (config/tg_modules.php).');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($result->registry->all() as $definition) {
            $configEnabled = $definition->enabled;
            $envKey = 'TG_MODULE_ENABLED_'.$definition->configKey;
            $envValue = getenv($envKey);

            if ($envValue !== false) {
                $envBool = filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
                $envOverride = $envValue;
                $effective = $envBool;
            } else {
                $envOverride = '-';
                $effective = $configEnabled;
            }

            $rows[] = [
                $definition->id(),
                $configEnabled ? 'true' : 'false',
                $envOverride,
                $effective ? 'enabled' : 'disabled',
            ];
        }

        $this->table(['Module', 'Config', 'Env Override', 'Effective State'], $rows);

        return self::SUCCESS;
    }
}
