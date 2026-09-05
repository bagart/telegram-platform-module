<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Tenancy\BotContext;
use BAGArt\TelegramModuleEngine\Tenancy\ScopeLevel;
use PHPUnit\Framework\TestCase;

final class BotContextTest extends TestCase
{
    public function test_for_bot_context_is_bot_scoped_without_chat(): void
    {
        $context = BotContext::forBot('bot-1');

        self::assertSame('bot-1', $context->botId);
        self::assertNull($context->chatId);
        self::assertSame(ScopeLevel::Bot, $context->scope);
    }

    public function test_for_chat_context_binds_chat_to_its_bot(): void
    {
        $context = BotContext::forChat('bot-1', 42);

        self::assertSame('bot-1', $context->botId);
        self::assertSame(42, $context->chatId);
        self::assertSame(ScopeLevel::Chat, $context->scope);
    }

    public function test_context_is_immutable(): void
    {
        $context = BotContext::forBot('bot-1');

        $this->expectException(\Error::class);
        $context->botId = 'bot-2';
    }
}
