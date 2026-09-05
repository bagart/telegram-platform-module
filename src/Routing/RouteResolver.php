<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Routing;

use BAGArt\TelegramModuleEngine\Tenancy\BotContext;

/**
 * Strict read contract for the bot routing table (doc 12 §23-26): given an
 * explicit BotContext, return the set of active route entries for that bot.
 * Read-only, side-effect free, one call per operation context — callers must
 * not re-query per entry (doc 12 §32).
 */
interface RouteResolver
{
    public function resolve(BotContext $context): RoutingTable;
}
