<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

/**
 * A settings screen contribution from a module.
 *
 * Each module contributes one or more settings screens. The contribution
 * declares the screen ID, field descriptor, and optional web/telegram
 * rendering bindings. The engine resolves visibility per bot (and per
 * chat for Telegram) through the availability resolver.
 */
final readonly class SettingsScreenContribution
{
    /**
     * @param  string  $screenId  Stable screen identifier, e.g. 'summarizer.settings'.
     * @param  SettingsDescriptor  $descriptor  Typed field list for generic rendering.
     * @param  WebScreenBinding|null  $web  Web rendering binding (null = descriptor-only).
     * @param  TelegramScreenBinding|null  $telegram  Telegram rendering binding (null = descriptor-only).
     */
    public function __construct(
        public string $screenId,
        public SettingsDescriptor $descriptor,
        public ?WebScreenBinding $web = null,
        public ?TelegramScreenBinding $telegram = null,
    ) {
    }
}
