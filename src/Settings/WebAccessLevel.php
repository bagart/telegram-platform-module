<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

/**
 * Web access level for settings screen rendering.
 */
enum WebAccessLevel: string
{
    case PlatformAdmin = 'platform_admin';
    case BotAdmin = 'bot_admin';
}
