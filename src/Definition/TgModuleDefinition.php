<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Definition;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;

/**
 * Resolved, immutable view of one module: its descriptor (module-owned
 * metadata) joined with platform policy (config/tg_modules.php). Built only
 * by the registry builder; carries no behavior and performs no I/O.
 */
final readonly class TgModuleDefinition
{
    /**
     * @param  string  $configKey  exact key from config/tg_modules.php (== descriptor id)
     * @param  class-string<TgModuleContract>  $provider
     * @param  list<class-string>  $seeders  declarative seed data (TgModuleConfig::$seeders)
     * @param  class-string|null  $laravelProvider  module's Laravel ServiceProvider (TgModuleConfig::$laravelProvider)
     * @param  list<\BAGArt\TelegramModuleEngine\Routing\RouteDeclaration>  $routes  declarative routing contributions
     * @param  list<class-string<\Illuminate\Console\Command>>  $commands  declarative Artisan commands (TgModuleConfig::$commands)
     * @param  list<\BAGArt\TelegramModuleEngine\Config\TgModuleSchedule>  $schedule  declarative scheduler entries
     * @param  list<string>  $httpRoutes  absolute HTTP route-file paths the engine loads
     * @param  array<string, class-string>  $routeMiddleware  router middleware aliases (alias => class)
     * @param  list<class-string|callable>  $exceptionRenderables  exception handler renderables
     * @param  list<string>  $frontendPages  absolute Inertia page source dirs
     * @param  list<string>  $pageGenerators  Artisan command names for the host modules:pages shim
     */
    public function __construct(
        public string $configKey,
        public string $provider,
        public TgModuleDescriptor $descriptor,
        public bool $enabled,
        public array $seeders = [],
        public ?string $laravelProvider = null,
        public array $routes = [],
        public array $commands = [],
        public array $schedule = [],
        public array $httpRoutes = [],
        public array $routeMiddleware = [],
        public array $exceptionRenderables = [],
        public array $frontendPages = [],
        public array $pageGenerators = [],
    ) {}

    public function id(): string
    {
        return $this->descriptor->id;
    }
}
