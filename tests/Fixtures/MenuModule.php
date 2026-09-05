<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;

/**
 * Dependency fixture, descriptor defaultEnabled = false. Used both as an
 * explicit-enable target and as a required dependency whose absence must
 * block dependent enables with a structured reason.
 */
final class MenuModule implements TgModuleContract
{
    public const string ID = 'menu';

    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: self::ID,
            name: 'Menu Module',
            version: '1.0.0',
            defaultEnabled: false,
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        // no-op
    }
}
