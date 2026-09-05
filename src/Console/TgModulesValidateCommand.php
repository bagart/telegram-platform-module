<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Console;

use BAGArt\TelegramModuleEngine\Registry\CommandSignatureInspector;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use BAGArt\TelegramModuleEngine\Registry\RegistryErrorCode;
use Illuminate\Console\Command;

final class TgModulesValidateCommand extends Command
{
    protected $signature = 'tg:modules:validate';

    protected $description = 'Validate config/tg_modules.php and the resolved module registry';

    public function handle(): int
    {
        $result = $this->laravel->make(ModuleRegistryBuilder::class)->build();

        $errors = [];
        foreach ($result->errors as $error) {
            $errors[] = [$error->code->value, $error->moduleKey, $error->message];
        }

        $collisions = CommandSignatureInspector::collisions($result->registry);
        foreach ($collisions as $collision) {
            $errors[] = [
                RegistryErrorCode::CommandSignatureCollision->value,
                '*',
                sprintf(
                    'duplicate Artisan command signature "%s" (%s vs %s)',
                    $collision['signature'],
                    $collision['first'],
                    $collision['second'],
                ),
            ];
        }

        if ($errors === []) {
            $this->info(sprintf(
                'Module registry OK: %d module(s) validated.',
                $result->registry->count(),
            ));

            return self::SUCCESS;
        }

        foreach ($errors as [$code, $moduleKey, $message]) {
            $this->error(sprintf('[%s] %s: %s', $code, $moduleKey, $message));
        }

        $this->error(sprintf(
            'Module registry INVALID: %d error(s), %d valid module(s).',
            count($errors),
            $result->registry->count(),
        ));

        return self::FAILURE;
    }
}
