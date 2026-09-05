<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Console;

use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use Illuminate\Console\Command;

final class TgModulesListCommand extends Command
{
    protected $signature = 'tg:modules:list';

    protected $description = 'List modules registered in the Telegram Module Engine registry';

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
            $rows[] = [
                $definition->id(),
                $definition->descriptor->version,
                $definition->enabled ? 'enabled' : 'disabled',
                $definition->provider,
                (string) count($definition->commands),
            ];
        }

        $this->table(['Module', 'Version', 'Policy', 'Provider', 'Commands'], $rows);

        return self::SUCCESS;
    }
}
