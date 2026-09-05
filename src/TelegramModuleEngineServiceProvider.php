<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine;

use BAGArt\TelegramBot\Contracts\Modules\CommandRouteContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Modules\ModuleBootloader;
use BAGArt\TelegramModuleEngine\Activation\EngineModuleEnablement;
use BAGArt\TelegramModuleEngine\Diagnostics\EngineMetrics;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use BAGArt\TelegramModuleEngine\Console\TgModulesDiagnoseCommand;
use BAGArt\TelegramModuleEngine\Console\TgModulesDisableCommand;
use BAGArt\TelegramModuleEngine\Console\TgModulesEnableCommand;
use BAGArt\TelegramModuleEngine\Console\TgModulesListCommand;
use BAGArt\TelegramModuleEngine\Console\TgModulesRoutesCheckCommand;
use BAGArt\TelegramModuleEngine\Console\TgModulesRoutesSyncCommand;
use BAGArt\TelegramModuleEngine\Console\TgModulesValidateCommand;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Registry\CommandSignatureInspector;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use BAGArt\TelegramModuleEngine\Registry\RegistryErrorCode;
use BAGArt\TelegramModuleEngine\Registry\RegistryError;
use BAGArt\TelegramModuleEngine\Registry\RegistryValidationException;
use BAGArt\TelegramModuleEngine\Registry\ProviderSequence;
use BAGArt\TelegramModuleEngine\Routing\CommandRouteLookup;
use BAGArt\TelegramModuleEngine\Routing\PgRouteResolver;
use BAGArt\TelegramModuleEngine\Routing\RouteResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Engine bootstrap (docs/architecture/07-mvp-roadmap.md, phases 1-2):
 * builds the module registry from config/tg_modules.php and takes over
 * module booting from TelegramBotServiceProvider::bootModules() (the lib
 * steps aside when config('tg_modules') is present). Legacy
 * telegram.modules / telegram.modules_providers sources are consumed as
 * deprecated aliases. Phase 2 adds bot-scoped activation (lifecycle ops)
 * and the PG routing table behind the RouteResolver contract. Diagnostics
 * CLI: tg:modules:list|validate.
 */
final class TelegramModuleEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ModuleRegistryBuilder::class,
            static fn (Application $app): ModuleRegistryBuilder => new ModuleRegistryBuilder(
                (array) $app->make('config')->get('tg_modules', []),
            ),
        );

        $this->app->singleton(
            EngineModuleRegistry::class,
            static fn (Application $app): EngineModuleRegistry => $app->make(ModuleRegistryBuilder::class)->build()->registry,
        );

        $this->app->singleton(
            ModuleActivationReader::class,
            static fn (Application $app): ModuleActivationReader => new ModuleActivationReader(
                $app->make(ConnectionResolverInterface::class)->connection(),
                $app->make(EngineModuleRegistry::class),
            ),
        );

        $this->app->singleton(EngineMetrics::class);

        $this->app->singleton(
            ModuleActivationService::class,
            static fn (Application $app): ModuleActivationService => new ModuleActivationService(
                $app->make(ConnectionResolverInterface::class)->connection(),
                $app->make(EngineModuleRegistry::class),
                $app->make(ModuleActivationReader::class),
                $app->make(EngineMetrics::class),
            ),
        );

        $this->app->singleton(
            RouteResolver::class,
            static fn (Application $app): PgRouteResolver => new PgRouteResolver(
                $app->make(ConnectionResolverInterface::class)->connection(),
                $app->make(ModuleActivationReader::class),
                'bot_module_routes',
                $app->make(EngineMetrics::class),
            ),
        );

        // Dispatch integration: the lib update selector consults this
        // (guarded by container binding, same pattern as enablement) so
        // declared bot routes override the flat command registry.
        $this->app->singleton(
            CommandRouteContract::class,
            static fn (Application $app): CommandRouteLookup => new CommandRouteLookup(
                $app->make(RouteResolver::class),
            ),
        );

        $this->registerModuleLaravelProviders();

        $this->registerModuleCommands();
    }

    /**
     * Declarative Artisan command passthrough: registers the `commands`
     * contributions of platform-enabled modules (config/tg_modules.php).
     * Degraded mode isolates a broken registry build (logged, skipped);
     * strict mode fails platform boot.
     */
    private function registerModuleCommands(): void
    {
        try {
            $registry = $this->app->make(EngineModuleRegistry::class);
        } catch (RegistryValidationException $e) {
            if ($this->app->make('config')->get('tg_modules.strict', false)) {
                throw $e;
            }

            return;
        }

        $collisions = CommandSignatureInspector::collisions($registry);
        if ($collisions !== []) {
            $message = sprintf(
                'tg-modules: duplicate Artisan command signature(s) declared by modules: %s',
                implode('; ', array_map(
                    static fn (array $c): string => sprintf('"%s" (%s vs %s)', $c['signature'], $c['first'], $c['second']),
                    $collisions,
                )),
            );

            if ($this->app->make('config')->get('tg_modules.strict', false)) {
                throw new RegistryValidationException([new RegistryError(
                    RegistryErrorCode::CommandSignatureCollision,
                    '*',
                    $message,
                )]);
            }

            $this->app->make('log')->error($message);
        }

        $commands = $registry->commands();
        if ($commands !== []) {
            $this->commands($commands);
        }
    }

    /**
     * Bootstrap takeover (phase 3, doc 08 §20-21): registers each
     * platform-enabled module's Laravel ServiceProvider in dependency order
     * (descriptor requiresModules first). Degraded mode isolates a broken
     * provider (logged, skipped); strict mode fails platform boot.
     */
    private function registerModuleLaravelProviders(): void
    {
        $registry = $this->app->make(EngineModuleRegistry::class);
        $providers = (new ProviderSequence($registry))->laravelProviders();

        foreach ($providers as $providerClass) {
            try {
                $this->app->register($providerClass);
            } catch (Throwable $e) {
                if ($this->app->make('config')->get('tg_modules.strict', false)) {
                    throw $e;
                }

                $this->app->make('log')->error('tg-modules: module Laravel provider failed to register, module skipped', [
                    'provider' => $providerClass,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tg_modules.php' => config_path('tg_modules.php'),
            ], 'tg-modules-config');
        }

        $this->commands([
            TgModulesListCommand::class,
            TgModulesValidateCommand::class,
            TgModulesDiagnoseCommand::class,
            TgModulesEnableCommand::class,
            TgModulesDisableCommand::class,
            TgModulesRoutesSyncCommand::class,
            TgModulesRoutesCheckCommand::class,
        ]);

        $this->bindDispatchEnablement();

        $this->registerDeclarations();

        $this->bootModules();
    }

    /**
     * Declarative host-integration contributions of platform-enabled modules
     * (config/tg_modules.php): HTTP route files, router middleware aliases,
     * exception handler renderables and scheduler entries (with
     * schedule-overrides.php user overrides applied). Replaces the legacy
     * per-module Config::set side-channels (telegram.modules_schedule,
     * modules_frontend_pages, modules_page_generators). Degraded mode
     * isolates a broken registry build (logged, skipped); strict mode fails
     * platform boot.
     */
    private function registerDeclarations(): void
    {
        try {
            $registry = $this->app->make(EngineModuleRegistry::class);
        } catch (RegistryValidationException $e) {
            if ($this->app->make('config')->get('tg_modules.strict', false)) {
                throw $e;
            }

            return;
        }

        foreach ($registry->httpRoutes() as $routeFile) {
            if (is_file($routeFile)) {
                $this->loadRoutesFrom($routeFile);
            } else {
                $this->app->make('log')->warning('tg-modules: declared HTTP route file missing, skipped', [
                    'path' => $routeFile,
                ]);
            }
        }

        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app->make(\Illuminate\Routing\Router::class);
        foreach ($registry->routeMiddleware() as $alias => $class) {
            $router->aliasMiddleware($alias, $class);
        }

        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        foreach ($registry->exceptionRenderables() as $renderable) {
            // class-string entries resolve through the container (invokable
            // renderable classes); callables are used as-is.
            $handler->renderable(is_string($renderable) ? $this->app->make($renderable) : $renderable);
        }

        // Interchange keys consumed by engine-agnostic host tooling:
        // `modules:pages` (page generators) and the menu module's
        // `menu:pages` (frontend page source dirs). The engine is the SOLE
        // producer — module providers never Config::set these keys again.
        $config = $this->app->make('config');
        $config->set('telegram.modules_frontend_pages', $registry->frontendPages());
        $config->set('telegram.modules_page_generators', $registry->pageGenerators());

        $this->app->booted(function () use ($registry): void {
            $this->registerSchedule($registry);
        });
    }

    /**
     * Registers module scheduler entries on the console Schedule (see
     * ModuleScheduleRegistrar for the override contract).
     */
    private function registerSchedule(EngineModuleRegistry $registry): void
    {
        $registrar = new \BAGArt\TelegramModuleEngine\Schedule\ModuleScheduleRegistrar(
            $this->app->make(\Illuminate\Console\Scheduling\Schedule::class),
            $registry,
            (array) $this->app->make('config')->get('schedule-overrides', []),
        );
        $registrar->register();
    }

    /**
     * Dispatch integration (doc 05 decision: the engine is a dumb proxy in
     * the request path). tg_modules.enablement_driver selects who answers the
     * lib dispatch contract: 'legacy' (default) keeps the management service
     * over tg_module_enablements; 'engine' swaps in the engine adapter over
     * bot_module_activations.
     */
    private function bindDispatchEnablement(): void
    {
        if ($this->app->make('config')->get('tg_modules.enablement_driver', 'legacy') !== 'engine') {
            return;
        }

        $this->app->singleton(ModuleEnablementContract::class, static fn (Application $app): EngineModuleEnablement => new EngineModuleEnablement(
            $app->make(ModuleActivationReader::class),
        ));
    }

    /**
     * Boots module entry points through the lib ModuleBootloader (per-module
     * fault isolation): platform-enabled registry providers first, then the
     * deprecated telegram.modules* alias sources.
     */
    private function bootModules(): void
    {
        try {
            $registry = $this->app->make(EngineModuleRegistry::class);
        } catch (RegistryValidationException $e) {
            $this->app->make('log')->error('tg-modules: strict registry validation failed, module boot aborted', [
                'errors' => $e->errors(),
            ]);

            throw $e;
        }

        $providers = [];
        foreach ($registry->enabled() as $definition) {
            $providers[$definition->provider] = true;
        }

        foreach ($this->deprecatedAliasProviders() as $providerClass) {
            if (! isset($providers[$providerClass])) {
                $providers[$providerClass] = true;
            }
        }

        if ($providers === []) {
            return;
        }

        /** @var ModuleBootloader $bootloader */
        $bootloader = $this->app->make(ModuleBootloader::class);
        $bootloader->bootAll(array_keys($providers));

        $failed = $bootloader->failed();
        if ($failed !== []) {
            $this->app->make('log')->error('tg-modules: module registration failed, modules skipped (details in lib log)', [
                'modules' => array_values($failed),
            ]);
        }
    }

    /**
     * Legacy discovery sources (config('telegram.modules') entries with a
     * 'provider' key + config('telegram.modules_providers') class-strings).
     * Returns [] on the target end-state; any result is logged as deprecated.
     *
     * @return list<class-string>
     */
    private function deprecatedAliasProviders(): array
    {
        $legacy = [];

        foreach ((array) $this->app->make('config')->get('telegram.modules', []) as $moduleConfig) {
            if (is_array($moduleConfig)
                && isset($moduleConfig['provider'])
                && is_string($moduleConfig['provider'])
            ) {
                $legacy[] = $moduleConfig['provider'];
            }
        }

        foreach ((array) $this->app->make('config')->get('telegram.modules_providers', []) as $providerClass) {
            if (is_string($providerClass)) {
                $legacy[] = $providerClass;
            }
        }

        return array_values(array_unique($legacy));
    }

    private function logBootSummary(): void
    {
        try {
            $result = $this->app->make(ModuleRegistryBuilder::class)->build();
        } catch (\Throwable $e) {
            $this->app->make('log')->error('tg-modules: registry build failed in strict mode', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($result->errors as $error) {
            $this->app->make('log')->warning('tg-modules: registry validation error', $error->toArray());
        }

        $this->app->make('log')->info('tg-modules: registry built', [
            'modules' => $result->registry->count(),
            'enabled' => count($result->registry->enabled()),
            'errors' => count($result->errors),
        ]);
    }
}
