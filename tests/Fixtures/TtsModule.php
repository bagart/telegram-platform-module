<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;

final class TtsModule implements TgModuleContract
{
    public const string ID = 'tts';

    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(id: self::ID, name: 'TTS Module', version: '0.1.0');
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
    }
}
