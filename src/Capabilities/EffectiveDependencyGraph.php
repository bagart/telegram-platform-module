<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Capabilities;

/**
 * Effective (per-bot) dependency view computed on top of the static graph:
 * platform-enabled registry intersected with a per-bot activation set passed
 * in as plain input (16 §68-70). Activation storage is NOT this class's
 * concern — no DB, no container, no I/O.
 */
final readonly class EffectiveDependencyGraph
{
    /**
     * @param  list<string>  $activeModuleIds  modules that run, sorted
     * @param  array<string, list<string>>  $reducedMode  moduleId => missing optional capability ids (16 §76)
     * @param  array<string, list<BlockReason>>  $blocked  moduleId => blocking reasons
     */
    public function __construct(
        public array $activeModuleIds,
        public array $reducedMode,
        public array $blocked,
    ) {}

    public function isActive(string $moduleId): bool
    {
        return in_array($moduleId, $this->activeModuleIds, true);
    }

    /** True when the module runs but with missing optional capabilities. */
    public function isReduced(string $moduleId): bool
    {
        return isset($this->reducedMode[$moduleId])
            && $this->reducedMode[$moduleId] !== [];
    }

    /** @return list<BlockReason> */
    public function reasonsFor(string $moduleId): array
    {
        return $this->blocked[$moduleId] ?? [];
    }
}
