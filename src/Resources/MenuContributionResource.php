<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Resources;

/**
 * §4.11 Menu contribution descriptor — registered by module, resolved by engine,
 * consumed by menu. Engine owns discovery, ownership, collision detection;
 * menu owns rendering and navigation.
 *
 * @see docs/architecture/33.md §4.11
 */
final readonly class MenuContributionResource
{
    public function __construct(
        /** Stable identity: {moduleId}.menu.{localId} */
        public string $id,
        /** Owning module ID. */
        public string $moduleId,
        /** Menu section: 'games' | 'skills' | 'settings'. */
        public string $location,
        /** Display label (translation key or literal). */
        public string $label,
        /** Icon emoji or icon key. */
        public string $icon,
        /** Ordering within the section (lower = earlier). */
        public int $ordering = 100,
        /** Optional route/action pair for navigation target. */
        public ?string $route = null,
        /** Required permission for visibility (null = no restriction). */
        public ?string $requiredPermission = null,
        /** Translation key for the label. */
        public ?string $translationKey = null,
        /** Visibility state. */
        public string $visibility = 'ACTIVE',
        /** Registration scope: PLATFORM | BOT. */
        public string $registrationScope = 'PLATFORM',
        /** Activation scope: BOT | CHAT. */
        public string $activationScope = 'BOT',
    ) {}

    /**
     * Resolve the effective label from the i18n map.
     */
    public function resolveLabel(callable $translator): string
    {
        if ($this->translationKey !== null) {
            return $translator($this->translationKey, $this->label);
        }

        return $this->label;
    }
}
