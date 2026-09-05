<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;

/**
 * Second probe module fixture (distinct descriptor id) for multi-module registry tests.
 * descriptor() is pure and register() is a no-op.
 */
final class SecondModule implements TgModuleContract
{
    public const string ID = 'test-second';

    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: self::ID,
            name: 'Test Module',
            version: '0.1.0',
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        // no-op
    }
}
