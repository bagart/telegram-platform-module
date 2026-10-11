<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Console;

use BAGArt\TelegramBot\Modules\TgModuleCapability;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use Illuminate\Console\Command;

final class TgModulesListCommand extends Command
{
    protected $signature = 'tg:modules:list
        {--format=text : Output format: text or json}
        {--json : Shorthand for --format=json}';

    protected $description = 'List modules registered in the Telegram Module Engine registry';

    public function handle(): int
    {
        $result = $this->laravel->make(ModuleRegistryBuilder::class)->build();

        if ($this->option('json') || $this->option('format') === 'json') {
            $this->output->writeln(json_encode(array_map(
                static fn (TgModuleDefinition $definition): array => [
                    'id' => $definition->id(),
                    'name' => $definition->descriptor->name,
                    'version' => $definition->descriptor->version,
                    'capabilities' => array_map(
                        static fn (TgModuleCapability $capability): string => $capability->value,
                        $definition->descriptor->capabilities,
                    ),
                    'defaultEnabled' => $definition->descriptor->defaultEnabled,
                    'requiresModules' => $definition->descriptor->requiresModules,
                    'conflictsWith' => $definition->descriptor->conflictsWith,
                ],
                array_values($result->registry->all()),
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

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
