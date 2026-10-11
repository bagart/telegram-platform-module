<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

/**
 * Enforces the `<module>:<action>` grammar on declared callback route keys
 * (Q2 namespacing rule — validation-only, no runtime dispatch change).
 *
 * Scope is declarations only: RouteDeclaration rows of type 'callback' in
 * config/tg_modules.php. Runtime callback_data (processor support() strings,
 * emitted keyboard payloads) has no inventory and stays out of sight here.
 *
 * Complements ModuleRegistryBuilder::firstDuplicateRoute(), which dedupes the
 * raw (type, key) pair: this inspector checks key *ownership*, so a foreign
 * but well-formed prefix is still a violation for the declaring module.
 */
final readonly class CallbackPrefixInspector
{
    /**
     * Legacy namespaces grandfathered by the Q7 decision (2026-10-07): keys
     * under any of these tokens stay declarable, while new/changed
     * declarations must follow `<module>:`. The list doubles as the worklist
     * for a future strict-migration wave — renaming these namespaces breaks
     * keyboards already delivered (old buttons keep sending old payloads).
     *
     * Token owner sources:
     *  - wsi  menu        telegram-platform-menu/src/Chats/InviteCallbackRoute.php:15
     *  - wsa  menu        telegram-platform-menu/src/Chats/WorkspaceAdminCallbackRoute.php:26
     *  - sm   summarizer  tgbot-module-summarizer/src/Ui/CallbackRoute.php:14
     *  - tv   tts         tgbot-module-tts/src/Ui/CallbackRoute.php:15
     *  - tc   stt         tgbot-module-stt/src/Ui/CallbackRoute.php:15
     *  - nt:  nettools    tgbot-module-nettools/src/Ui/CallbackGrammar.php:20
     *  - m:   mafia       tgbot-game-mafia/src/Telegram/CallbackRouterProcessor.php:49
     *
     * Compliant baseline (not exempt — already matches the grammar):
     * antispam:captcha: (tgbot-module-antispam/src/Captcha/CaptchaStore.php:17).
     */
    private const GRANDFATHERED = ['wsi', 'wsa', 'sm', 'tv', 'tc', 'nt:', 'm:'];

    private const CALLBACK_TYPE = 'callback';

    /**
     * @return list<array{key: string, module: string}>
     *         one entry per declared callback key that is neither
     *         `<module>:<action>` nor a grandfathered legacy namespace
     */
    public static function violations(EngineModuleRegistry $registry): array
    {
        $violations = [];

        foreach ($registry->enabled() as $definition) {
            $moduleId = $definition->id();

            foreach ($definition->routes as $route) {
                if ($route->type !== self::CALLBACK_TYPE) {
                    continue;
                }

                if (self::isNamespaced($route->key, $moduleId) || self::isGrandfathered($route->key)) {
                    continue;
                }

                $violations[] = [
                    'key' => $route->key,
                    'module' => $moduleId,
                ];
            }
        }

        return $violations;
    }

    /** `<module>:<action>` — prefix owned by the declaring module, action non-empty. */
    private static function isNamespaced(string $key, string $moduleId): bool
    {
        $prefix = $moduleId.':';

        return str_starts_with($key, $prefix) && strlen($key) > strlen($prefix);
    }

    private static function isGrandfathered(string $key): bool
    {
        foreach (self::GRANDFATHERED as $token) {
            $prefix = str_ends_with($token, ':') ? $token : $token.':';

            if ($key === $token || str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
