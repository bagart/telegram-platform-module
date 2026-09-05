<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Capabilities;

use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;

/**
 * Immutable, global dependency graph built once from registry manifests at
 * discovery time (16 §68). Nodes are modules; edges are requiresModules and
 * conflictsWith declarations. Cycle detection is mandatory (16 §66).
 */
final class StaticDependencyGraph
{
    /** @var array<string, array<string, string>> moduleId => dependency id => version constraint */
    private array $requires;

    /** @var array<string, list<string>> moduleId => conflicting module ids, sorted */
    private array $conflicts;

    /** @var list<list<string>> */
    private array $cycles;

    public function __construct(private readonly EngineModuleRegistry $registry)
    {
        $requires = [];
        $conflicts = [];
        foreach ($this->registry->all() as $moduleId => $definition) {
            $requires[$moduleId] = $definition->descriptor->requiresModules;
            $moduleConflicts = $definition->descriptor->conflictsWith;
            sort($moduleConflicts);
            $conflicts[$moduleId] = $moduleConflicts;
        }
        ksort($requires);
        ksort($conflicts);
        $this->requires = $requires;
        $this->conflicts = $conflicts;
        $this->cycles = $this->detectCycles();
    }

    /** @return list<string> sorted module ids */
    public function moduleIds(): array
    {
        return array_keys($this->requires);
    }

    /** @return array<string, string> dependency id => version constraint */
    public function requiresOf(string $moduleId): array
    {
        return $this->requires[$moduleId] ?? [];
    }

    /** @return list<string> */
    public function conflictsOf(string $moduleId): array
    {
        return $this->conflicts[$moduleId] ?? [];
    }

    /**
     * Dependency cycles found in the requiresModules edges. Each cycle is
     * reported once, starting from its lexicographically smallest node.
     *
     * @return list<list<string>>
     */
    public function cycles(): array
    {
        return $this->cycles;
    }

    /** @return list<BlockReason> one CycleDetected reason per participating module */
    public function cycleReasons(): array
    {
        $reasons = [];
        foreach ($this->cycles as $cycle) {
            foreach ($cycle as $moduleId) {
                $reasons[] = new BlockReason(
                    code: BlockReasonCode::CycleDetected,
                    moduleId: $moduleId,
                    blockingModuleId: '',
                    detail: 'dependency cycle: '.implode(' -> ', [...$cycle, $cycle[0]]),
                );
            }
        }

        return $reasons;
    }

    /** @return list<list<string>> */
    private function detectCycles(): array
    {
        /** @var array<string, int> $state 0 = unvisited, 1 = in stack, 2 = done */
        $state = [];
        /** @var list<string> $stack */
        $stack = [];
        /** @var list<list<string>> $cycles */
        $cycles = [];

        $visit = function (string $node) use (&$visit, &$state, &$stack, &$cycles): void {
            $state[$node] = 1;
            $stack[] = $node;

            foreach (array_keys($this->requiresOf($node)) as $dependency) {
                if (! isset($this->requires[$dependency])) {
                    continue;
                }
                $status = $state[$dependency] ?? 0;
                if ($status === 0) {
                    $visit($dependency);
                } elseif ($status === 1) {
                    $position = array_search($dependency, $stack, true);
                    if ($position !== false) {
                        $cycles[] = array_slice($stack, (int) $position);
                    }
                }
            }

            array_pop($stack);
            $state[$node] = 2;
        };

        foreach ($this->moduleIds() as $moduleId) {
            if (($state[$moduleId] ?? 0) === 0) {
                $visit($moduleId);
            }
        }

        return $cycles;
    }
}
