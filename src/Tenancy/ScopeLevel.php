<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tenancy;

/**
 * Scope levels of the tenancy model (doc 38, Part I). Scope answers "about
 * which object are we talking?" — it is never a permission decision.
 *
 * Tenant scope is intentionally absent in the MVP: the platform currently
 * has no tenant entity, so the hierarchy collapses to Platform → Bot → Chat.
 */
enum ScopeLevel
{
    /** Platform-wide installation state: module definitions, global capabilities. */
    case Platform;

    /** Bot scope: one operational bot, its bindings and routing table. */
    case Bot;

    /** Chat scope: a Telegram chat inside a concrete bot's context. */
    case Chat;
}
