<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Routing;

use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Diagnostics\EngineMetrics;
use BAGArt\TelegramModuleEngine\Tenancy\BotContext;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * PostgreSQL-backed RouteResolver: a dumb dispatcher proxy over the routing
 * table (doc 33 Part 1). Rows are written on the control path; this resolver
 * only reads and filters — an entry is returned only when its owning module
 * is effectively enabled for the context's bot (explicit activation row or
 * descriptor default), so disabled modules are absent from results. Chat-level
 * filtering is out of MVP scope: routing is bot-level, chatId is carried by
 * the context but not used for filtering yet.
 */
final class PgRouteResolver implements RouteResolver
{
    private const float CACHE_TTL_SECONDS = 30.0;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ModuleActivationReader $activations,
        private readonly CacheRepository $cache,
        private readonly string $table = 'bot_module_routes',
        private readonly ?EngineMetrics $metrics = null,
    ) {
    }

    public function resolve(BotContext $context): RoutingTable
    {
        $startedAt = hrtime(true);

        try {
            return $this->doResolve($context);
        } finally {
            $this->metrics?->observeDurationMs('route_lookup_ms', (hrtime(true) - $startedAt) / 1e6);
        }
    }

    public function invalidateBot(string $botId): void
    {
        $this->cache->forget('tg-routes:'.$botId);
    }

    private function doResolve(BotContext $context): RoutingTable
    {
        $cacheKey = 'tg-routes:'.$context->botId;

        return $this->cache->remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($context): RoutingTable {
            return $this->loadFromDatabase($context);
        });
    }

    private function loadFromDatabase(BotContext $context): RoutingTable
    {
        $active = $this->activations->activeModuleIds($context->botId);

        $rows = $this->connection->transaction(function () use ($context) {
            return $this->connection->table($this->table)
                ->where('bot_id', $context->botId)
                ->orderByDesc('priority')
                ->orderBy('entry_key')
                ->lockForUpdate()
                ->get();
        });

        $entries = [];
        foreach ($rows as $row) {
            if (! in_array($row->module_id, $active, true)) {
                continue;
            }

            $entries[] = new RouteEntry(
                moduleId: $row->module_id,
                entryType: $row->entry_type,
                entryKey: $row->entry_key,
                priority: (int) $row->priority,
                payload: self::decodePayload($row->payload),
            );
        }

        return new RoutingTable($context->botId, $entries);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodePayload(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
