<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Feature;

use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use BAGArt\TelegramModuleEngine\Registry\RegistryErrorCode;
use BAGArt\TelegramModuleEngine\TelegramModuleEngineServiceProvider;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\MenuModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\UiNoMenuModule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * D2-b: a Ui-capable module must declare requiresModules menu or
 * MenuCatalogRefiner silently drops its manifest. Enforced twice — build-time
 * rule MISSING_MENU_DEPENDENCY (tg:modules:validate) and the boot warning —
 * with the `menu` hub exempt from both (it cannot depend on itself).
 */
final class UiMenuDependencyRuleTest extends TestCase
{
    public function test_stock_registry_validates_green(): void
    {
        $exit = Artisan::call('tg:modules:validate');
        $output = Artisan::output();

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Module registry OK:', $output);
    }

    public function test_stock_registry_emits_no_menu_warning(): void
    {
        Log::spy();

        $this->emitWarnings($this->app->make(EngineModuleRegistry::class));

        Log::shouldNotHaveReceived('warning');
    }

    public function test_ui_module_without_menu_dependency_warns_while_menu_stays_exempt(): void
    {
        $result = (new ModuleRegistryBuilder([
            'strict' => false,
            'modules' => [
                UiNoMenuModule::ID => new TgModuleConfig(enabled: true, provider: UiNoMenuModule::class),
                MenuModule::ID => new TgModuleConfig(enabled: true, provider: MenuModule::class),
            ],
        ]))->build();

        self::assertFalse($result->isValid());
        self::assertSame(RegistryErrorCode::MissingMenuDependency, $result->errors[0]->code);

        Log::spy();
        $this->emitWarnings($result->registry);

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'silently dropped by MenuCatalogRefiner')
                && ($context['moduleId'] ?? null) === UiNoMenuModule::ID)
            ->once();
        Log::shouldNotHaveReceived('warning', static fn (string $message, array $context): bool => ($context['moduleId'] ?? null) === MenuModule::ID);
    }

    public function test_validate_command_fails_with_missing_menu_dependency_code(): void
    {
        $this->app->instance(ModuleRegistryBuilder::class, new ModuleRegistryBuilder([
            'strict' => false,
            'modules' => [
                UiNoMenuModule::ID => new TgModuleConfig(enabled: true, provider: UiNoMenuModule::class),
                MenuModule::ID => new TgModuleConfig(enabled: true, provider: MenuModule::class),
            ],
        ]));

        $exit = Artisan::call('tg:modules:validate');
        $output = Artisan::output();

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('[MISSING_MENU_DEPENDENCY] '.UiNoMenuModule::ID, $output);
        self::assertStringNotContainsString('[MISSING_MENU_DEPENDENCY] '.MenuModule::ID, $output);
    }

    private function emitWarnings(EngineModuleRegistry $registry): void
    {
        (new ReflectionMethod(TelegramModuleEngineServiceProvider::class, 'warnUiModulesMissingMenuDependency'))
            ->invoke(new TelegramModuleEngineServiceProvider($this->app), $registry);
    }
}
