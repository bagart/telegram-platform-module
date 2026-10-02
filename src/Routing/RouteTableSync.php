<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Routing;

use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;

/**
 * Control-path writer for bot_module_routes: materializes each active
 * module's declarative route contributions (TgModuleConfig::$routes) into
 * the per-bot routing table. Idempotent — re-running produces the same
 * rows; rows of modules that are no longer active for the bot are removed.
 * The read side (PgRouteResolver) stays a dumb proxy over the result.
 */
final readonly class RouteTableSync
{
    public function __construct(
        private ConnectionInterface $connection,
        private ModuleActivationReader $activations,
        private EngineModuleRegistry $registry,
        private string $table = 'bot_module_routes',
    ) {
    }

    /**
     * Dry-run counterpart of syncForBot: what the next sync would change.
     * Drift detection (roadmap phase 6) — replaces any reconcile machinery.
     *
     * @return array{missing: list<array{bot_id: string, module_id: string, entry_type: string, entry_key: string}>, stale: list<array<string, mixed>>}
     */
    public function diffForBot(string $botId): array
    {
        $active = $this->activations->activeModuleIds($botId);

        $desired = [];
        foreach ($active as $moduleId) {
            $definition = $this->registry->get($moduleId);
            if ($definition === null) {
                continue;
            }

            foreach ($definition->routes as $route) {
                $desired[] = [
                    'bot_id' => $botId,
                    'module_id' => $moduleId,
                    'entry_type' => $route->type,
                    'entry_key' => $route->key,
                ];
            }
        }

        $stored = $this->connection->table($this->table)
            ->where('bot_id', $botId)
            ->get(['module_id', 'entry_type', 'entry_key'])
            ->map(static fn ($row): string => $row->module_id.'|'.$row->entry_type.'|'.$row->entry_key)
            ->all();

        $desiredKeys = array_map(
            static fn (array $row): string => $row['module_id'].'|'.$row['entry_type'].'|'.$row['entry_key'],
            $desired,
        );

        $missing = array_values(array_filter(
            $desired,
            static fn (array $row, int $i): bool => ! in_array($desiredKeys[$i], $stored, true),
            ARRAY_FILTER_USE_BOTH,
        ));

        $stale = [];
        foreach ($this->connection->table($this->table)->where('bot_id', $botId)->get() as $row) {
            $key = $row->module_id.'|'.$row->entry_type.'|'.$row->entry_key;
            if (! in_array($key, $desiredKeys, true) && ! in_array($row->module_id, $active, true)) {
                $stale[] = (array) $row;
            }
        }

        return ['missing' => $missing, 'stale' => $stale];
    }

    /**
     * @return array{written: int, removed: int} row counts for reporting
     */
    public function syncForBot(string $botId): array
    {
        return $this->connection->transaction(function () use ($botId): array {
            $active = $this->activations->activeModuleIds($botId);

            $written = 0;
            foreach ($active as $moduleId) {
                $definition = $this->registry->get($moduleId);
                if ($definition === null) {
                    continue;
                }

                foreach ($definition->routes as $route) {
                    $this->connection->table($this->table)->updateOrInsert(
                        [
                            'bot_id' => $botId,
                            'module_id' => $moduleId,
                            'entry_type' => $route->type,
                            'entry_key' => $route->key,
                        ],
                        [
                            'priority' => $route->priority,
                            'payload' => $route->payload === null ? null : json_encode($route->payload),
                            'updated_at' => Date::now(),
                        ],
                    );
                    $written++;
                }
            }

            $removed = $this->connection->table($this->table)
                ->where('bot_id', $botId)
                ->whereNotIn('module_id', $active === [] ? [''] : $active)
                ->delete();

            return ['written' => $written, 'removed' => $removed];
        });
    }
}
