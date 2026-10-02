<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Database-backed settings storage scoped to bot_module_activations.
 *
 * Stores per-field overrides in the `module_settings` JSON column.
 * JSON structure: { "screenId.fieldId": value, ... }
 * Chat-scoped fields use "chatId:fieldId" keys.
 *
 * This is for runtime overrides only — canonical config lives in files.
 * Bot as tenant (platform rule); optional chat scope for per-chat overrides.
 *
 * PostgreSQL: uses atomic JSONB operations (|| merge, - remove).
 * SQLite: falls back to read-modify-write (no JSONB support).
 */
final class DatabaseSettingsStorage implements SettingsStorageContract
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'bot_module_activations',
    ) {
    }

    public function get(string $botId, string $screenId, string $fieldId, ?int $chatId = null): ?ResolvedSetting
    {
        $row = $this->connection->table($this->table)
            ->where('bot_id', $botId)
            ->where('module_id', $screenId)
            ->first();

        if ($row === null) {
            return null;
        }

        $settings = $this->decodeSettings($row->module_settings);
        $path = $this->fieldPath($chatId, $fieldId);

        if (! array_key_exists($path, $settings)) {
            return null;
        }

        return new ResolvedSetting(
            botId: $botId,
            screenId: $screenId,
            fieldId: $fieldId,
            value: $settings[$path],
            chatId: $chatId,
        );
    }

    public function set(ResolvedSetting $setting): void
    {
        $path = $this->fieldPath($setting->chatId, $setting->fieldId);
        $patch = json_encode([$path => $setting->value], JSON_THROW_ON_ERROR);

        if ($this->isPostgres()) {
            $this->setPostgres($setting, $patch);
        } else {
            $this->setFallback($setting, $path);
        }
    }

    public function all(string $botId, ?int $chatId = null): array
    {
        $rows = $this->connection->table($this->table)
            ->where('bot_id', $botId)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $result = [];
        $prefix = $chatId !== null ? "{$chatId}:" : '';

        foreach ($rows as $row) {
            $settings = $this->decodeSettings($row->module_settings);

            foreach ($settings as $path => $value) {
                if ($chatId !== null && ! str_starts_with($path, $prefix)) {
                    continue;
                }

                if ($chatId === null && str_contains($path, ':')) {
                    continue;
                }

                $fieldId = $chatId !== null ? substr($path, strlen($prefix)) : $path;
                $result[$row->module_id."\0".$fieldId] = new ResolvedSetting(
                    botId: $botId,
                    screenId: $row->module_id,
                    fieldId: $fieldId,
                    value: $value,
                    chatId: $chatId,
                );
            }
        }

        return array_values($result);
    }

    /**
     * Enumeration parity with the legacy TgModuleEnablementService::
     * chatsWithSettings(): chat scopes by chat id descending first (globally
     * across bots — the legacy service partitions in PHP because PG would
     * order NULL chat ids first under DESC), then bot scopes by bot id
     * ascending. Every activation row is a bot scope (its status is
     * enablement) and every "{chatId}:" prefix is a chat scope, so
     * enablement-only scopes come back with `settings: []`; the
     * chat-enablement sentinel never appears in a map.
     *
     * @return list<array{botId: string, chatId: int|null, settings: array<string, mixed>}>
     */
    public function scopesWithSettings(string $moduleId, ?string $botId = null): array
    {
        $query = $this->connection->table($this->table)
            ->where('module_id', $moduleId)
            ->whereNotNull('bot_id')
            ->orderBy('bot_id');

        if ($botId !== null) {
            $query->where('bot_id', $botId);
        }

        $chatScopes = [];
        $botScopes = [];

        foreach ($query->get() as $row) {
            $botSettings = [];
            $chatSettings = [];

            foreach ($this->decodeSettings($row->module_settings) as $path => $value) {
                $parsed = $this->parseSettingPath((string) $path);

                if ($parsed === null) {
                    continue;
                }

                [$chatId, $fieldId] = $parsed;

                if ($chatId !== null) {
                    // The sentinel registers the chat scope (enablement-only)
                    // even though its value never enters the settings map.
                    $chatSettings[$chatId] ??= [];

                    if ($fieldId !== ModuleActivationReader::CHAT_ENABLED_KEY) {
                        $chatSettings[$chatId][$fieldId] = $value;
                    }

                    continue;
                }

                if ($fieldId === ModuleActivationReader::CHAT_ENABLED_KEY) {
                    continue;
                }

                $botSettings[$fieldId] = $value;
            }

            $botScopes[] = [
                'botId' => (string) $row->bot_id,
                'chatId' => null,
                'settings' => $botSettings,
            ];

            foreach ($chatSettings as $chatId => $settings) {
                $chatScopes[] = [
                    'botId' => (string) $row->bot_id,
                    'chatId' => $chatId,
                    'settings' => $settings,
                ];
            }
        }

        // Stable sort: ties keep bot id ascending row order, matching the
        // legacy partition (chat rows) + orderBy('bot_id') (bot rows).
        usort($chatScopes, static fn (array $a, array $b): int => $b['chatId'] <=> $a['chatId']);

        return array_merge($chatScopes, $botScopes);
    }

    public function forget(string $botId, string $screenId, string $fieldId, ?int $chatId = null): void
    {
        $path = $this->fieldPath($chatId, $fieldId);

        if ($this->isPostgres()) {
            $this->connection->table($this->table)
                ->where('bot_id', $botId)
                ->where('module_id', $screenId)
                ->update([
                    'module_settings' => DB::raw("module_settings - '{$path}'"),
                    'updated_at' => now(),
                ]);

            return;
        }

        // SQLite fallback: read-modify-write.
        $row = $this->connection->table($this->table)
            ->where('bot_id', $botId)
            ->where('module_id', $screenId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return;
        }

        $settings = $this->decodeSettings($row->module_settings);

        if (! array_key_exists($path, $settings)) {
            return;
        }

        unset($settings[$path]);

        $this->connection->table($this->table)
            ->where('bot_id', $botId)
            ->where('module_id', $screenId)
            ->update([
                'module_settings' => $settings !== [] ? $this->encodeSettings($settings) : null,
                'updated_at' => now(),
            ]);
    }

    private function setPostgres(ResolvedSetting $setting, string $patch): void
    {
        $this->connection->table($this->table)
            ->upsert(
                [
                    'bot_id' => $setting->botId,
                    'module_id' => $setting->screenId,
                    'status' => 'enabled',
                    'module_settings' => DB::raw("COALESCE(module_settings, '{}'::jsonb) || '{$patch}'::jsonb"),
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['bot_id', 'module_id'],
                ['module_settings', 'updated_at'],
            );
    }

    private function setFallback(ResolvedSetting $setting, string $path): void
    {
        $row = $this->connection->table($this->table)
            ->where('bot_id', $setting->botId)
            ->where('module_id', $setting->screenId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            $this->connection->table($this->table)->insert([
                'bot_id' => $setting->botId,
                'module_id' => $setting->screenId,
                'status' => 'enabled',
                'module_settings' => $this->encodeSettings([$path => $setting->value]),
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $current = $this->decodeSettings($row->module_settings);
        $current[$path] = $setting->value;

        $this->connection->table($this->table)
            ->where('bot_id', $setting->botId)
            ->where('module_id', $setting->screenId)
            ->update([
                'module_settings' => $this->encodeSettings($current),
                'updated_at' => now(),
            ]);
    }

    /**
     * Split a stored module_settings key into its chat scope and field id.
     *
     * `"{chatId}:field"` (negative chat ids included) yields `[chatId, field]`;
     * a plain key yields `[null, key]`; a colon-bearing key with a non-numeric
     * prefix is not addressable at any scope and yields null.
     *
     * @return array{0: int|null, 1: string}|null
     */
    private function parseSettingPath(string $path): ?array
    {
        if (preg_match('/^(-?\d+):(.*)$/s', $path, $matches) === 1) {
            return [(int) $matches[1], $matches[2]];
        }

        if (str_contains($path, ':')) {
            return null;
        }

        return [null, $path];
    }

    /** @return array<string, mixed> */
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

    /** @param array<string, mixed> $settings */
    private function encodeSettings(array $settings): string
    {
        return json_encode($settings, JSON_THROW_ON_ERROR);
    }

    private function fieldPath(?int $chatId, string $fieldId): string
    {
        return $chatId !== null ? "{$chatId}:{$fieldId}" : $fieldId;
    }

    private function isPostgres(): bool
    {
        return $this->connection->getDriverName() === 'pgsql';
    }
}
