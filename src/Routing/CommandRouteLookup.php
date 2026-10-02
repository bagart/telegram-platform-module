<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Routing;

use BAGArt\TelegramBot\Contracts\Modules\CommandRouteContract;
use BAGArt\TelegramModuleEngine\Tenancy\BotContext;

/**
 * Dispatch-side consumer of the routing table (roadmap follow-up: live
 * command dispatch through the RouteResolver). Resolves the declared
 * processor class for a bot command, memoized per bot — the update
 * selector calls this for every incoming command, so steady state is
 * memory-only. Route rows are already activation-filtered by the
 * resolver; entries without a 'processor' payload are not dispatchable
 * and are skipped. A colliding entry key resolves deterministically by
 * module id (priority is honored first, per the table order).
 */
final class CommandRouteLookup implements CommandRouteContract
{
    /** @var array<string, array<string, string>> botId => command => processor class */
    private array $maps = [];

    public function __construct(
        private readonly RouteResolver $resolver,
    ) {
    }

    public function processorOf(string $commandName, string $botId): ?string
    {
        return $this->mapFor($botId)[$commandName] ?? null;
    }

    /** Invalidate the memoized map after a routes:sync for this bot. */
    public function refresh(?string $botId = null): void
    {
        if ($botId === null) {
            $this->maps = [];

            return;
        }
        unset($this->maps[$botId]);
    }

    /** @return array<string, string> command => processor class */
    private function mapFor(string $botId): array
    {
        if (isset($this->maps[$botId])) {
            return $this->maps[$botId];
        }

        $entries = $this->resolver->resolve(BotContext::forBot($botId))->entries;
        usort($entries, static fn (RouteEntry $a, RouteEntry $b): int
            => [$b->priority, $a->moduleId] <=> [$a->priority, $b->moduleId]);

        $map = [];
        foreach ($entries as $entry) {
            // Route type convention: 'command' is canonical; 'telegram.command' accepted for backward compat.
            if ($entry->entryType !== 'command' && $entry->entryType !== 'telegram.command') {
                continue;
            }
            $processor = is_string($entry->payload['processor'] ?? null)
                ? $entry->payload['processor']
                : null;
            $command = ltrim($entry->entryKey, '/');
            if ($processor !== null && $command !== '' && ! isset($map[$command])) {
                $map[$command] = $processor;
            }
        }

        return $this->maps[$botId] = $map;
    }
}
