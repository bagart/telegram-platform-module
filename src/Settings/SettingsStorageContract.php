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

    /**
     * Explicit (non-inherited) settings maps for every scope a module uses.
     *
     * Plain `module_settings` keys form the bot scope (`chatId === null`);
     * `"{chatId}:field"` keys form chat scopes. Platform rows
     * (`bot_id IS NULL`) are excluded and the chat-enablement sentinel
     * (`ModuleActivationReader::CHAT_ENABLED_KEY`) is excluded from the
     * maps. A scope that exists only through enablement (the activation
     * row's status, or a lone chat-enablement sentinel key) is reported
     * with `settings: []` — no inherited value is ever materialized into a
     * narrower scope.
     *
     * @param  string|null  $botId  restrict to one bot; null = all bots
     * @return list<array{botId: string, chatId: int|null, settings: array<string, mixed>}>
     *         chat scopes by chat id descending first (globally across
     *         matching bots), then bot scopes by bot id ascending — parity
     *         with TgModuleEnablementService::chatsWithSettings.
     */
    public function scopesWithSettings(string $moduleId, ?string $botId = null): array;
}
