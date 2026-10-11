<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures;

use BAGArt\TelegramBot\Modules\TgModuleCapability;
use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;

/**
 * Ui-capable fixture that deliberately omits requiresModules menu — the
 * negative fixture for the MISSING_MENU_DEPENDENCY registry rule and the
 * Ui-menu boot warning (the hub module cannot rescue it: id != 'menu').
 */
final class UiNoMenuModule implements TgModuleContract
{
    public const string ID = 'ui_no_menu';

    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: self::ID,
            name: 'Ui Without Menu Module',
            version: '1.0.0',
            capabilities: [TgModuleCapability::Ui],
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        // no-op
    }
}
