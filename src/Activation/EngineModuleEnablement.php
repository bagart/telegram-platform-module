<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;

/**
 * Engine-backed adapter for the lib dispatch contract: the update selector
 * asks "is this module enabled for this bot right now?" and gets the answer
 * from the engine activation store (bot_module_activations + descriptor
 * defaults). Per-instance memo cache honors NFR-5 (no SQL per update in
 * steady state); chatId participates in the memo key but is not a filtering
 * dimension yet — routing is bot-level (see PgRouteResolver).
 */
final class EngineModuleEnablement implements ModuleEnablementContract
{
    /** @var array<string, bool> "moduleId|botId|chatId" => decision */
    private array $memo = [];

    public function __construct(
        private readonly ModuleActivationReader $activations,
    ) {}

    public function isEnabled(string $moduleId, string $botId, int $chatId): bool
    {
        $key = $moduleId.'|'.$botId.'|'.$chatId;

        return $this->memo[$key] ??= $this->activations->isEffectivelyEnabled($botId, $moduleId);
    }

    public function refresh(?string $botId = null, ?int $chatId = null): void
    {
        if ($botId === null) {
            $this->memo = [];

            return;
        }

        foreach (array_keys($this->memo) as $key) {
            [, $memoBot, $memoChat] = explode('|', $key);
            if ($memoBot === $botId && ($chatId === null || $memoChat === (string) $chatId)) {
                unset($this->memo[$key]);
            }
        }
    }
}
