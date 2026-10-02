<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

/**
 * Immutable, typed outcome of one enable/disable operation (doc 38 §47).
 * Business outcomes (blocked, conflict) are values, never exceptions.
 */
final readonly class ActivationResult
{
    /**
     * @param  ActivationOutcome  $outcome  what happened
     * @param  int  $revision  binding revision after the operation (unchanged
     *                         on idempotent no-ops; 0 when no binding exists;
     *                         the pre-write revision on conflict/blocked)
     * @param  list<ActivationBlocker>  $blockers  non-empty only for Blocked
     * @param  ActivationConflict|null  $conflict  non-null only for ConcurrentModification
     */
    public function __construct(
        public ActivationOutcome $outcome,
        public int $revision,
        public array $blockers = [],
        public ?ActivationConflict $conflict = null,
    ) {
    }

    /** True when the operation persisted a new desired state (revision bumped). */
    public function applied(): bool
    {
        return $this->outcome === ActivationOutcome::Enabled
            || $this->outcome === ActivationOutcome::Disabled;
    }
}
