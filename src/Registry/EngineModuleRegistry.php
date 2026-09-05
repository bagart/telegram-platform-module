<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;

/**
 * Immutable in-memory registry of module definitions keyed by module id.
 * No mutators by design: the registry is rebuilt, never patched.
 */
final class EngineModuleRegistry
{
    /** @var array<string, TgModuleDefinition> */
    private array $definitions;

    /** @param  list<TgModuleDefinition>  $definitions */
    public function __construct(array $definitions)
    {
        $byId = [];
        foreach ($definitions as $definition) {
            $byId[$definition->id()] = $definition;
        }
        ksort($byId);
        $this->definitions = $byId;
    }

    public function get(string $moduleId): ?TgModuleDefinition
    {
        return $this->definitions[$moduleId] ?? null;
    }

    /** @return array<string, TgModuleDefinition> id => definition, sorted by id */
    public function all(): array
    {
        return $this->definitions;
    }

    /** @return array<string, TgModuleDefinition> platform-enabled modules only */
    public function enabled(): array
    {
        return array_filter(
            $this->definitions,
            static fn (TgModuleDefinition $d): bool => $d->enabled,
        );
    }

    public function count(): int
    {
        return count($this->definitions);
    }

    /**
     * Seeder class-strings declared by platform-enabled modules, deduplicated,
     * in module-id order. Consumed by the host DatabaseSeeder.
     *
     * @return list<class-string>
     */
    public function seeders(): array
    {
        $seeders = [];
        foreach ($this->enabled() as $definition) {
            foreach ($definition->seeders as $seeder) {
                $seeders[$seeder] = true;
            }
        }

        return array_keys($seeders);
    }

    /**
     * Artisan command class-strings declared by platform-enabled modules,
     * deduplicated, in module-id order. Consumed by the engine service
     * provider for console registration.
     *
     * @return list<class-string>
     */
    public function commands(): array
    {
        $commands = [];
        foreach ($this->enabled() as $definition) {
            foreach ($definition->commands as $command) {
                $commands[$command] = true;
            }
        }

        return array_keys($commands);
    }

    /**
     * Scheduler entries declared by platform-enabled modules, keyed by module
     * id (module-id order). Consumed by the engine schedule registrar, which
     * applies config/schedule-overrides.php user overrides.
     *
     * @return array<string, list<\BAGArt\TelegramModuleEngine\Config\TgModuleSchedule>>
     */
    public function scheduleEntries(): array
    {
        $entries = [];
        foreach ($this->enabled() as $definition) {
            if ($definition->schedule !== []) {
                $entries[$definition->id()] = $definition->schedule;
            }
        }

        return $entries;
    }

    /**
     * Absolute HTTP route-file paths declared by platform-enabled modules,
     * deduplicated, in module-id order. Loaded by the engine service provider.
     *
     * @return list<string>
     */
    public function httpRoutes(): array
    {
        return $this->dedup(static fn (TgModuleDefinition $d): array => $d->httpRoutes);
    }

    /**
     * Router middleware aliases (alias => class) declared by platform-enabled
     * modules, in module-id order. Registered by the engine service provider.
     *
     * @return array<string, class-string>
     */
    public function routeMiddleware(): array
    {
        $aliases = [];
        foreach ($this->enabled() as $definition) {
            foreach ($definition->routeMiddleware as $alias => $class) {
                $aliases[(string) $alias] = $class;
            }
        }

        return $aliases;
    }

    /**
     * Exception handler renderables declared by platform-enabled modules,
     * deduplicated, in module-id order.
     *
     * @return list<class-string|callable>
     */
    public function exceptionRenderables(): array
    {
        return $this->dedup(static fn (TgModuleDefinition $d): array => $d->exceptionRenderables);
    }

    /**
     * Absolute Inertia page source dirs declared by platform-enabled modules,
     * deduplicated, in module-id order. Consumed by the host page generator
     * (declarative replacement for telegram.modules_frontend_pages).
     *
     * @return list<string>
     */
    public function frontendPages(): array
    {
        return $this->dedup(static fn (TgModuleDefinition $d): array => $d->frontendPages);
    }

    /**
     * Artisan command names (page generators) declared by platform-enabled
     * modules, deduplicated, in module-id order. Consumed by the host
     * `modules:pages` shim (declarative replacement for
     * telegram.modules_page_generators).
     *
     * @return list<string>
     */
    public function pageGenerators(): array
    {
        return $this->dedup(static fn (TgModuleDefinition $d): array => $d->pageGenerators);
    }

    /**
     * @param  callable(TgModuleDefinition): list<string>  $extract
     * @return list<string>
     */
    private function dedup(callable $extract): array
    {
        $values = [];
        foreach ($this->enabled() as $definition) {
            foreach ($extract($definition) as $value) {
                $values[(string) $value] = true;
            }
        }

        return array_keys($values);
    }
}
