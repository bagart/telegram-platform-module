<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use Illuminate\Console\Command;
use Throwable;

/**
 * Builds the engine module registry from the config/tg_modules.php policy.
 *
 * Pure component: takes the raw config array, calls TgModuleContract::descriptor()
 * (metadata only, no registration side effects) and validates every entry.
 * Strict mode = fail-fast: the first error throws RegistryValidationException
 * (REQUIRED_PLATFORM semantics). Degraded mode collects errors and registers
 * all valid modules so the platform can boot with a partially broken config.
 */
final readonly class ModuleRegistryBuilder
{
    /**
     * @param  array<string, mixed>  $config  content of config/tg_modules.php
     */
    public function __construct(
        private array $config,
    ) {}

    /**
     * @throws RegistryValidationException in strict mode when any entry is invalid
     */
    public function build(): RegistryResult
    {
        $strict = (bool) ($this->config['strict'] ?? false);
        $errors = [];
        $definitions = [];

        foreach ((array) ($this->config['modules'] ?? []) as $moduleKey => $entry) {
            $moduleKey = (string) $moduleKey;

            if (! $entry instanceof TgModuleConfig) {
                $errors[] = new RegistryError(
                    RegistryErrorCode::InvalidEntry,
                    $moduleKey,
                    'config entry must be a TgModuleConfig DTO',
                );

                continue;
            }

            if (! class_exists($entry->provider)) {
                $errors[] = new RegistryError(
                    RegistryErrorCode::ProviderMissing,
                    $moduleKey,
                    sprintf('provider class "%s" does not exist', $entry->provider),
                );

                continue;
            }

            if (! is_a($entry->provider, TgModuleContract::class, true)) {
                $errors[] = new RegistryError(
                    RegistryErrorCode::ProviderNotContract,
                    $moduleKey,
                    sprintf('provider "%s" does not implement TgModuleContract', $entry->provider),
                );

                continue;
            }

            try {
                $descriptor = $entry->provider::descriptor();
            } catch (Throwable $e) {
                $errors[] = new RegistryError(
                    RegistryErrorCode::DescriptorFailed,
                    $moduleKey,
                    sprintf('descriptor() threw %s: %s', $e::class, $e->getMessage()),
                );

                continue;
            }

            if ($descriptor->id !== $moduleKey) {
                $errors[] = new RegistryError(
                    RegistryErrorCode::IdMismatch,
                    $moduleKey,
                    sprintf('config key "%s" does not match descriptor id "%s"', $moduleKey, $descriptor->id),
                );

                continue;
            }

            $commandError = $this->firstInvalidCommand($moduleKey, $entry->commands);
            if ($commandError !== null) {
                $errors[] = $commandError;

                continue;
            }

            // duplicate ids are impossible by construction: config keys are
            // unique (PHP array) and the key==descriptor-id invariant is
            // enforced above, so one id maps to exactly one entry.

            $definitions[] = new TgModuleDefinition(
                configKey: $moduleKey,
                provider: $entry->provider,
                descriptor: $descriptor,
                enabled: $entry->enabled,
                seeders: $entry->seeders,
                laravelProvider: $entry->laravelProvider,
                routes: $entry->routes,
                commands: $entry->commands,
                schedule: $entry->schedule,
                httpRoutes: $entry->httpRoutes,
                routeMiddleware: $entry->routeMiddleware,
                exceptionRenderables: $entry->exceptionRenderables,
                frontendPages: $entry->frontendPages,
                pageGenerators: $entry->pageGenerators,
                settingsScreens: $entry->settingsScreens,
            );
        }

        if ($strict && $errors !== []) {
            throw new RegistryValidationException($errors);
        }

        return new RegistryResult(new EngineModuleRegistry($definitions), $errors);
    }

    /**
     * @param  list<class-string>  $commands
     */
    private function firstInvalidCommand(string $moduleKey, array $commands): ?RegistryError
    {
        foreach ($commands as $command) {
            if (! is_string($command) || ! class_exists($command)) {
                return new RegistryError(
                    RegistryErrorCode::CommandMissing,
                    $moduleKey,
                    sprintf('command class "%s" does not exist', $command),
                );
            }

            if (! is_a($command, Command::class, true)) {
                return new RegistryError(
                    RegistryErrorCode::CommandNotCommand,
                    $moduleKey,
                    sprintf('command "%s" does not extend %s', $command, Command::class),
                );
            }
        }

        return null;
    }
}
