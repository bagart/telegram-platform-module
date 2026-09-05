<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use Illuminate\Database\ConnectionInterface;

/**
 * Read-side view of bot module activations (doc 12 §5: Registry does not
 * decide per-bot enablement; this reader does, per bot). Resolves the
 * enablement inheritance chain platform → bot (doc 38 §35): an explicit
 * activation row wins; otherwise the descriptor's defaultEnabled decides
 * (legacy "enabled everywhere" seed parity). Read-only by design — the
 * resolver must never mutate state (doc 38 §90).
 */
final class ModuleActivationReader
{
    public const string STATUS_ENABLED = 'enabled';

    public const string STATUS_DISABLED = 'disabled';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly EngineModuleRegistry $registry,
        private readonly string $table = 'bot_module_activations',
    ) {}

    /** Name of the activation table (shared with the write-side service). */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * Raw activation row for (bot, module), or null when the module has no
     * binding for the bot yet.
     *
     * @return object|null anonymous row with bot_id, module_id, status, revision
     */
    public function rowFor(string $botId, string $moduleId): ?object
    {
        $row = $this->connection->table($this->table)
            ->where('bot_id', $botId)
            ->where('module_id', $moduleId)
            ->first();

        return $row ?? null;
    }

    /**
     * Effective enablement of one module for one bot: explicit row, else the
     * platform-enabled descriptor's defaultEnabled fallback. Platform-disabled
     * modules are never effective.
     */
    public function isEffectivelyEnabled(string $botId, string $moduleId): bool
    {
        $definition = $this->registry->get($moduleId);
        if ($definition === null || ! $definition->enabled) {
            return false;
        }

        $row = $this->rowFor($botId, $moduleId);

        return $row === null
            ? $definition->descriptor->defaultEnabled
            : $row->status === self::STATUS_ENABLED;
    }

    /** True when the bot has an activation row explicitly disabling the module. */
    public function isExplicitlyDisabled(string $botId, string $moduleId): bool
    {
        $row = $this->rowFor($botId, $moduleId);

        return $row !== null && $row->status === self::STATUS_DISABLED;
    }

    /**
     * All module ids effectively enabled for the bot (explicitly enabled rows
     * plus default-enabled modules without rows). Disabled/absent modules are
     * absent from the result.
     *
     * @return list<string> sorted module ids
     */
    public function activeModuleIds(string $botId): array
    {
        $rows = [];
        foreach ($this->connection->table($this->table)->where('bot_id', $botId)->get() as $row) {
            $rows[$row->module_id] = $row->status;
        }

        $active = [];
        foreach ($this->registry->enabled() as $definition) {
            $moduleId = $definition->id();
            $status = $rows[$moduleId] ?? null;

            if ($status === self::STATUS_ENABLED
                || ($status === null && $definition->descriptor->defaultEnabled)
            ) {
                $active[] = $moduleId;
            }
        }
        sort($active);

        return $active;
    }
}
