<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

/**
 * Outcome of one lifecycle mutation (enable/disable). Idempotent no-ops are
 * distinct outcomes (AlreadyEnabled / AlreadyDisabled) so callers can tell
 * "you changed nothing" from "state changed".
 */
enum ActivationOutcome: string
{
    /** Desired state persisted; revision was bumped. */
    case Enabled = 'enabled';

    /** Module was already enabled for the bot; nothing changed. */
    case AlreadyEnabled = 'already_enabled';

    /** Desired state persisted; revision was bumped. */
    case Disabled = 'disabled';

    /** No enabled binding existed for the bot; nothing changed. */
    case AlreadyDisabled = 'already_disabled';

    /** Enable was blocked before persistence; see ::$blockers for the reasons. */
    case Blocked = 'blocked';

    /** Stale expectedRevision: nothing was written; see ::$conflict. */
    case ConcurrentModification = 'concurrent_modification';
}
