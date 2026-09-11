<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Feature;

use BAGArt\TelegramModuleEngine\Settings\DatabaseSettingsStorage;
use BAGArt\TelegramModuleEngine\Settings\ResolvedSetting;
use BAGArt\TelegramModuleEngine\Settings\SettingsStorageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseSettingsStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_to_database_settings_storage(): void
    {
        $storage = $this->app->make(SettingsStorageContract::class);

        self::assertInstanceOf(DatabaseSettingsStorage::class, $storage);
    }

    public function test_get_returns_null_when_no_row(): void
    {
        $storage = new DatabaseSettingsStorage(DB::connection());

        $result = $storage->get('bot-999', 'proxy.settings', 'proxy.selection_strategy');

        self::assertNull($result);
    }

    public function test_get_returns_null_when_field_not_in_settings(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', ['proxy.lease_ttl_seconds' => 300]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $result = $storage->get('bot-1', 'proxy.settings', 'proxy.selection_strategy');

        self::assertNull($result);
    }

    public function test_set_stores_field_in_json(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings');

        $storage = new DatabaseSettingsStorage(DB::connection());

        $storage->set(new ResolvedSetting(
            botId: 'bot-1',
            screenId: 'proxy.settings',
            fieldId: 'proxy.selection_strategy',
            value: 'random',
        ));

        $row = DB::table('bot_module_activations')
            ->where('bot_id', 'bot-1')
            ->where('module_id', 'proxy.settings')
            ->first();

        $settings = json_decode((string) $row->module_settings, true);
        self::assertSame('random', $settings['proxy.selection_strategy']);
    }

    public function test_set_preserves_existing_fields(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', [
            'proxy.lease_ttl_seconds' => 300,
        ]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $storage->set(new ResolvedSetting(
            botId: 'bot-1',
            screenId: 'proxy.settings',
            fieldId: 'proxy.selection_strategy',
            value: 'random',
        ));

        $row = DB::table('bot_module_activations')
            ->where('bot_id', 'bot-1')
            ->where('module_id', 'proxy.settings')
            ->first();

        $settings = json_decode((string) $row->module_settings, true);
        self::assertSame(300, $settings['proxy.lease_ttl_seconds']);
        self::assertSame('random', $settings['proxy.selection_strategy']);
    }

    public function test_get_returns_stored_value(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', [
            'proxy.selection_strategy' => 'weighted',
        ]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $result = $storage->get('bot-1', 'proxy.settings', 'proxy.selection_strategy');

        self::assertNotNull($result);
        self::assertSame('weighted', $result->value);
        self::assertSame('bot-1', $result->botId);
        self::assertSame('proxy.settings', $result->screenId);
        self::assertSame('proxy.selection_strategy', $result->fieldId);
    }

    public function test_forget_removes_field(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', [
            'proxy.selection_strategy' => 'weighted',
            'proxy.lease_ttl_seconds' => 300,
        ]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $storage->forget('bot-1', 'proxy.settings', 'proxy.selection_strategy');

        $result = $storage->get('bot-1', 'proxy.settings', 'proxy.selection_strategy');
        self::assertNull($result);

        // Other field preserved.
        $result = $storage->get('bot-1', 'proxy.settings', 'proxy.lease_ttl_seconds');
        self::assertNotNull($result);
        self::assertSame(300, $result->value);
    }

    public function test_forget_on_nonexistent_field_is_noop(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', [
            'proxy.lease_ttl_seconds' => 300,
        ]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $storage->forget('bot-1', 'proxy.settings', 'proxy.nonexistent');

        $result = $storage->get('bot-1', 'proxy.settings', 'proxy.lease_ttl_seconds');
        self::assertNotNull($result);
    }

    public function test_all_returns_bot_scoped_settings(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', [
            'proxy.selection_strategy' => 'random',
            'proxy.lease_ttl_seconds' => 300,
        ]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $all = $storage->all('bot-1');

        self::assertCount(2, $all);
        self::assertSame('proxy.selection_strategy', $all[0]->fieldId);
        self::assertSame('random', $all[0]->value);
    }

    public function test_all_returns_empty_for_unknown_bot(): void
    {
        $storage = new DatabaseSettingsStorage(DB::connection());

        $all = $storage->all('bot-unknown');

        self::assertCount(0, $all);
    }

    public function test_settings_are_tenant_scoped(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', ['proxy.selection_strategy' => 'random']);
        $this->seedActivation('bot-2', 'proxy.settings', ['proxy.selection_strategy' => 'weighted']);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $b1 = $storage->get('bot-1', 'proxy.settings', 'proxy.selection_strategy');
        $b2 = $storage->get('bot-2', 'proxy.settings', 'proxy.selection_strategy');

        self::assertSame('random', $b1->value);
        self::assertSame('weighted', $b2->value);
    }

    private function seedActivation(string $botId, string $moduleId, array $settings = []): void
    {
        DB::table('bot_module_activations')->insertOrIgnore([
            'bot_id' => $botId,
            'module_id' => $moduleId,
            'status' => 'enabled',
            'module_settings' => $settings !== [] ? $settings : null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
