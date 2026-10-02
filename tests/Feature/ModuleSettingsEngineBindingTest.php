<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Feature;

use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramModuleEngine\Activation\EngineSettingsAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Host binding seam for enablement_driver='engine': ModuleSettingsContract
 * must resolve to EngineSettingsAdapter wired with the settings storage and
 * the activation writer, so chat reads merge and the reserved bot-scope
 * `enabled` patch reaches the activation status.
 */
final class ModuleSettingsEngineBindingTest extends TestCase
{
    use RefreshDatabase;

    public function testSettingsContractResolvesToTheEngineAdapter(): void
    {
        $settings = $this->app->make(ModuleSettingsContract::class);

        self::assertInstanceOf(EngineSettingsAdapter::class, $settings);
    }

    public function testChatSettingsReadMergesBotLevelValuesThroughTheBinding(): void
    {
        $settings = $this->app->make(ModuleSettingsContract::class);

        $settings->patchSettings('alpha', 'bot-1', null, ['strategy' => 'bot', 'ttl' => 300]);
        $settings->patchSettings('alpha', 'bot-1', 100, ['strategy' => 'chat']);

        self::assertSame(
            ['strategy' => 'chat', 'ttl' => 300],
            $settings->settingsFor('alpha', 'bot-1', 100),
        );
        self::assertSame(
            ['strategy' => 'bot', 'ttl' => 300],
            $settings->settingsFor('alpha', 'bot-1'),
        );
    }

    public function testReservedEnabledAtBotScopeUsesTheActivationWriterBinding(): void
    {
        $settings = $this->app->make(ModuleSettingsContract::class);

        $settings->patchSettings('menu', 'bot-1', null, ['enabled' => true]);
        self::assertSame('enabled', $this->activationStatus('bot-1', 'menu'));

        $settings->patchSettings('menu', 'bot-1', null, ['enabled' => false]);
        self::assertSame('disabled', $this->activationStatus('bot-1', 'menu'));

        $row = DB::table('bot_module_activations')
            ->where('bot_id', 'bot-1')
            ->where('module_id', 'menu')
            ->first();
        self::assertNotNull($row);
        self::assertNull($row->module_settings);
    }

    private function activationStatus(string $botId, string $moduleId): ?string
    {
        $status = DB::table('bot_module_activations')
            ->where('bot_id', $botId)
            ->where('module_id', $moduleId)
            ->value('status');

        return is_string($status) ? $status : null;
    }
}
