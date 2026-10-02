<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;

/**
 * Module fixture with descriptor defaultChatEnabled = false and
 * defaultEnabled = true (Q11): bot scope resolves ON (no row → true), but
 * chat scope stays OFF until an explicit "{chatId}:__enabled__" override
 * exists — neither an absent row nor a bot-level enabled row may flip
 * chats ON.
 */
final class ChatDefaultOffModule implements TgModuleContract
{
    public const string ID = 'chat-default-off';

    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: self::ID,
            name: 'Chat Default Off Module',
            version: '0.1.0',
            defaultEnabled: true,
            defaultChatEnabled: false,
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        // no-op
    }
}
