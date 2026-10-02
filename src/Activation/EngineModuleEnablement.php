<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;

/**
 * Engine-backed adapter for the lib dispatch contract: the update selector
 * asks "is this module enabled for this bot (in this chat) right now?" and
 * gets the answer from the engine activation store (bot_module_activations
 * + descriptor defaults), filtered per chat via the
 * "{chatId}:__enabled__" override in module_settings — an explicit chat
 * flag wins over the bot status, otherwise the descriptor's chat-scope
 * default and then the bot-level decision apply. A null chatId asks for the
 * bot-level decision only (isEffectivelyEnabled) — routing stays bot-level
 * (see PgRouteResolver). Per-instance memo cache honors NFR-5 (no SQL per
 * update in steady state); the memo key distinguishes bot-scope entries
 * ("{moduleId}:{botId}") from chat-scope entries
 * ("{moduleId}:{botId}:{chatId}") so the two decisions never collide.
 */
final class EngineModuleEnablement implements ModuleEnablementContract
{
    /** @var array<string, array{decision: bool, expiresAt: float}> */
    private array $memo = [];

    private const float TTL_SECONDS = 60.0;

    public function __construct(
        private readonly ModuleActivationReader $activations,
    ) {
    }

    public function isEnabled(string $moduleId, string $botId, ?int $chatId = null): bool
    {
        $key = $this->memoKey($moduleId, $botId, $chatId);
        $now = hrtime(true) / 1e9;

        $entry = $this->memo[$key] ?? null;
        if ($entry !== null && $entry['expiresAt'] > $now) {
            return $entry['decision'];
        }

        $decision = $chatId === null
            ? $this->activations->isEffectivelyEnabled($botId, $moduleId)
            : $this->activations->isEffectivelyEnabledForChat($botId, $moduleId, $chatId);
        $this->memo[$key] = [
            'decision' => $decision,
            'expiresAt' => $now + self::TTL_SECONDS,
        ];

        return $decision;
    }

    public function refresh(?string $botId = null, ?int $chatId = null): void
    {
        if ($botId === null) {
            $this->memo = [];

            return;
        }

        foreach (array_keys($this->memo) as $key) {
            $parts = explode(':', $key, 3);
            if (($parts[1] ?? null) !== $botId) {
                continue;
            }

            $memoChat = $parts[2] ?? null;
            if ($chatId === null || ($memoChat !== null && $memoChat === (string) $chatId)) {
                unset($this->memo[$key]);
            }
        }
    }

    private function memoKey(string $moduleId, string $botId, ?int $chatId): string
    {
        return $chatId === null
            ? $moduleId.':'.$botId
            : $moduleId.':'.$botId.':'.$chatId;
    }
}
