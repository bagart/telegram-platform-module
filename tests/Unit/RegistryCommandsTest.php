<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\CommandSignatureInspector;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\Commands\AlphaCommand;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\Commands\BetaCommand;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use PHPUnit\Framework\TestCase;

final class RegistryCommandsTest extends TestCase
{
    public function test_aggregates_commands_from_enabled_modules_in_id_order(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('test', true, [BetaCommand::class]),
            $this->definition('alpha', true, [AlphaCommand::class, BetaCommand::class]),
        ]);

        // deduplicated, module-id order (alpha before test)
        self::assertSame(
            [AlphaCommand::class, BetaCommand::class],
            $registry->commands(),
        );
    }

    public function test_disabled_modules_contribute_no_commands(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('test', true, [AlphaCommand::class]),
            $this->definition('alpha', false, [BetaCommand::class]),
        ]);

        self::assertSame([AlphaCommand::class], $registry->commands());
    }

    public function test_signature_collisions_are_detected(): void
    {
        // same command class declared by two enabled modules -> same signature
        $registry = new EngineModuleRegistry([
            $this->definition('alpha', true, [AlphaCommand::class]),
            $this->definition('test', true, [AlphaCommand::class]),
        ]);

        $collisions = CommandSignatureInspector::collisions($registry);

        self::assertCount(1, $collisions);
        self::assertSame('alpha:ping', $collisions[0]['signature']);
        self::assertSame(AlphaCommand::class, $collisions[0]['first']);
        self::assertSame(AlphaCommand::class, $collisions[0]['second']);
    }

    public function test_no_collisions_between_distinct_signatures(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('alpha', true, [AlphaCommand::class]),
            $this->definition('test', true, [BetaCommand::class]),
        ]);

        self::assertSame([], CommandSignatureInspector::collisions($registry));
    }

    /**
     * @param  list<class-string>  $commands
     */
    private function definition(string $id, bool $enabled, array $commands): TgModuleDefinition
    {
        return new TgModuleDefinition(
            configKey: $id,
            provider: TestModule::class,
            descriptor: new TgModuleDescriptor(id: $id, name: ucfirst($id), version: '0.1.0'),
            enabled: $enabled,
            commands: $commands,
        );
    }
}
