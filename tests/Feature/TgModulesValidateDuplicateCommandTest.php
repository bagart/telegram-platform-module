<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Feature;

use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use BAGArt\TelegramModuleEngine\Routing\RouteDeclaration;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\SecondModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The cross-module duplicate-command rule of `tg:modules:validate` (Q5 → W-D1,
 * edge 11). It must start GREEN on the current registry (K24 — no wrong-reason
 * red in a blocking step) and flip red only for a flat-name collision the
 * builder's exact (type, key) pass cannot see.
 */
final class TgModulesValidateDuplicateCommandTest extends TestCase
{
    public function testCurrentRegistryValidatesGreen(): void
    {
        $exit = Artisan::call('tg:modules:validate');
        $output = Artisan::output();

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Module registry OK:', $output);
    }

    public function testFlatCommandNameCollisionAcrossModulesFailsValidation(): void
    {
        $this->app->instance(ModuleRegistryBuilder::class, new ModuleRegistryBuilder([
            'strict' => false,
            'modules' => [
                TestModule::ID => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    routes: [new RouteDeclaration('command', '/Shared')],
                ),
                SecondModule::ID => new TgModuleConfig(
                    enabled: true,
                    provider: SecondModule::class,
                    routes: [new RouteDeclaration('telegram.command', 'shared@MyBot')],
                ),
            ],
        ]));

        $exit = Artisan::call('tg:modules:validate');
        $output = Artisan::output();

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('[DUPLICATE_COMMAND_KEY]', $output);
        self::assertStringNotContainsString('[DUPLICATE_ROUTE_ENTRY]', $output);
    }
}
