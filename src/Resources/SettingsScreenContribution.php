<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Resources;

/**
 * Settings screen contribution — module declares a settings screen that the
 * menu renders via SchemaForm or a custom screen binding. Engine resolves
 * per-bot visibility; menu renders.
 *
 * @see docs/architecture/33.md §4.11
 */
final readonly class SettingsScreenContribution
{
    public function __construct(
        /** Stable identity: {moduleId}.settings.{localId}. */
        public string $id,
        /** Owning module ID. */
        public string $moduleId,
        /** Screen location: 'skills' | 'settings'. */
        public string $location,
        /** Display label (translation key or literal). */
        public string $label,
        /** Typed field list — same five-language rule as menu. */
        public array $fields = [],
        /** Optional custom screen builder class (null = use SchemaForm). */
        public ?string $customScreenBuilder = null,
        /** Required permission for this screen (null = admin-only by default). */
        public ?string $requiredPermission = null,
        /** Translation key for the label. */
        public ?string $translationKey = null,
        /** Registration scope: PLATFORM | BOT. */
        public string $registrationScope = 'PLATFORM',
        /** Activation scope: BOT | CHAT. */
        public string $activationScope = 'BOT',
    ) {
    }
}
