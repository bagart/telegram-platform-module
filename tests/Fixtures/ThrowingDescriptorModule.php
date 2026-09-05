<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;
use RuntimeException;

/**
 * Broken fixture: descriptor() always throws, exercising DESCRIPTOR_FAILED
 * fault isolation.
 */
final class ThrowingDescriptorModule implements TgModuleContract
{
    public const string ID = 'throwing';

    public static function descriptor(): TgModuleDescriptor
    {
        throw new RuntimeException('descriptor exploded');
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        // no-op
    }
}
