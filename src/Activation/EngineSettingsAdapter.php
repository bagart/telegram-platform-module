<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramModuleEngine\Settings\ResolvedSetting;
use BAGArt\TelegramModuleEngine\Settings\SettingsStorageContract;

/**
 * Engine-backed implementation of the lib settings contract: reads and
 * writes bot_module_activations.module_settings through
 * SettingsStorageContract instead of the legacy tg_module_enablements table.
 *
 * Bound for ModuleSettingsContract when enablement_driver='engine' (the
 * management package skips its legacy bridge in that mode). Reads layer chat
 * overrides over bot-level values; writes merge into the RAW map of exactly
 * one scope. The reserved `enabled` key never lands in a settings map: at
 * chat scope it stores "{chatId}:__enabled__", at bot scope it flips the
 * activation status.
 */
final class EngineSettingsAdapter implements ModuleSettingsContract
{
    /** Reserved patch key that flips enablement instead of storing a value. */
    private const string ENABLED_KEY = 'enabled';

    private ?\Closure $afterWrite = null;

    public function __construct(
        private readonly SettingsStorageContract $storage,
        private readonly ModuleActivationWriterContract $activationWriter,
    ) {
    }

    /**
     * Effective settings map for a module in a scope: bot-level values with
     * chat overrides layered on top (chat wins); `$chatId === null` returns
     * bot scope only. Descriptor defaults remain the caller's fallback, and
     * the chat-enablement sentinel is never part of the settings map.
     *
     * @return array<string, mixed>
     */
    public function settingsFor(string $moduleId, string $botId, ?int $chatId = null): array
    {
        $settings = $this->storedSettings($moduleId, $botId, null);

        if ($chatId !== null) {
            foreach ($this->storedSettings($moduleId, $botId, $chatId) as $fieldId => $value) {
                $settings[$fieldId] = $value;
            }
        }

        unset($settings[ModuleActivationReader::CHAT_ENABLED_KEY]);

        return $settings;
    }

    /**
     * Merge `$patch` into the raw stored map of exactly one scope: `null`
     * removes the key, and no inherited value from a wider scope is ever
     * read or materialized. The reserved `enabled` key never lands in
     * settings — only a bool value acts (chat scope stores the
     * "{chatId}:__enabled__" sentinel, bot scope flips the activation
     * status); any non-bool value, `null` included, is ignored silently
     * for legacy parity.
     *
     * @param  array<string, mixed|null>  $patch
     */
    public function patchSettings(string $moduleId, string $botId, ?int $chatId, array $patch): void
    {
        foreach ($patch as $fieldId => $value) {
            if ($fieldId === self::ENABLED_KEY) {
                $this->patchEnablement($moduleId, $botId, $chatId, $value);
                continue;
            }

            if ($value === null) {
                $this->storage->forget($botId, $moduleId, (string) $fieldId, $chatId);
                continue;
            }

            $this->storage->set(new ResolvedSetting(
                botId: $botId,
                screenId: $moduleId,
                fieldId: (string) $fieldId,
                value: $value,
                chatId: $chatId,
            ));
        }

        if ($this->afterWrite !== null) {
            ($this->afterWrite)($moduleId, $botId, $chatId, $patch);
        }
    }

    /**
     * Explicit (non-inherited) settings for every scope a module uses —
     * the scope maps exactly as stored, so a caller saving them back never
     * materializes inherited values. Parity with the legacy driver: chat
     * scopes by chat id descending first (globally across bots), then bot
     * scopes by bot id ascending; enablement-only scopes come back with
     * `settings: []` and the chat-enablement sentinel stays out of the maps.
     *
     * @param  string|null  $botId  restrict to one bot; null = all bots
     * @return list<array{botId: string, chatId: int|null, settings: array<string, mixed>}>
     */
    public function chatsWithSettings(string $moduleId, ?string $botId = null): array
    {
        return $this->storage->scopesWithSettings($moduleId, $botId);
    }

    /**
     * Register the hook invoked once per patchSettings() call, after every
     * key of the patch has been persisted, with
     * (moduleId, botId, chatId, patch). A later registration replaces the
     * previous hook; hook exceptions propagate to the patch caller.
     *
     * The menu provider registers the audit / settings-refiner /
     * etag-bump closure here — the engine package itself has no
     * menu/management dependency (precedent:
     * TgModuleEnablementService::setEpochBumper).
     */
    public function setAfterWrite(\Closure $hook): void
    {
        $this->afterWrite = $hook;
    }

    /** @return array<string, mixed> stored (raw, scope-own) settings */
    private function storedSettings(string $moduleId, string $botId, ?int $chatId): array
    {
        $settings = [];

        foreach ($this->storage->all($botId, $chatId) as $setting) {
            if ($setting->screenId === $moduleId) {
                $settings[$setting->fieldId] = $setting->value;
            }
        }

        return $settings;
    }

    /**
     * Reserved `enabled` entry: a bool acts at the patch's scope — chat
     * scope stores the sentinel, bot scope flips the activation status.
     * Any non-bool value (`null` included) is ignored silently, matching
     * the legacy service's `is_bool()` gate.
     */
    private function patchEnablement(string $moduleId, string $botId, ?int $chatId, mixed $value): void
    {
        if (! is_bool($value)) {
            return;
        }

        if ($chatId !== null) {
            $this->storage->set(new ResolvedSetting(
                botId: $botId,
                screenId: $moduleId,
                fieldId: ModuleActivationReader::CHAT_ENABLED_KEY,
                value: $value,
                chatId: $chatId,
            ));

            return;
        }

        $this->activationWriter->setEnabled($moduleId, $botId, $value);
    }
}
