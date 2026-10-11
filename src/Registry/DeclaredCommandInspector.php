<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;

/**
 * Detects cross-module collisions in the declared Telegram bot-command route
 * keys of TgModuleConfig::$routes.
 *
 * Complements ModuleRegistryBuilder::firstDuplicateRoute(), which compares the
 * raw (type, key) pair: this inspector sees the flat name a bot user actually
 * types, so it also catches the two shape variants the platform accepts at
 * dispatch time — the 'command' / 'telegram.command' type alias pair
 * (CommandRouteLookup) and case / leading-slash / @bot-suffix differences.
 *
 * Runtime flat-registry visibility (callback inventory, support() strings) is
 * out of scope: only declarations are inspected.
 */
final readonly class DeclaredCommandInspector
{
    /** Reported by tg:modules:validate; intentionally not a RegistryErrorCode case (owned by the builder's taxonomy). */
    public const ERROR_CODE = 'DUPLICATE_COMMAND_KEY';

    private const COMMAND_TYPES = ['command', 'telegram.command'];

    /**
     * @return list<array{key: string, normalized: string, first: string, second: string}>
     *         one entry per colliding normalized name (first declaration wins)
     */
    public static function duplicates(EngineModuleRegistry $registry): array
    {
        /** @var array<string, array{key: string, module: string}> $seen */
        $seen = [];
        $duplicates = [];

        foreach ($registry->enabled() as $definition) {
            foreach (self::commandKeys($definition) as $key) {
                $normalized = self::normalize($key);

                if ($normalized === '') {
                    continue;
                }

                if (isset($seen[$normalized])) {
                    $duplicates[] = [
                        'key' => $seen[$normalized]['key'],
                        'normalized' => $normalized,
                        'first' => $seen[$normalized]['module'],
                        'second' => $definition->id(),
                    ];

                    continue;
                }

                $seen[$normalized] = ['key' => $key, 'module' => $definition->id()];
            }
        }

        return $duplicates;
    }

    /** @return list<string> */
    private static function commandKeys(TgModuleDefinition $definition): array
    {
        $keys = [];

        foreach ($definition->routes as $route) {
            if (! in_array($route->type, self::COMMAND_TYPES, true)) {
                continue;
            }

            $keys[] = $route->key;
        }

        return $keys;
    }

    /**
     * Telegram treats a bot command as case-insensitive and ignores the
     * trailing @botname targeting suffix, so those spellings collide in the
     * flat command table.
     */
    private static function normalize(string $key): string
    {
        $token = strtolower(trim(strtok(trim($key), " \t") ?: ''));

        $at = strpos($token, '@');

        if ($at !== false) {
            $token = substr($token, 0, $at);
        }

        return ltrim($token, '/');
    }
}
