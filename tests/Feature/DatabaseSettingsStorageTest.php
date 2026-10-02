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

    public function test_set_after_forget_keeps_field_deleted(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', [
            'proxy.selection_strategy' => 'weighted',
            'proxy.lease_ttl_seconds' => 300,
        ]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $storage->forget('bot-1', 'proxy.settings', 'proxy.selection_strategy');

        // Set a different field — the forgotten field must stay deleted.
        $storage->set(new ResolvedSetting(
            botId: 'bot-1',
            screenId: 'proxy.settings',
            fieldId: 'proxy.max_retries',
            value: 5,
        ));

        $result = $storage->get('bot-1', 'proxy.settings', 'proxy.selection_strategy');
        self::assertNull($result);

        $result = $storage->get('bot-1', 'proxy.settings', 'proxy.lease_ttl_seconds');
        self::assertSame(300, $result->value);

        $result = $storage->get('bot-1', 'proxy.settings', 'proxy.max_retries');
        self::assertSame(5, $result->value);
    }

    public function test_all_merges_settings_across_multiple_activation_rows(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', ['proxy.selection_strategy' => 'random']);
        $this->seedActivation('bot-1', 'nettools.settings', ['nettools.dns_server' => '8.8.8.8']);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $all = $storage->all('bot-1');

        self::assertCount(2, $all);

        $fieldIds = array_map(static fn (ResolvedSetting $s) => $s->fieldId, $all);
        self::assertContains('proxy.selection_strategy', $fieldIds);
        self::assertContains('nettools.dns_server', $fieldIds);
    }

    public function test_chat_scoped_settings_are_filtered_correctly(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', [
            'proxy.selection_strategy' => 'random',
            '100:proxy.selection_strategy' => 'weighted',
        ]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $botLevel = $storage->all('bot-1');
        self::assertCount(1, $botLevel);
        self::assertSame('proxy.selection_strategy', $botLevel[0]->fieldId);
        self::assertSame('random', $botLevel[0]->value);

        $chatLevel = $storage->all('bot-1', chatId: 100);
        self::assertCount(1, $chatLevel);
        self::assertSame('proxy.selection_strategy', $chatLevel[0]->fieldId);
        self::assertSame('weighted', $chatLevel[0]->value);
    }

    public function test_set_on_missing_activation_row_creates_row(): void
    {
        $storage = new DatabaseSettingsStorage(DB::connection());

        $storage->set(new ResolvedSetting(
            botId: 'bot-new',
            screenId: 'proxy.settings',
            fieldId: 'proxy.selection_strategy',
            value: 'random',
        ));

        $result = $storage->get('bot-new', 'proxy.settings', 'proxy.selection_strategy');
        self::assertNotNull($result);
        self::assertSame('random', $result->value);
    }

    public function test_chat_level_overrides_bot_level_on_get(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', [
            'proxy.selection_strategy' => 'random',
            '100:proxy.selection_strategy' => 'weighted',
        ]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $botValue = $storage->get('bot-1', 'proxy.settings', 'proxy.selection_strategy');
        self::assertNotNull($botValue);
        self::assertSame('random', $botValue->value);
        self::assertNull($botValue->chatId);

        $chatValue = $storage->get('bot-1', 'proxy.settings', 'proxy.selection_strategy', chatId: 100);
        self::assertNotNull($chatValue);
        self::assertSame('weighted', $chatValue->value);
        self::assertSame(100, $chatValue->chatId);
    }

    public function test_all_chat_scoped_returns_only_chat_entries(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', [
            'proxy.selection_strategy' => 'random',
            'proxy.lease_ttl_seconds' => 300,
            '100:proxy.selection_strategy' => 'weighted',
            '100:proxy.lease_ttl_seconds' => 600,
            '200:proxy.selection_strategy' => 'roundrobin',
        ]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $chat100 = $storage->all('bot-1', chatId: 100);
        self::assertCount(2, $chat100);
        foreach ($chat100 as $setting) {
            self::assertSame(100, $setting->chatId);
            self::assertContains($setting->fieldId, ['proxy.selection_strategy', 'proxy.lease_ttl_seconds']);
        }

        $chat200 = $storage->all('bot-1', chatId: 200);
        self::assertCount(1, $chat200);
        self::assertSame(200, $chat200[0]->chatId);
        self::assertSame('proxy.selection_strategy', $chat200[0]->fieldId);
        self::assertSame('roundrobin', $chat200[0]->value);

        $botOnly = $storage->all('bot-1');
        self::assertCount(2, $botOnly);
        foreach ($botOnly as $setting) {
            self::assertNull($setting->chatId);
        }
    }

    public function test_forget_with_chat_id_removes_only_chat_entry(): void
    {
        $this->seedActivation('bot-1', 'proxy.settings', [
            'proxy.selection_strategy' => 'random',
            '100:proxy.selection_strategy' => 'weighted',
        ]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $storage->forget('bot-1', 'proxy.settings', 'proxy.selection_strategy', chatId: 100);

        $chatValue = $storage->get('bot-1', 'proxy.settings', 'proxy.selection_strategy', chatId: 100);
        self::assertNull($chatValue);

        $botValue = $storage->get('bot-1', 'proxy.settings', 'proxy.selection_strategy');
        self::assertNotNull($botValue);
        self::assertSame('random', $botValue->value);

        $botAll = $storage->all('bot-1');
        self::assertCount(1, $botAll);
        self::assertSame('proxy.selection_strategy', $botAll[0]->fieldId);
    }

    public function test_concurrent_set_fields_both_survive_pgsql_only(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Concurrent JSONB merge test requires PostgreSQL');
        }

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

        $storage->set(new ResolvedSetting(
            botId: 'bot-1',
            screenId: 'proxy.settings',
            fieldId: 'proxy.max_retries',
            value: 5,
        ));

        $all = $storage->all('bot-1');
        $values = [];
        foreach ($all as $s) {
            $values[$s->fieldId] = $s->value;
        }

        self::assertSame(300, $values['proxy.lease_ttl_seconds']);
        self::assertSame('random', $values['proxy.selection_strategy']);
        self::assertSame(5, $values['proxy.max_retries']);
    }

    public function test_set_creates_activation_row_with_correct_columns(): void
    {
        $storage = new DatabaseSettingsStorage(DB::connection());

        $storage->set(new ResolvedSetting(
            botId: 'bot-fresh',
            screenId: 'nettools.settings',
            fieldId: 'nettools.dns_server',
            value: '1.1.1.1',
        ));

        $row = DB::table('bot_module_activations')
            ->where('bot_id', 'bot-fresh')
            ->where('module_id', 'nettools.settings')
            ->first();

        self::assertNotNull($row);
        self::assertSame('enabled', $row->status);
        self::assertSame(1, (int) $row->revision);
        self::assertNotNull($row->created_at);
        self::assertNotNull($row->updated_at);

        $settings = json_decode((string) $row->module_settings, true);
        self::assertSame('1.1.1.1', $settings['nettools.dns_server']);
    }

    private function seedActivation(string $botId, string $moduleId, array $settings = []): void
    {
        DB::table('bot_module_activations')->insertOrIgnore([
            'bot_id' => $botId,
            'module_id' => $moduleId,
            'status' => 'enabled',
            'module_settings' => $settings !== [] ? json_encode($settings, JSON_THROW_ON_ERROR) : null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
