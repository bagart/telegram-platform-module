<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Feature;

use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use BAGArt\TelegramModuleEngine\Routing\RouteDeclaration;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The callback-namespacing rule of `tg:modules:validate` (W-Q2, Q2/Q7): it
 * ships report-only, so non-conforming declarations warn without failing the
 * command, and the current registry must stay green (0 declared callback rows
 * today plus the grandfathered legacy namespaces).
 */
final class TgModulesValidateCallbackPrefixTest extends TestCase
{
    public function testCurrentRegistryValidatesGreenWithoutCallbackFindings(): void
    {
        $exit = Artisan::call('tg:modules:validate');
        $output = Artisan::output();

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Module registry OK:', $output);
        self::assertStringNotContainsString('[CALLBACK_PREFIX_INVALID]', $output);
    }

    public function testNonConformingDeclarationWarnsWithoutFailingInReportOnlyMode(): void
    {
        $this->app->instance(ModuleRegistryBuilder::class, new ModuleRegistryBuilder([
            'strict' => false,
            'modules' => [
                TestModule::ID => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    routes: [new RouteDeclaration('callback', 'settings')],
                ),
            ],
        ]));

        $exit = Artisan::call('tg:modules:validate');
        $output = Artisan::output();

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('[CALLBACK_PREFIX_INVALID]', $output);
        self::assertStringContainsString('callback route key "settings"', $output);
    }

    /**
     * Post-flip case: once the warn-mode merge is observed green in CI, the
     * findings move into the $errors path — unskip and restore the body
     * (inject the same `settings` declaration, assert exit 1 plus the
     * [CALLBACK_PREFIX_INVALID] error line).
     */
    public function testNonConformingDeclarationFailsValidationAfterTheBlockingFlip(): void
    {
        $this->markTestSkipped(
            'Report-only until the warn-mode merge is observed green in CI; unskip with the blocking flip (W-Q2 checklist).'
        );
    }
}
