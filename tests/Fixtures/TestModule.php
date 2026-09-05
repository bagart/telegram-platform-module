<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;

/**
 * In-memory module fixtures for registry builder tests. No side effects —
 * descriptor() is pure and register() is a no-op.
 */
final class TestModule implements TgModuleContract
{
    public const string ID = 'test';

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
