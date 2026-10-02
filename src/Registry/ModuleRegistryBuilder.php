<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;
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
    ) {
    }

    /**
     * @throws RegistryValidationException in strict mode when any entry is invalid
     */
    public function build(): RegistryResult
    {
        $strict = (bool) ($this->config['strict'] ?? false);
        $errors = [];
        $definitions = [];
        $routeKeys = [];

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

            $laravelProviderError = $this->firstInvalidLaravelProvider($moduleKey, $entry->laravelProvider);
            if ($laravelProviderError !== null) {
                $errors[] = $laravelProviderError;

                continue;
            }

            $classStringError = $this->firstInvalidClassString($moduleKey, $entry);
            if ($classStringError !== null) {
                $errors[] = $classStringError;

                continue;
            }

            $routeError = $this->firstDuplicateRoute($moduleKey, $entry->routes, $routeKeys);
            if ($routeError !== null) {
                $errors[] = $routeError;
            }

            // duplicate ids are impossible by construction: config keys are
            // unique (PHP array) and the key==descriptor-id invariant is
            // enforced above, so one id maps to exactly one entry.

            $resolvedHttpRoutes = $this->resolvePaths($entry->httpRoutes, $entry->sourcePath);
            $resolvedFrontendPages = $this->resolvePaths($entry->frontendPages, $entry->sourcePath);

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
                httpRoutes: $resolvedHttpRoutes,
                routeMiddleware: $entry->routeMiddleware,
                exceptionRenderables: $entry->exceptionRenderables,
                frontendPages: $resolvedFrontendPages,
                pageGenerators: $entry->pageGenerators,
                settingsScreens: $entry->settingsScreens,
                sourcePath: $entry->sourcePath,
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

    private function firstInvalidLaravelProvider(string $moduleKey, ?string $laravelProvider): ?RegistryError
    {
        if ($laravelProvider === null) {
            return null;
        }

        if (! class_exists($laravelProvider)) {
            return new RegistryError(
                RegistryErrorCode::LaravelProviderInvalid,
                $moduleKey,
                sprintf('laravelProvider class "%s" does not exist', $laravelProvider),
            );
        }

        if (! is_a($laravelProvider, ServiceProvider::class, true)) {
            return new RegistryError(
                RegistryErrorCode::LaravelProviderInvalid,
                $moduleKey,
                sprintf('laravelProvider "%s" does not extend %s', $laravelProvider, ServiceProvider::class),
            );
        }

        return null;
    }

    private function firstInvalidClassString(string $moduleKey, TgModuleConfig $entry): ?RegistryError
    {
        foreach ($entry->seeders as $seeder) {
            if (! is_string($seeder) || ! class_exists($seeder)) {
                return new RegistryError(
                    RegistryErrorCode::ClassStringInvalid,
                    $moduleKey,
                    sprintf('seeder class "%s" does not exist', $seeder),
                );
            }
        }

        $resolvedHttpRoutes = $this->resolvePaths($entry->httpRoutes, $entry->sourcePath);
        foreach ($resolvedHttpRoutes as $routePath) {
            if (! is_string($routePath) || $routePath === '' || ! is_file($routePath)) {
                return new RegistryError(
                    RegistryErrorCode::ClassStringInvalid,
                    $moduleKey,
                    sprintf('httpRoutes path "%s" is not a valid file', $routePath),
                );
            }
        }

        $resolvedFrontendPages = $this->resolvePaths($entry->frontendPages, $entry->sourcePath);
        foreach ($resolvedFrontendPages as $pagePath) {
            if (! is_string($pagePath) || $pagePath === '' || ! is_dir($pagePath)) {
                return new RegistryError(
                    RegistryErrorCode::ClassStringInvalid,
                    $moduleKey,
                    sprintf('frontendPages path "%s" is not a valid directory', $pagePath),
                );
            }
        }

        return null;
    }

    /**
     * Resolve filesystem paths against a module's sourcePath.
     *
     * Absolute paths are returned as-is. Relative paths are resolved
     * relative to $sourcePath. When sourcePath is null, relative paths
     * are returned unchanged (caller must handle).
     *
     * @param  list<string>  $paths
     * @param  string|null  $sourcePath
     * @return list<string>
     */
    private function resolvePaths(array $paths, ?string $sourcePath): array
    {
        if ($sourcePath === null) {
            return $paths;
        }

        $resolved = [];
        foreach ($paths as $path) {
            if ($path !== '' && ! $this->isAbsolutePath($path)) {
                $path = rtrim($sourcePath, '/\\').'/'.$path;
            }
            $resolved[] = $path;
        }

        return $resolved;
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path[0] === '/' || $path[0] === '\\'
            || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');
    }

    /**
     * @param  array<int, array{type: string, key: string}>  $routes
     * @param  array<string, string>  $routeKeys  populated in-place with "type|key" => moduleKey
     */
    private function firstDuplicateRoute(string $moduleKey, array $routes, array &$routeKeys): ?RegistryError
    {
        foreach ($routes as $route) {
            $compositeKey = $route->type.'|'.$route->key;
            if (isset($routeKeys[$compositeKey])) {
                return new RegistryError(
                    RegistryErrorCode::DuplicateRouteEntry,
                    $moduleKey,
                    sprintf(
                        'duplicate route entry (type="%s", key="%s") already declared by module "%s"',
                        $route->type,
                        $route->key,
                        $routeKeys[$compositeKey],
                    ),
                );
            }
            $routeKeys[$compositeKey] = $moduleKey;
        }

        return null;
    }
}
