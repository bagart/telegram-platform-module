<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tenancy;

/**
 * Immutable runtime context identifying the bot (and, optionally, the chat)
 * an operation belongs to (doc 38 §68-69, doc 12 §25: never a bare integer
 * that could be confused with userId/tenantId). No global current-bot state —
 * every consumer receives the context explicitly.
 */
final readonly class BotContext
{
    /**
     * @param  string  $botId  stable bot identity (opaque to the engine)
     * @param  int|null  $chatId  Telegram chat id, present only when the
     *                            operation is chat-scoped; a chat context is
     *                            always bound to its bot (doc 38 §69)
     * @param  ScopeLevel  $scope  scope level at which the context operates
     */
    public function __construct(
        public string $botId,
        public ?int $chatId,
        public ScopeLevel $scope,
    ) {
    }

    /** Bot-scoped context: an operation against the bot itself (no chat). */
    public static function forBot(string $botId): self
    {
        return new self(botId: $botId, chatId: null, scope: ScopeLevel::Bot);
    }

    /** Chat-scoped context: bot + chat as a single security/runtime context. */
    public static function forChat(string $botId, int $chatId): self
    {
        return new self(botId: $botId, chatId: $chatId, scope: ScopeLevel::Chat);
    }
}
