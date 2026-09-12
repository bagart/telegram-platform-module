<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

/**
 * Contract for storing and retrieving bot-specific setting overrides.
 *
 * This contract is for runtime overrides only (per-bot settings that override
 * platform defaults from config files). Canonical platform and module settings
 * live in config files (*.php, JSON, YML), NOT in DB.
 *
 * Storage is tenant-scoped (bot as tenant per platform rule) with optional
 * chat scope for per-chat overrides.
 */
interface SettingsStorageContract
{
    /**
     * Retrieve a resolved setting value.
     *
     * @return ResolvedSetting|null  null if no value is stored (caller uses descriptor default).
     */
    public function get(string $botId, string $screenId, string $fieldId, ?int $chatId = null): ?ResolvedSetting;

    /**
     * Store a resolved setting value.
     */
    public function set(ResolvedSetting $setting): void;

    /**
     * Retrieve all settings for a bot (and optionally a chat).
     *
     * @return list<ResolvedSetting>
     */
    public function all(string $botId, ?int $chatId = null): array;

    /**
     * Delete a specific setting (revert to descriptor default).
     */
    public function forget(string $botId, string $screenId, string $fieldId, ?int $chatId = null): void;
}
