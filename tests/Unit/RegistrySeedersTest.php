<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\SecondModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\Seeders\AlphaSeeder;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\Seeders\BetaSeeder;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\Seeders\ZetaSeeder;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use PHPUnit\Framework\TestCase;

final class RegistrySeedersTest extends TestCase
{
    public function test_seeders_are_collected_from_enabled_modules_only(): void
    {
        $registry = $this->build([
            'test' => new TgModuleConfig(true, TestModule::class, [AlphaSeeder::class]),
            'test-second' => new TgModuleConfig(false, SecondModule::class, [BetaSeeder::class]),
        ]);

        self::assertSame([AlphaSeeder::class], $registry->seeders());
    }

    public function test_seeders_are_deduplicated_across_modules(): void
    {
        $registry = $this->build([
            'test' => new TgModuleConfig(true, TestModule::class, [AlphaSeeder::class]),
            'test-second' => new TgModuleConfig(true, SecondModule::class, [AlphaSeeder::class, BetaSeeder::class]),
        ]);

        self::assertSame([AlphaSeeder::class, BetaSeeder::class], $registry->seeders());
    }

    public function test_seeders_order_follows_module_id_order(): void
    {
        $registry = $this->build([
            'test-second' => new TgModuleConfig(true, SecondModule::class, [ZetaSeeder::class]),
            'test' => new TgModuleConfig(true, TestModule::class, [AlphaSeeder::class]),
        ]);

        self::assertSame([AlphaSeeder::class, ZetaSeeder::class], $registry->seeders());
    }

    public function test_seeders_empty_when_no_module_declares_any(): void
    {
        $registry = $this->build([
            'test' => new TgModuleConfig(true, TestModule::class),
        ]);

        self::assertSame([], $registry->seeders());
    }

    /**
     * @param  array<string, TgModuleConfig>  $modules
     */
    private function build(array $modules): EngineModuleRegistry
    {
        return (new ModuleRegistryBuilder(['modules' => $modules]))->build()->registry;
    }
}
