<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

/**
 * Optimistic-locking conflict detail (doc 40 §15): the caller sent a stale
 * expectedRevision; nothing was written. UI should offer a refresh instead
 * of a retry that would silently overwrite someone else's change.
 */
final readonly class ActivationConflict
{
    public function __construct(
        public int $expectedRevision,
        public int $currentRevision,
    ) {}
}
