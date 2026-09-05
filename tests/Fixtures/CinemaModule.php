<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;

/**
 * Dependent fixture: requires the "menu" module (version-constrained,
 * model only). Enabling it without an effectively enabled menu dependency
 * must return a structured blocker, never an exception.
 */
final class CinemaModule implements TgModuleContract
{
    public const string ID = 'cinema';

    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: self::ID,
            name: 'Cinema Module',
            version: '0.1.0',
            requiresModules: [MenuModule::ID => '^1.0'],
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        // no-op
    }
}
