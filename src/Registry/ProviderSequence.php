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
    public function __construct(
        private EngineModuleRegistry $registry,
    ) {}

    /**
     * @return list<class-string> Laravel provider class-strings, dependencies first
     */
    public function laravelProviders(): array
    {
        $ordered = [];
        $visited = [];

        foreach ($this->registry->all() as $definition) {
            if ($definition->enabled) {
                $this->visit($definition, $visited, $ordered);
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string, bool>  $visited
     * @param  list<class-string>  $ordered
     */
    private function visit(TgModuleDefinition $definition, array &$visited, array &$ordered): void
    {
        if (isset($visited[$definition->id()])) {
            return;
        }
        $visited[$definition->id()] = true;

        foreach (array_keys($definition->descriptor->requiresModules) as $dependencyId) {
            $dependency = $this->registry->get((string) $dependencyId);
            if ($dependency !== null && $dependency->enabled) {
                $this->visit($dependency, $visited, $ordered);
            }
        }

        if ($definition->laravelProvider !== null) {
            $ordered[] = $definition->laravelProvider;
        }
    }
}
