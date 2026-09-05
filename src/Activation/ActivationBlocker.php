<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

/**
 * One structured "blocked because" reason of a failed enable (doc 40 §7:
 * dependency errors are machine-readable).
 */
final readonly class ActivationBlocker
{
    public function __construct(
        /** Module whose state causes the block (the dependency, not the target). */
        public string $moduleId,
        public ActivationErrorCode $reason,
        /** Human-readable diagnostic, safe for admin UI rendering. */
        public string $message,
    ) {}
}
