<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Config;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramModuleEngine\Settings\SettingsScreenContribution;

/**
 * Platform-level policy entry for one module in config/tg_modules.php.
 *
 * Pure immutable DTO (plan doc 32): declares platform policy only — the
 * module's Definition itself lives in the module package and is discovered
 * via TgModuleContract::descriptor(). The config key of the entry MUST equal
 * the descriptor id (enforced by the registry builder).
 *
 * The declarative component fields (schedule, HTTP routes, middleware
 * aliases, exception renderables, frontend page sources, page generator
 * commands, settings screens) replace the legacy Config::set side-channels
 * module providers used to push at boot (telegram.modules_schedule,
 * modules_frontend_pages, modules_page_generators) plus the providers'
 * own loadRoutesFrom() / aliasMiddleware() / renderable() registrations.
 */
final readonly class TgModuleConfig
{
    /**
     * @param  bool  $enabled  platform-level availability of the module
     *                         (bot-level activation is a separate, engine-owned concern)
     * @param  class-string<TgModuleContract>  $provider  module entry point
     * @param  list<class-string>  $seeders  module seed data run by the host
     *                             DatabaseSeeder on install (declarative replacement
     *                             for the legacy telegram.modules_seeders config push)
     * @param  class-string|null  $laravelProvider  the module's Laravel ServiceProvider,
     *                             registered by the engine in dependency order (phase 3
     *                             bootstrap takeover); null for contract-only modules
     * @param  list<\BAGArt\TelegramModuleEngine\Routing\RouteDeclaration>  $routes  declarative
     *                             routing-table contributions materialized per bot by RouteTableSync
     * @param  list<class-string<\Illuminate\Console\Command>>  $commands  module Artisan commands,
     *                             registered by the engine for platform-enabled modules
     *                             (declarative replacement for provider-level ->commands() pushes)
     * @param  list<TgModuleSchedule>  $schedule  scheduler entries registered by the engine
     * @param  list<string>  $httpRoutes  absolute paths of HTTP route files the engine loads
     * @param  array<string, class-string>  $routeMiddleware  router middleware aliases
     *                             (alias => class) registered by the engine
     * @param  list<class-string|callable>  $exceptionRenderables  exception handler
     *                             renderables registered by the engine
     * @param  list<string>  $frontendPages  absolute dirs of Inertia page sources the
     *                             host page generator globs (legacy modules_frontend_pages)
     * @param  list<string>  $pageGenerators  Artisan command names invoked by the host
     *                             `modules:pages` shim (legacy modules_page_generators)
     * @param  list<SettingsScreenContribution>  $settingsScreens  settings screen descriptors
     *                             contributed by this module; rendered by Management (web)
     *                             and Menu (Telegram); config-file-based storage
     */
    public function __construct(
        public bool $enabled,
        public string $provider,
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
        public array $settingsScreens = [],
    ) {}
}
