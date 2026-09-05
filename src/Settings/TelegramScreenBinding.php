<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

/**
 * Binding for the Telegram (menu) rendering of a settings screen.
 *
 * Points to a menu screen ID and declares the required Chat Access Control
 * level for rendering (consumes the access control contract).
 */
final readonly class TelegramScreenBinding
{
    /**
     * @param  string  $screenId  Menu module screen identifier.
     * @param  string|null  $requiredCapability  Capability key required from Chat Access Control (null = no check).
     */
    public function __construct(
        public string $screenId,
        public ?string $requiredCapability = null,
    ) {
    }
}
