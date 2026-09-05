<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Capabilities;

use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;

/**
 * Pure dependency resolver: read-only over the immutable static graph. It
 * answers "can the requirement be satisfied?" and produces structured,
 * human-readable "blocked because" reasons; it never activates, executes or
 * mutates anything (16 §106, §132 RESOLVER=READ).
 */
final class EffectiveDependencyResolver
{
    private StaticDependencyGraph $staticGraph;

    /**
     * @param  EngineModuleRegistry  $registry  discovery output (all modules + platform policy)
     * @param  ModuleCapabilityRegistry  $capabilities  declared capabilities per module
     * @param  array<string, list<CapabilityRequirement>>  $capabilityRequirements  moduleId => capability dependency contracts (module dependency lives on the descriptor; capability dependency is plain input for the MVP slice)
     */
    public function __construct(
        private readonly EngineModuleRegistry $registry,
        private readonly ModuleCapabilityRegistry $capabilities,
        private array $capabilityRequirements = [],
    ) {
        $this->staticGraph = new StaticDependencyGraph($registry);
    }

    public function staticGraph(): StaticDependencyGraph
    {
        return $this->staticGraph;
    }

    /**
     * Boot/configuration-time validation (16 §69): dependency cycles and
     * duplicate exclusive capabilities. Structured reasons, never exceptions.
     *
     * @return list<BlockReason>
     */
    public function validateStatic(): array
    {
        return [...$this->staticGraph->cycleReasons(), ...$this->capabilities->exclusiveConflicts()];
    }

    /**
     * Computes the effective graph for one bot from a plain activation set.
     * Effective set = platform-enabled registry ∩ $activeModuleIds (16 §68).
     * A module is blocked when: a required module dependency is missing or
     * not active, a declared conflict is active, or a required capability has
     * no active provider. Missing optional capabilities leave the module
     * ACTIVE in reduced mode (16 §76-77).
     *
     * @param  list<string>  $activeModuleIds  desired per-bot activation set
     */
    public function resolve(array $activeModuleIds): EffectiveDependencyGraph
    {
        $desired = array_fill_keys($activeModuleIds, true);
        $effective = [];
        foreach ($this->registry->enabled() as $moduleId => $definition) {
            if (isset($desired[$moduleId])) {
                $effective[$moduleId] = true;
            }
        }
        ksort($effective);

        $active = [];
        $reduced = [];
        $blocked = [];

        foreach (array_keys($effective) as $moduleId) {
            $reasons = $this->blockingReasons($moduleId, $effective);
            $missingOptional = $this->missingOptionalCapabilities($moduleId, $effective);

            if ($reasons !== []) {
                $blocked[$moduleId] = $reasons;

                continue;
            }

            $active[] = $moduleId;
            if ($missingOptional !== []) {
                $reduced[$moduleId] = $missingOptional;
            }
        }

        return new EffectiveDependencyGraph(
            activeModuleIds: $active,
            reducedMode: $reduced,
            blocked: $blocked,
        );
    }

    /**
     * @param  array<string, true>  $effective  moduleId => true for the effective set
     * @return list<BlockReason>
     */
    private function blockingReasons(string $moduleId, array $effective): array
    {
        $reasons = [];

        foreach ($this->staticGraph->requiresOf($moduleId) as $dependencyId => $constraint) {
            $dependency = $this->registry->get($dependencyId);
            if ($dependency === null) {
                $reasons[] = new BlockReason(
                    code: BlockReasonCode::DependencyMissing,
                    moduleId: $moduleId,
                    blockingModuleId: $dependencyId,
                    detail: sprintf("required module '%s' (%s) is not installed", $dependencyId, $constraint),
                );

                continue;
            }
            if (! isset($effective[$dependencyId])) {
                $reasons[] = new BlockReason(
                    code: BlockReasonCode::DependencyDisabled,
                    moduleId: $moduleId,
                    blockingModuleId: $dependencyId,
                    detail: sprintf("required module '%s' is not active", $dependencyId),
                );
            }
        }

        foreach ($this->staticGraph->conflictsOf($moduleId) as $conflictId) {
            if (isset($effective[$conflictId])) {
                $reasons[] = new BlockReason(
                    code: BlockReasonCode::DependencyConflict,
                    moduleId: $moduleId,
                    blockingModuleId: $conflictId,
                    detail: sprintf("conflicts with active module '%s'", $conflictId),
                );
            }
        }

        foreach ($this->requirementsFor($moduleId) as $requirement) {
            if ($requirement->optional) {
                continue;
            }
            if ($this->activeProvidersOf($requirement->capabilityId, $effective) === []) {
                $reasons[] = new BlockReason(
                    code: BlockReasonCode::CapabilityUnavailable,
                    moduleId: $moduleId,
                    blockingModuleId: '',
                    detail: sprintf("no active provider for capability '%s'", $requirement->capabilityId),
                );
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string, true>  $effective
     * @return list<string> missing optional capability ids
     */
    private function missingOptionalCapabilities(string $moduleId, array $effective): array
    {
        $missing = [];
        foreach ($this->requirementsFor($moduleId) as $requirement) {
            if ($requirement->optional
                && $this->activeProvidersOf($requirement->capabilityId, $effective) === []
            ) {
                $missing[] = $requirement->capabilityId;
            }
        }

        return $missing;
    }

    /**
     * Requirements of one module: descriptor-declared requiresCapabilities
     * (all mandatory, doc 16) merged with the explicit resolver input
     * (which may add optional requirements).
     *
     * @return list<CapabilityRequirement>
     */
    private function requirementsFor(string $moduleId): array
    {
        $requirements = [];
        $definition = $this->registry->get($moduleId);
        foreach ($definition?->descriptor->requiresCapabilities ?? [] as $capabilityId) {
            $requirements[] = new CapabilityRequirement($capabilityId, optional: false);
        }

        foreach ($this->capabilityRequirements[$moduleId] ?? [] as $requirement) {
            $requirements[] = $requirement;
        }

        return $requirements;
    }

    /**
     * @param  array<string, true>  $effective
     * @return list<string> active module ids providing the capability
     */
    private function activeProvidersOf(string $capabilityId, array $effective): array
    {
        return array_values(array_filter(
            $this->capabilities->providersOf($capabilityId),
            static fn (string $moduleId): bool => isset($effective[$moduleId]),
        ));
    }
}
