<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\DeclaredCommandInspector;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Routing\RouteDeclaration;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\SecondModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use PHPUnit\Framework\TestCase;

final class DeclaredCommandInspectorTest extends TestCase
{
    public function test_distinct_command_keys_produce_no_duplicates(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition(TestModule::ID, [new RouteDeclaration('command', '/start')]),
            $this->definition(SecondModule::ID, [new RouteDeclaration('command', '/help')]),
        ]);

        self::assertSame([], DeclaredCommandInspector::duplicates($registry));
    }

    public function test_same_key_declared_by_two_modules_is_a_duplicate(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition(TestModule::ID, [new RouteDeclaration('command', '/report')]),
            $this->definition(SecondModule::ID, [new RouteDeclaration('command', '/report')]),
        ]);

        $duplicates = DeclaredCommandInspector::duplicates($registry);

        self::assertCount(1, $duplicates);
        self::assertSame('/report', $duplicates[0]['key']);
        self::assertSame('report', $duplicates[0]['normalized']);
        self::assertSame(TestModule::ID, $duplicates[0]['first']);
        self::assertSame(SecondModule::ID, $duplicates[0]['second']);
    }

    public function test_command_and_telegram_command_types_are_the_same_dispatch_surface(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition(TestModule::ID, [new RouteDeclaration('command', '/menu')]),
            $this->definition(SecondModule::ID, [new RouteDeclaration('telegram.command', '/menu')]),
        ]);

        self::assertCount(1, DeclaredCommandInspector::duplicates($registry));
    }

    public function test_case_leading_slash_and_bot_suffix_are_normalized(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition(TestModule::ID, [new RouteDeclaration('command', '/Report')]),
            $this->definition(SecondModule::ID, [new RouteDeclaration('command', 'report@MyBot')]),
        ]);

        $duplicates = DeclaredCommandInspector::duplicates($registry);

        self::assertCount(1, $duplicates);
        self::assertSame('report', $duplicates[0]['normalized']);
    }

    public function test_non_command_route_types_are_ignored(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition(TestModule::ID, [new RouteDeclaration('callback', 'settings')]),
            $this->definition(SecondModule::ID, [new RouteDeclaration('callback', 'settings')]),
        ]);

        self::assertSame([], DeclaredCommandInspector::duplicates($registry));
    }

    public function test_disabled_modules_contribute_no_duplicates(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition(TestModule::ID, [new RouteDeclaration('command', '/report')]),
            $this->disabledDefinition(SecondModule::ID, [new RouteDeclaration('command', '/report')]),
        ]);

        self::assertSame([], DeclaredCommandInspector::duplicates($registry));
    }

    /**
     * @param  list<RouteDeclaration>  $routes
     */
    private function definition(string $id, array $routes): TgModuleDefinition
    {
        return $this->build($id, true, $routes);
    }

    /**
     * @param  list<RouteDeclaration>  $routes
     */
    private function disabledDefinition(string $id, array $routes): TgModuleDefinition
    {
        return $this->build($id, false, $routes);
    }

    /**
     * @param  list<RouteDeclaration>  $routes
     */
    private function build(string $id, bool $enabled, array $routes): TgModuleDefinition
    {
        $provider = $id === TestModule::ID ? TestModule::class : SecondModule::class;

        return new TgModuleDefinition(
            configKey: $id,
            provider: $provider,
            descriptor: new TgModuleDescriptor(id: $id, name: ucfirst($id), version: '0.1.0'),
            enabled: $enabled,
            routes: $routes,
        );
    }
}
