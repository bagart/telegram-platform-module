<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Database-backed settings storage scoped to bot_module_activations.
 *
 * Stores per-field overrides in the `module_settings` JSON column.
 * JSON structure: { "screenId": { "fieldId": value, ... }, ... }
 *
 * This is for runtime overrides only — canonical config lives in files.
 * Bot as tenant (platform rule); optional chat scope for per-chat overrides.
 */
final class DatabaseSettingsStorage implements SettingsStorageContract
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'bot_module_activations',
    ) {}

    public function get(string $botId, string $screenId, string $fieldId, ?int $chatId = null): ?ResolvedSetting
    {
        $row = $this->connection->table($this->table)
            ->where('bot_id', $botId)
            ->where('module_id', $screenId)
            ->first();

        if ($row === null) {
            return null;
        }

        $settings = is_array($row->module_settings) ? $row->module_settings : [];
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
        $this->connection->table($this->table)
            ->where('bot_id', $setting->botId)
            ->where('module_id', $setting->screenId)
            ->lockForUpdate()
            ->upsert(
                [
                    'bot_id' => $setting->botId,
                    'module_id' => $setting->screenId,
                    'status' => 'enabled',
                    'module_settings' => $this->buildSettingsJson(
                        [],
                        $setting->chatId,
                        $setting->fieldId,
                        $setting->value,
                    ),
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['bot_id', 'module_id'],
                ['module_settings', 'updated_at'],
            );

        // Merge into existing settings (upsert won't deep-merge JSON).
        $row = $this->connection->table($this->table)
            ->where('bot_id', $setting->botId)
            ->where('module_id', $setting->screenId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return;
        }

        $current = is_array($row->module_settings) ? $row->module_settings : [];
        $path = $this->fieldPath($setting->chatId, $setting->fieldId);
        $current[$path] = $setting->value;

        $this->connection->table($this->table)
            ->where('bot_id', $setting->botId)
            ->where('module_id', $setting->screenId)
            ->update([
                'module_settings' => $current,
                'updated_at' => now(),
            ]);
    }

    public function all(string $botId, ?int $chatId = null): array
    {
        $row = $this->connection->table($this->table)
            ->where('bot_id', $botId)
            ->first();

        if ($row === null) {
            return [];
        }

        $settings = is_array($row->module_settings) ? $row->module_settings : [];
        $prefix = $chatId !== null ? "{$chatId}:" : '';

        $result = [];
        foreach ($settings as $path => $value) {
            if ($chatId !== null && ! str_starts_with($path, $prefix)) {
                continue;
            }

            if ($chatId === null && str_contains($path, ':')) {
                continue;
            }

            $fieldId = $chatId !== null ? substr($path, strlen($prefix)) : $path;

            $result[] = new ResolvedSetting(
                botId: $botId,
                screenId: $row->module_id,
                fieldId: $fieldId,
                value: $value,
                chatId: $chatId,
            );
        }

        return $result;
    }

    public function forget(string $botId, string $screenId, string $fieldId, ?int $chatId = null): void
    {
        $row = $this->connection->table($this->table)
            ->where('bot_id', $botId)
            ->where('module_id', $screenId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return;
        }

        $settings = is_array($row->module_settings) ? $row->module_settings : [];
        $path = $this->fieldPath($chatId, $fieldId);

        if (! array_key_exists($path, $settings)) {
            return;
        }

        unset($settings[$path]);

        $this->connection->table($this->table)
            ->where('bot_id', $botId)
            ->where('module_id', $screenId)
            ->update([
                'module_settings' => $settings !== [] ? $settings : null,
                'updated_at' => now(),
            ]);
    }

    private function fieldPath(?int $chatId, string $fieldId): string
    {
        return $chatId !== null ? "{$chatId}:{$fieldId}" : $fieldId;
    }

    /**
     * Build a settings JSON array with a single field set.
     *
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function buildSettingsJson(array $existing, ?int $chatId, string $fieldId, mixed $value): array
    {
        $path = $this->fieldPath($chatId, $fieldId);
        $existing[$path] = $value;

        return $existing;
    }
}
