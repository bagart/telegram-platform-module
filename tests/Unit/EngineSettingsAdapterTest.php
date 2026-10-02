<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Activation\EngineSettingsAdapter;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use BAGArt\TelegramModuleEngine\Settings\DatabaseSettingsStorage;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\MenuModule;

/**
 * Engine driver side of the lib ModuleSettingsContract (decisions Q2/Q3):
 * chat reads overlay bot-level values, patches merge into the raw map of
 * exactly one scope with null-removal, the reserved `enabled` key flips
 * enablement instead of landing in settings, enumeration returns explicit
 * (non-inherited) per-scope maps, and the after-write hook fires per patch.
 */
final class EngineSettingsAdapterTest extends EngineSqliteTestCase
{
    private const string MODULE = 'alpha';

    private EngineSettingsAdapter $adapter;

    private DatabaseSettingsStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->registry([
            MenuModule::class => true,
        ]);

        $this->storage = new DatabaseSettingsStorage($this->db);
        $this->adapter = new EngineSettingsAdapter(
            $this->storage,
            new ModuleActivationService($this->db, $registry, new ModuleActivationReader($this->db, $registry)),
        );
    }

    public function testChatReadMergesBotLevelSettingsWithChatOverrideWinning(): void
    {
        $this->seed('bot-1', self::MODULE, [
            'strategy' => 'bot',
            'ttl' => 300,
            '100:strategy' => 'chat',
        ]);

        self::assertSame(
            ['strategy' => 'chat', 'ttl' => 300],
            $this->adapter->settingsFor(self::MODULE, 'bot-1', 100),
        );

        self::assertSame(
            ['strategy' => 'bot', 'ttl' => 300],
            $this->adapter->settingsFor(self::MODULE, 'bot-1', 200),
        );
    }

    public function testBotScopeReadReturnsBotLevelSettingsOnly(): void
    {
        $this->seed('bot-1', self::MODULE, [
            'strategy' => 'bot',
            '100:strategy' => 'chat',
        ]);

        self::assertSame(['strategy' => 'bot'], $this->adapter->settingsFor(self::MODULE, 'bot-1'));
        self::assertSame(['strategy' => 'bot'], $this->adapter->settingsFor(self::MODULE, 'bot-1', null));
    }

    public function testPatchMergesIntoTheBotScopeRawMap(): void
    {
        $this->seed('bot-1', self::MODULE, ['ttl' => 300]);

        $this->adapter->patchSettings(self::MODULE, 'bot-1', null, ['strategy' => 'random', 'ttl' => 600]);

        self::assertSame(
            ['ttl' => 600, 'strategy' => 'random'],
            $this->rawSettings('bot-1', self::MODULE),
        );
    }

    public function testPatchNullRemovesTheKeyAtItsOwnScope(): void
    {
        $this->seed('bot-1', self::MODULE, [
            'ttl' => 300,
            '100:ttl' => 600,
        ]);

        $this->adapter->patchSettings(self::MODULE, 'bot-1', 100, ['ttl' => null]);

        self::assertSame(['ttl' => 300], $this->rawSettings('bot-1', self::MODULE));

        $this->adapter->patchSettings(self::MODULE, 'bot-1', null, ['ttl' => null]);

        self::assertSame([], $this->rawSettings('bot-1', self::MODULE));
        self::assertSame([], $this->adapter->settingsFor(self::MODULE, 'bot-1', 100));
    }

    public function testPatchAtChatScopeMergesOnlyTheChatOwnStoredMap(): void
    {
        $this->seed('bot-1', self::MODULE, ['ttl' => 300, 'strategy' => 'bot']);

        $this->adapter->patchSettings(self::MODULE, 'bot-1', 100, ['strategy' => 'chat']);

        $raw = $this->rawSettings('bot-1', self::MODULE);
        self::assertSame(
            ['ttl' => 300, 'strategy' => 'bot', '100:strategy' => 'chat'],
            $raw,
        );
        self::assertArrayNotHasKey('100:ttl', $raw);

        self::assertSame(
            ['ttl' => 300, 'strategy' => 'chat'],
            $this->adapter->settingsFor(self::MODULE, 'bot-1', 100),
        );
    }

    public function testPatchReservedEnabledAtChatScopeStoresTheSentinelOutsideSettings(): void
    {
        $this->adapter->patchSettings(self::MODULE, 'bot-1', 100, ['enabled' => true]);

        self::assertSame(
            ['100:'.ModuleActivationReader::CHAT_ENABLED_KEY => true],
            $this->rawSettings('bot-1', self::MODULE),
        );
        self::assertSame([], $this->adapter->settingsFor(self::MODULE, 'bot-1', 100));

        // Non-bool reserved values are ignored silently (legacy parity):
        // they neither store nor remove the sentinel.
        $this->adapter->patchSettings(self::MODULE, 'bot-1', 100, ['enabled' => null]);
        $this->adapter->patchSettings(self::MODULE, 'bot-1', 100, ['enabled' => 'yes']);

        self::assertSame(
            ['100:'.ModuleActivationReader::CHAT_ENABLED_KEY => true],
            $this->rawSettings('bot-1', self::MODULE),
        );

        // The sentinel-only chat scope is an enablement-only scope: reported
        // with an empty map (sentinel excluded), bot scope last.
        self::assertSame(
            [
                ['botId' => 'bot-1', 'chatId' => 100, 'settings' => []],
                ['botId' => 'bot-1', 'chatId' => null, 'settings' => []],
            ],
            $this->adapter->chatsWithSettings(self::MODULE),
        );
    }

    public function testPatchReservedEnabledAtBotScopeFlipsTheActivationStatus(): void
    {
        $this->adapter->patchSettings(MenuModule::ID, 'bot-1', null, ['enabled' => true]);

        self::assertSame(ModuleActivationReader::STATUS_ENABLED, $this->statusOf('bot-1', MenuModule::ID));
        self::assertSame([], $this->rawSettings('bot-1', MenuModule::ID));

        $this->adapter->patchSettings(MenuModule::ID, 'bot-1', null, ['enabled' => false]);

        self::assertSame(ModuleActivationReader::STATUS_DISABLED, $this->statusOf('bot-1', MenuModule::ID));
        self::assertSame([], $this->rawSettings('bot-1', MenuModule::ID));
        self::assertArrayNotHasKey('enabled', $this->adapter->settingsFor(MenuModule::ID, 'bot-1'));
    }

    public function testPatchReservedEnabledIgnoresNonBoolValuesAtBotScope(): void
    {
        $this->adapter->patchSettings(MenuModule::ID, 'bot-1', null, ['enabled' => true]);
        $this->adapter->patchSettings(MenuModule::ID, 'bot-1', null, ['enabled' => false]);
        self::assertSame(ModuleActivationReader::STATUS_DISABLED, $this->statusOf('bot-1', MenuModule::ID));

        $this->adapter->patchSettings(MenuModule::ID, 'bot-1', null, ['enabled' => null]);
        self::assertSame(ModuleActivationReader::STATUS_DISABLED, $this->statusOf('bot-1', MenuModule::ID));

        $this->adapter->patchSettings(MenuModule::ID, 'bot-1', null, ['enabled' => 'yes']);
        self::assertSame(ModuleActivationReader::STATUS_DISABLED, $this->statusOf('bot-1', MenuModule::ID));

        self::assertSame([], $this->rawSettings('bot-1', MenuModule::ID));
    }

    public function testChatsWithSettingsReturnsExplicitScopeMapsWithoutInheritance(): void
    {
        $this->seed('bot-1', self::MODULE, [
            'ttl' => 300,
            '100:strategy' => 'chat-100',
            '100:'.ModuleActivationReader::CHAT_ENABLED_KEY => true,
            '200:strategy' => 'chat-200',
        ]);
        $this->seed('bot-2', self::MODULE, ['ttl' => 600]);

        self::assertSame(
            [
                ['botId' => 'bot-1', 'chatId' => 200, 'settings' => ['strategy' => 'chat-200']],
                ['botId' => 'bot-1', 'chatId' => 100, 'settings' => ['strategy' => 'chat-100']],
                ['botId' => 'bot-1', 'chatId' => null, 'settings' => ['ttl' => 300]],
                ['botId' => 'bot-2', 'chatId' => null, 'settings' => ['ttl' => 600]],
            ],
            $this->adapter->chatsWithSettings(self::MODULE),
        );

        self::assertSame(
            [['botId' => 'bot-2', 'chatId' => null, 'settings' => ['ttl' => 600]]],
            $this->adapter->chatsWithSettings(self::MODULE, 'bot-2'),
        );

        self::assertSame([], $this->adapter->chatsWithSettings('module-without-scopes'));
    }

    public function testModulesSharingAFieldNameKeepTheirOwnValues(): void
    {
        $this->seed('bot-1', 'alpha', ['shared' => 'a']);
        $this->seed('bot-1', 'beta', ['shared' => 'b']);

        self::assertSame(['shared' => 'a'], $this->adapter->settingsFor('alpha', 'bot-1'));
        self::assertSame(['shared' => 'b'], $this->adapter->settingsFor('beta', 'bot-1'));
    }

    public function testAfterWriteHookReceivesEveryPatch(): void
    {
        $calls = [];
        $this->adapter->setAfterWrite(static function (string $moduleId, string $botId, ?int $chatId, array $patch) use (&$calls): void {
            $calls[] = [$moduleId, $botId, $chatId, $patch];
        });

        $this->adapter->patchSettings(self::MODULE, 'bot-1', null, ['ttl' => 300]);
        $this->adapter->patchSettings(self::MODULE, 'bot-1', 100, ['ttl' => null, 'enabled' => false]);

        self::assertSame(
            [
                [self::MODULE, 'bot-1', null, ['ttl' => 300]],
                [self::MODULE, 'bot-1', 100, ['ttl' => null, 'enabled' => false]],
            ],
            $calls,
        );
    }

    /** @param array<string, mixed> $settings */
    private function seed(string $botId, string $moduleId, array $settings): void
    {
        $this->db->table('bot_module_activations')->insert([
            'bot_id' => $botId,
            'module_id' => $moduleId,
            'status' => ModuleActivationReader::STATUS_ENABLED,
            'module_settings' => $settings === [] ? null : json_encode($settings, JSON_THROW_ON_ERROR),
            'revision' => 1,
            'created_at' => '2026-09-29 00:00:00',
            'updated_at' => '2026-09-29 00:00:00',
        ]);
    }

    /** @return array<string, mixed> raw stored keys of one activation row */
    private function rawSettings(string $botId, string $moduleId): array
    {
        $raw = $this->db->table('bot_module_activations')
            ->where('bot_id', $botId)
            ->where('module_id', $moduleId)
            ->value('module_settings');

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function statusOf(string $botId, string $moduleId): ?string
    {
        $status = $this->db->table('bot_module_activations')
            ->where('bot_id', $botId)
            ->where('module_id', $moduleId)
            ->value('status');

        return is_string($status) ? $status : null;
    }
}
