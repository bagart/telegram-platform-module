<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

use ReflectionProperty;

/**
 * Detects Artisan command-name collisions between module-declared commands.
 * Reads the default value of Command::$signature via reflection — command
 * instances are never constructed. Commands without an explicit $signature
 * (Laravel derives their name from the class name) are ignored: two derived
 * names cannot collide because PHP class names are unique.
 */
final readonly class CommandSignatureInspector
{
    /**
     * @param  EngineModuleRegistry  $registry
     * @return list<array{signature: string, first: class-string, second: class-string}>
     *         one entry per duplicated signature (first occurrence wins)
     */
    public static function collisions(EngineModuleRegistry $registry): array
    {
        /** @var array<string, class-string> $seen signature => command class */
        $seen = [];
        $collisions = [];

        foreach ($registry->enabled() as $definition) {
            foreach ($definition->commands as $command) {
                $signature = self::signatureOf($command);
                if ($signature === null) {
                    continue;
                }

                if (isset($seen[$signature])) {
                    $collisions[] = [
                        'signature' => $signature,
                        'first' => $seen[$signature],
                        'second' => $command,
                    ];

                    continue;
                }

                $seen[$signature] = $command;
            }
        }

        return $collisions;
    }

    private static function signatureOf(string $command): ?string
    {
        $property = new ReflectionProperty($command, 'signature');
        $default = $property->getDefaultValue();

        return is_string($default) && $default !== '' ? $default : null;
    }
}
