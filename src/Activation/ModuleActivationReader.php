<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use Illuminate\Database\ConnectionInterface;

/**
 * Read-side view of bot module activations (doc 12 §5: Registry does not
 * decide per-bot enablement; this reader does, per bot). Resolves the
 * enablement inheritance chain platform → chat → bot (doc 38 §35): an
 * explicit per-chat override in module_settings wins; otherwise the
 * descriptor's defaultChatEnabled decides whether a chat may fall back to
 * bot scope at all (false = chats stay OFF until opted in); otherwise an
 * explicit activation row wins; otherwise the descriptor's defaultEnabled
 * decides (legacy "enabled everywhere" seed parity). Read-only by design —
 * the resolver must never mutate state (doc 38 §90).
 */
final class ModuleActivationReader
{
    public const string STATUS_ENABLED = 'enabled';

    public const string STATUS_DISABLED = 'disabled';

    /**
     * module_settings field name holding the per-chat enablement override;
     * full key is "{chatId}:__enabled__" (migration 2026_09_20_000001).
     */
    public const string CHAT_ENABLED_KEY = '__enabled__';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly EngineModuleRegistry $registry,
        private readonly string $table = 'bot_module_activations',
    ) {
    }

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

    /**
     * Effective enablement of one module for one bot and chat. Precedence:
     * platform gate (unknown/platform-disabled module → false) → per-chat
     * override "{chatId}:__enabled__" from module_settings (a bool wins over
     * the bot status in both directions, including false) → chat-scope
     * default → bot-level decision (row status, else the descriptor's
     * defaultEnabled when the bot has no row). Only real bools are honored —
     * "(bool) 'false'" would be true — so any non-bool value falls through
     * to the next step. Decoding is total: a degraded module_settings never
     * throws.
     *
     * Chat default (Q11): when descriptor->defaultChatEnabled is false, a
     * chat without an explicit bool override resolves to false REGARDLESS of
     * bot-level state — a bot activation row (even "enabled") or
     * defaultEnabled=true must not flip chats ON; a settings write that only
     * materializes the bot row stays inert for chat scope. Chats turn ON
     * only via an explicit chat override. defaultChatEnabled=true keeps the
     * legacy cascade (bot row status, else defaultEnabled).
     */
    public function isEffectivelyEnabledForChat(string $botId, string $moduleId, int $chatId): bool
    {
        $definition = $this->registry->get($moduleId);
        if ($definition === null || ! $definition->enabled) {
            return false;
        }

        $row = $this->rowFor($botId, $moduleId);

        if ($row !== null) {
            $override = $this->chatEnabledOverride($row, $chatId);
            if ($override !== null) {
                return $override;
            }
        }

        if (! $definition->descriptor->defaultChatEnabled) {
            return false;
        }

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

    /**
     * Per-chat enablement override for $chatId, or null when the key is
     * absent or its value is not a real bool (never coerced).
     */
    private function chatEnabledOverride(object $row, int $chatId): ?bool
    {
        $settings = $this->decodeSettings($row->module_settings ?? null);
        $value = $settings[$chatId.':'.self::CHAT_ENABLED_KEY] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * Decoded module_settings payload — an already-decoded array or a JSON
     * string (Postgres shape); anything else yields an empty array. No
     * JSON_THROW: a malformed payload degrades to "no overrides".
     *
     * @return array<string, mixed>
     */
    private function decodeSettings(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
