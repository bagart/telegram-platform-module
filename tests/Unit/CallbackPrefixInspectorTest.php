<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\CallbackPrefixInspector;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Routing\RouteDeclaration;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\SecondModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use PHPUnit\Framework\TestCase;

final class CallbackPrefixInspectorTest extends TestCase
{
    public function test_module_namespaced_callback_key_passes(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('alpha', [new RouteDeclaration('callback', 'alpha:open')]),
        ]);

        self::assertSame([], CallbackPrefixInspector::violations($registry));
    }

    public function test_non_conforming_callback_key_is_reported(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('alpha', [new RouteDeclaration('callback', 'settings')]),
        ]);

        $violations = CallbackPrefixInspector::violations($registry);

        self::assertCount(1, $violations);
        self::assertSame('settings', $violations[0]['key']);
        self::assertSame('alpha', $violations[0]['module']);
    }

    public function test_grandfathered_legacy_namespaces_pass(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('menu', [
                new RouteDeclaration('callback', 'wsi:invite:1'),
                new RouteDeclaration('callback', 'wsa:home'),
            ]),
            $this->definition('summarizer', [new RouteDeclaration('callback', 'sm:42:m')]),
            $this->definition('tts', [new RouteDeclaration('callback', 'tv:42:m')]),
            $this->definition('stt', [new RouteDeclaration('callback', 'tc:42:m')]),
            $this->definition('nettools', [new RouteDeclaration('callback', 'nt:v1:scan:1')]),
            $this->definition('mafia', [new RouteDeclaration('callback', 'm:game')]),
        ]);

        self::assertSame([], CallbackPrefixInspector::violations($registry));
    }

    public function test_prefix_owned_by_another_module_is_reported(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('alpha', [new RouteDeclaration('callback', 'beta:panel')]),
            $this->definition('beta', [new RouteDeclaration('callback', 'beta:panel')]),
        ]);

        $violations = CallbackPrefixInspector::violations($registry);

        self::assertCount(1, $violations);
        self::assertSame('beta:panel', $violations[0]['key']);
        self::assertSame('alpha', $violations[0]['module']);
    }

    /**
     * @param  list<RouteDeclaration>  $routes
     */
    private function definition(string $id, array $routes): TgModuleDefinition
    {
        return new TgModuleDefinition(
            configKey: $id,
            provider: $id === TestModule::ID ? TestModule::class : SecondModule::class,
            descriptor: new TgModuleDescriptor(id: $id, name: ucfirst($id), version: '0.1.0'),
            enabled: true,
            routes: $routes,
        );
    }
}
