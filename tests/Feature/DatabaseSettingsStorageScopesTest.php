<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Feature;

use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Settings\DatabaseSettingsStorage;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Storage layer behind ModuleSettingsContract::chatsWithSettings(): key
 * convention parsing (plain = bot scope, "{chatId}:" = chat scope, negative
 * chat ids included), chat-enablement sentinel exclusion, enablement-only
 * scope inclusion, legacy-parity ordering (chat id desc first, bot scopes
 * last), bot filtering, and the cross-module field-id collision fix in all().
 */
final class DatabaseSettingsStorageScopesTest extends TestCase
{
    use RefreshDatabase;

    public function testAllKeepsModulesSharingAFieldNameApart(): void
    {
        $this->seedActivation('bot-1', 'alpha', ['shared' => 'a']);
        $this->seedActivation('bot-1', 'beta', ['shared' => 'b']);

        $storage = new DatabaseSettingsStorage(DB::connection());

        $values = [];
        foreach ($storage->all('bot-1') as $setting) {
            $values[$setting->screenId] = $setting->value;
        }

        self::assertSame(['alpha' => 'a', 'beta' => 'b'], $values);
    }

    public function testScopesWithSettingsParsesTheStoredKeyConvention(): void
    {
        $this->seedActivation('bot-1', 'alpha', [
            'ttl' => 300,
            '100:strategy' => 'chat-100',
            '100:'.ModuleActivationReader::CHAT_ENABLED_KEY => true,
            '-50:strategy' => 'chat--50',
            'not-a-chat:strategy' => 'unaddressable',
        ]);
        $this->seedActivation('bot-2', 'alpha', ['ttl' => 600]);
        $this->seedActivation('bot-1', 'beta', ['ttl' => 900]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        self::assertSame(
            [
                ['botId' => 'bot-1', 'chatId' => 100, 'settings' => ['strategy' => 'chat-100']],
                ['botId' => 'bot-1', 'chatId' => -50, 'settings' => ['strategy' => 'chat--50']],
                ['botId' => 'bot-1', 'chatId' => null, 'settings' => ['ttl' => 300]],
                ['botId' => 'bot-2', 'chatId' => null, 'settings' => ['ttl' => 600]],
            ],
            $storage->scopesWithSettings('alpha'),
        );

        self::assertSame(
            [['botId' => 'bot-2', 'chatId' => null, 'settings' => ['ttl' => 600]]],
            $storage->scopesWithSettings('alpha', 'bot-2'),
        );

        self::assertSame(
            [['botId' => 'bot-1', 'chatId' => null, 'settings' => ['ttl' => 900]]],
            $storage->scopesWithSettings('beta'),
        );

        self::assertSame([], $storage->scopesWithSettings('module-without-rows'));
    }

    public function testScopesWithSettingsOrdersChatScopesGloballyByChatIdDescendingWithBotScopesLast(): void
    {
        $this->seedActivation('bot-1', 'alpha', ['100:strategy' => 'low']);
        $this->seedActivation('bot-2', 'alpha', ['250:strategy' => 'high']);

        $storage = new DatabaseSettingsStorage(DB::connection());

        self::assertSame(
            [
                ['botId' => 'bot-2', 'chatId' => 250, 'settings' => ['strategy' => 'high']],
                ['botId' => 'bot-1', 'chatId' => 100, 'settings' => ['strategy' => 'low']],
                ['botId' => 'bot-1', 'chatId' => null, 'settings' => []],
                ['botId' => 'bot-2', 'chatId' => null, 'settings' => []],
            ],
            $storage->scopesWithSettings('alpha'),
        );
    }

    public function testScopesWithSettingsIncludesEnablementOnlyScopesWithEmptyMaps(): void
    {
        $this->seedActivation('bot-1', 'alpha');
        $this->seedActivation('bot-2', 'alpha', [
            '100:'.ModuleActivationReader::CHAT_ENABLED_KEY => false,
        ]);

        $storage = new DatabaseSettingsStorage(DB::connection());

        self::assertSame(
            [
                ['botId' => 'bot-2', 'chatId' => 100, 'settings' => []],
                ['botId' => 'bot-1', 'chatId' => null, 'settings' => []],
                ['botId' => 'bot-2', 'chatId' => null, 'settings' => []],
            ],
            $storage->scopesWithSettings('alpha'),
        );
    }

    public function testScopesWithSettingsAcceptsAnAlreadyDecodedArrayPayload(): void
    {
        $query = new class () {
            public function where(mixed ...$conditions): static
            {
                return $this;
            }

            public function whereNotNull(mixed ...$columns): static
            {
                return $this;
            }

            public function orderBy(mixed ...$columns): static
            {
                return $this;
            }

            /** @return list<object> */
            public function get(): array
            {
                return [
                    (object) [
                        'bot_id' => 'bot-1',
                        'module_id' => 'alpha',
                        'module_settings' => [
                            'ttl' => 300,
                            '100:strategy' => 'chat-100',
                            '100:'.ModuleActivationReader::CHAT_ENABLED_KEY => false,
                        ],
                    ],
                ];
            }
        };

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('table')->willReturn($query);

        $storage = new DatabaseSettingsStorage($connection);

        self::assertSame(
            [
                ['botId' => 'bot-1', 'chatId' => 100, 'settings' => ['strategy' => 'chat-100']],
                ['botId' => 'bot-1', 'chatId' => null, 'settings' => ['ttl' => 300]],
            ],
            $storage->scopesWithSettings('alpha'),
        );
    }

    private function seedActivation(string $botId, string $moduleId, array $settings = []): void
    {
        DB::table('bot_module_activations')->insertOrIgnore([
            'bot_id' => $botId,
            'module_id' => $moduleId,
            'status' => ModuleActivationReader::STATUS_ENABLED,
            'module_settings' => $settings !== [] ? json_encode($settings, JSON_THROW_ON_ERROR) : null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
