<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

/**
 * Binding for the web (Inertia) rendering of a settings screen.
 *
 * Points to an Inertia page/component and declares the required access
 * level (Platform Admin or Bot Admin) for rendering.
 */
final readonly class WebScreenBinding
{
    /**
     * @param  string  $component  Inertia page component name, e.g. 'Settings/Summarizer'.
     * @param  WebAccessLevel  $accessLevel  Required access level to view/edit.
     */
    public function __construct(
        public string $component,
        public WebAccessLevel $accessLevel = WebAccessLevel::BotAdmin,
    ) {
    }
}
