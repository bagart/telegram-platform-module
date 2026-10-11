<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Console;

use BAGArt\TelegramModuleEngine\Registry\CallbackPrefixInspector;
use BAGArt\TelegramModuleEngine\Registry\CommandSignatureInspector;
use BAGArt\TelegramModuleEngine\Registry\DeclaredCommandInspector;
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
        $warnings = [];
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

        foreach (DeclaredCommandInspector::duplicates($result->registry) as $duplicate) {
            $errors[] = [
                DeclaredCommandInspector::ERROR_CODE,
                $duplicate['second'],
                sprintf(
                    'duplicate declared Telegram command "%s" (normalized "%s") already declared by module "%s"',
                    $duplicate['key'],
                    $duplicate['normalized'],
                    $duplicate['first'],
                ),
            ];
        }

        // Report-only by decision (Q7 wiring b): callback findings warn and
        // never enter $errors, so they cannot flip the exit code yet.
        foreach (CallbackPrefixInspector::violations($result->registry) as $violation) {
            $warnings[] = [
                RegistryErrorCode::CallbackPrefixInvalid->value,
                $violation['module'],
                sprintf(
                    'callback route key "%s" must be namespaced as "%s:<action>"',
                    $violation['key'],
                    $violation['module'],
                ),
            ];
        }

        foreach ($warnings as [$code, $moduleKey, $message]) {
            $this->warn(sprintf('[%s] %s: %s', $code, $moduleKey, $message));
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
