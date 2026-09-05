<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;

/**
 * Module fixture with descriptor defaultEnabled = false: without an explicit
 * activation row it must NOT resolve as enabled (no implicit "enabled
 * everywhere" for opt-in modules).
 */
final class DefaultOffModule implements TgModuleContract
{
    public const string ID = 'default-off';

    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: self::ID,
            name: 'Default Off Module',
            version: '0.1.0',
            defaultEnabled: false,
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        // no-op
    }
}
