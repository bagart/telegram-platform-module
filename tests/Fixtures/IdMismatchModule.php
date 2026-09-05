<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;

/**
 * Broken fixture: its descriptor id intentionally differs from its natural
 * config key, exercising the ID_MISMATCH validation.
 */
final class IdMismatchModule implements TgModuleContract
{
    public const string CONFIG_KEY = 'mismatch-key';

    public const string DESCRIPTOR_ID = 'actual-id';

    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: self::DESCRIPTOR_ID,
            name: 'Mismatch Module',
            version: '0.1.0',
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        // no-op
    }
}
