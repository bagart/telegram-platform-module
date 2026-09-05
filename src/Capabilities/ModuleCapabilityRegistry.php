<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Capabilities;

use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;

/**
 * Immutable registry of capability declarations keyed by module id, built at
 * discovery time from module descriptors (16 §33, §133). It answers "who
 * provides capability X" and detects exclusive-capability collisions; it never
 * executes a capability (16 §8-10).
 */
final class ModuleCapabilityRegistry
{
    /** @var array<string, list<CapabilityDeclaration>> moduleId => declarations, sorted by capability id */
    private array $declarations;

    /**
     * @param  array<string, list<CapabilityDeclaration>>  $declarations  moduleId => provided capabilities
     */
    public function __construct(array $declarations)
    {
        $sorted = [];
        foreach ($declarations as $moduleId => $moduleDeclarations) {
            usort(
                $moduleDeclarations,
                static fn (CapabilityDeclaration $a, CapabilityDeclaration $b): int => strcmp($a->capabilityId, $b->capabilityId),
            );
            $sorted[$moduleId] = $moduleDeclarations;
        }
        ksort($sorted);
        $this->declarations = $sorted;
    }

    /**
     * Derives declarations from module descriptors. Each declared capability
     * kind gets the stable id "{moduleId}.{kind}" (16 §7). Modules needing
     * explicit shared capability ids (e.g. exclusive ones) pass $overrides:
     * a per-module declaration list replaces the derived one entirely.
     *
     * @param  array<string, TgModuleDefinition>  $definitions  moduleId => definition
     * @param  array<string, list<CapabilityDeclaration>>  $overrides  moduleId => explicit declarations
     */
    public static function fromDefinitions(array $definitions, array $overrides = []): self
    {
        $declarations = [];
        foreach ($definitions as $moduleId => $definition) {
            if (array_key_exists($moduleId, $overrides)) {
                $declarations[$moduleId] = $overrides[$moduleId];

                continue;
            }

            $derived = [];
            foreach ($definition->descriptor->capabilities as $kind) {
                $derived[] = new CapabilityDeclaration(
                    moduleId: $moduleId,
                    capabilityId: $moduleId.'.'.$kind->value,
                    kind: $kind,
                );
            }
            $declarations[$moduleId] = $derived;
        }

        return new self($declarations);
    }

    /** @return array<string, list<CapabilityDeclaration>> moduleId => declarations */
    public function all(): array
    {
        return $this->declarations;
    }

    /** @return list<CapabilityDeclaration> */
    public function forModule(string $moduleId): array
    {
        return $this->declarations[$moduleId] ?? [];
    }

    /** @return list<string> module ids declaring the capability, sorted */
    public function providersOf(string $capabilityId): array
    {
        $providers = [];
        foreach ($this->declarations as $moduleDeclarations) {
            foreach ($moduleDeclarations as $declaration) {
                if ($declaration->capabilityId === $capabilityId) {
                    $providers[$declaration->moduleId] = true;
                }
            }
        }

        return array_keys($providers);
    }

    public function provides(string $moduleId, string $capabilityId): bool
    {
        foreach ($this->forModule($moduleId) as $declaration) {
            if ($declaration->capabilityId === $capabilityId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Static boot-time validation: an exclusive capability claimed by more
     * than one module is an error unless an explicit selection was made
     * (16 §61, §86). Every claimant receives a structured reason naming the
     * other claimant; nothing throws.
     *
     * @return list<BlockReason> one reason per claimant per contested capability
     */
    public function exclusiveConflicts(): array
    {
        /** @var array<string, list<string>> $byCapability capabilityId => sorted claiming module ids */
        $byCapability = [];
        foreach ($this->declarations as $moduleDeclarations) {
            foreach ($moduleDeclarations as $declaration) {
                if ($declaration->exclusive) {
                    $byCapability[$declaration->capabilityId][] = $declaration->moduleId;
                }
            }
        }
        foreach ($byCapability as &$claimants) {
            $claimants = array_values(array_unique($claimants));
            sort($claimants);
        }
        unset($claimants);
        ksort($byCapability);

        $reasons = [];
        foreach ($byCapability as $capabilityId => $claimants) {
            if (count($claimants) < 2) {
                continue;
            }
            foreach ($claimants as $claimant) {
                $others = array_values(array_diff($claimants, [$claimant]));
                $reasons[] = new BlockReason(
                    code: BlockReasonCode::ExclusiveCapabilityConflict,
                    moduleId: $claimant,
                    blockingModuleId: $others[0],
                    detail: sprintf(
                        "exclusive capability '%s' is also claimed by module '%s'",
                        $capabilityId,
                        $others[0],
                    ),
                );
            }
        }

        return $reasons;
    }
}
