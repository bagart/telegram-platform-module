<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;

/**
 * Dependency-safe Laravel-provider boot sequence for platform-enabled
 * modules (doc 08 §20): a module's provider is registered only after the
 * providers of the modules it declares in descriptor requiresModules.
 * Unknown/unregistered dependencies are ignored for ordering (the registry
 * builder reports them separately). Pure component, no container access.
 */
final readonly class ProviderSequence
{
    /** @var list<class-string> missing dependency ids that were warned about */
    public array $missingDependencies;

    private const WHITE = 0;
    private const GRAY = 1;
    private const BLACK = 2;

    public function __construct(
        private EngineModuleRegistry $registry,
    ) {
        $this->missingDependencies = $this->detectMissingDependencies();
    }

    /**
     * @return list<class-string> Laravel provider class-strings, dependencies first
     * @throws CyclicDependencyException when a dependency cycle is detected
     */
    public function laravelProviders(): array
    {
        $ordered = [];
        $color = [];

        foreach ($this->registry->all() as $definition) {
            if ($definition->enabled) {
                $this->visit($definition, $color, $ordered);
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string, int>  $color
     * @param  list<class-string>  $ordered
     */
    private function visit(TgModuleDefinition $definition, array &$color, array &$ordered): void
    {
        $id = $definition->id();

        $currentState = $color[$id] ?? self::WHITE;
        if ($currentState === self::BLACK) {
            return;
        }

        if ($currentState === self::GRAY) {
            throw CyclicDependencyException::detected($id);
        }

        $color[$id] = self::GRAY;

        foreach (array_keys($definition->descriptor->requiresModules) as $dependencyId) {
            $dependency = $this->registry->get((string) $dependencyId);
            if ($dependency !== null && $dependency->enabled) {
                $this->visit($dependency, $color, $ordered);
            }
        }

        $color[$id] = self::BLACK;

        if ($definition->laravelProvider !== null) {
            $ordered[] = $definition->laravelProvider;
        }
    }

    /**
     * @return list<string> dependency ids that are required but not registered or enabled
     */
    private function detectMissingDependencies(): array
    {
        $missing = [];
        foreach ($this->registry->enabled() as $definition) {
            foreach (array_keys($definition->descriptor->requiresModules) as $dependencyId) {
                $depId = (string) $dependencyId;
                $dependency = $this->registry->get($depId);
                if ($dependency === null || ! $dependency->enabled) {
                    $missing[] = $depId;
                }
            }
        }

        return array_values(array_unique($missing));
    }
}
