<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Activation\EngineModuleEnablement;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\CinemaModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\ChatDefaultOffModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\DefaultOffModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\MenuModule;
use Illuminate\Database\ConnectionInterface;

/**
 * Chat-level enablement dispatch (decision Q4): the "{chatId}:__enabled__"
 * key inside module_settings must filter the decision with legacy parity —
 * an explicit bool chat flag wins over the bot status in both directions,
 * the platform gate stays absolute, and an absent/invalid key falls back to
 * the bot-level decision (never default-true). Q11: a descriptor with
 * defaultChatEnabled = false pins chat scope OFF until an explicit chat
 * decision exists, regardless of bot-level rows.
 */
final class EngineChatLevelEnablementTest extends EngineSqliteTestCase
{
    private ModuleActivationReader $reader;

    private EngineModuleEnablement $enablement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = new ModuleActivationReader($this->db, $this->registry([
            CinemaModule::class => true,
            MenuModule::class => true,
            DefaultOffModule::class => true,
            ChatDefaultOffModule::class => true,
        ]));
        $this->enablement = new EngineModuleEnablement($this->reader);
    }

    public function testAbsentChatKeyFallsBackToBotLevelDecision(): void
    {
        self::assertTrue($this->reader->isEffectivelyEnabledForChat('bot-1', CinemaModule::ID, 100));
        self::assertFalse($this->reader->isEffectivelyEnabledForChat('bot-1', DefaultOffModule::ID, 100));

        $this->seedActivation('bot-1', CinemaModule::ID, ModuleActivationReader::STATUS_DISABLED);
        $this->seedActivation('bot-1', MenuModule::ID, ModuleActivationReader::STATUS_ENABLED);

        self::assertFalse($this->reader->isEffectivelyEnabledForChat('bot-1', CinemaModule::ID, 100));
        self::assertTrue($this->reader->isEffectivelyEnabledForChat('bot-1', MenuModule::ID, 100));
    }

    public function testChatFalseOverrideWinsAndIsIsolatedPerChat(): void
    {
        $this->seedActivation('bot-1', CinemaModule::ID, ModuleActivationReader::STATUS_ENABLED, [
            $this->chatKey(100) => false,
        ]);

        self::assertFalse($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 100));
        self::assertTrue($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 200));
        self::assertFalse($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 100));
    }

    public function testOtherChatKeyDoesNotLeakIntoChatWithoutKey(): void
    {
        $this->seedActivation('bot-1', CinemaModule::ID, ModuleActivationReader::STATUS_ENABLED, [
            $this->chatKey(999) => false,
        ]);

        self::assertTrue($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 100));
        self::assertFalse($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 999));
    }

    public function testChatTrueOverrideWinsOverDisabledBotStatus(): void
    {
        $this->seedActivation('bot-1', CinemaModule::ID, ModuleActivationReader::STATUS_DISABLED, [
            $this->chatKey(100) => true,
        ]);

        self::assertTrue($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 100));
        self::assertFalse($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 200));
    }

    public function testPlatformDisabledModuleIgnoresChatOverride(): void
    {
        $reader = new ModuleActivationReader($this->db, $this->registry([
            CinemaModule::class => false,
            MenuModule::class => true,
        ]));

        $this->seedActivation('bot-1', CinemaModule::ID, ModuleActivationReader::STATUS_ENABLED, [
            $this->chatKey(100) => true,
        ]);

        self::assertFalse($reader->isEffectivelyEnabledForChat('bot-1', CinemaModule::ID, 100));
        self::assertFalse($reader->isEffectivelyEnabledForChat('bot-1', 'unknown-module', 100));
    }

    public function testChatOverrideFromJsonStringPayloadIsHonored(): void
    {
        $this->seedActivation('bot-1', CinemaModule::ID, ModuleActivationReader::STATUS_ENABLED, [
            $this->chatKey(100) => false,
        ]);

        $raw = $this->db->table('bot_module_activations')
            ->where('bot_id', 'bot-1')
            ->where('module_id', CinemaModule::ID)
            ->value('module_settings');
        self::assertIsString($raw);

        self::assertFalse($this->reader->isEffectivelyEnabledForChat('bot-1', CinemaModule::ID, 100));
        self::assertTrue($this->reader->isEffectivelyEnabledForChat('bot-1', CinemaModule::ID, 200));
    }

    public function testChatOverrideFromDecodedArrayPayloadIsHonored(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('table')->willReturn(new class () {
            public function where(mixed ...$conditions): static
            {
                return $this;
            }

            public function first(): object
            {
                return (object) [
                    'bot_id' => 'bot-1',
                    'module_id' => CinemaModule::ID,
                    'status' => ModuleActivationReader::STATUS_ENABLED,
                    'module_settings' => ['100:'.ModuleActivationReader::CHAT_ENABLED_KEY => false],
                    'revision' => 1,
                ];
            }
        });

        $reader = new ModuleActivationReader($connection, $this->registry([
            CinemaModule::class => true,
        ]));

        self::assertFalse($reader->isEffectivelyEnabledForChat('bot-1', CinemaModule::ID, 100));
        self::assertTrue($reader->isEffectivelyEnabledForChat('bot-1', CinemaModule::ID, 200));
    }

    public function testNonBoolChatOverrideIsIgnored(): void
    {
        $this->seedActivation('bot-1', CinemaModule::ID, ModuleActivationReader::STATUS_DISABLED, [
            $this->chatKey(100) => 'false',
            $this->chatKey(101) => null,
        ]);
        $this->seedActivation('bot-2', CinemaModule::ID, ModuleActivationReader::STATUS_ENABLED, [
            $this->chatKey(100) => 0,
        ]);

        self::assertFalse($this->reader->isEffectivelyEnabledForChat('bot-1', CinemaModule::ID, 100));
        self::assertFalse($this->reader->isEffectivelyEnabledForChat('bot-1', CinemaModule::ID, 101));
        self::assertTrue($this->reader->isEffectivelyEnabledForChat('bot-2', CinemaModule::ID, 100));
    }

    public function testRefreshForOneChatKeepsSiblingChatMemo(): void
    {
        $this->seedActivation('bot-1', CinemaModule::ID, ModuleActivationReader::STATUS_ENABLED);

        self::assertTrue($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 100));
        self::assertTrue($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 200));

        $this->db->table('bot_module_activations')
            ->where('bot_id', 'bot-1')
            ->where('module_id', CinemaModule::ID)
            ->update([
                'module_settings' => json_encode([
                    $this->chatKey(100) => false,
                    $this->chatKey(200) => false,
                ], JSON_THROW_ON_ERROR),
            ]);

        self::assertTrue($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 100));

        $this->enablement->refresh('bot-1', 100);

        self::assertFalse($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 100));
        self::assertTrue($this->enablement->isEnabled(CinemaModule::ID, 'bot-1', 200));
    }

    public function testDefaultChatEnabledFalseKeepsChatOffWithoutRow(): void
    {
        self::assertFalse($this->reader->isEffectivelyEnabledForChat('bot-1', ChatDefaultOffModule::ID, 100));
        self::assertTrue($this->reader->isEffectivelyEnabled('bot-1', ChatDefaultOffModule::ID), 'bot scope must stay ON');
    }

    public function testDefaultChatEnabledFalseHonorsExplicitChatSentinels(): void
    {
        $this->seedActivation('bot-1', ChatDefaultOffModule::ID, ModuleActivationReader::STATUS_ENABLED, [
            $this->chatKey(100) => true,
            $this->chatKey(200) => false,
        ]);

        self::assertTrue($this->reader->isEffectivelyEnabledForChat('bot-1', ChatDefaultOffModule::ID, 100));
        self::assertFalse($this->reader->isEffectivelyEnabledForChat('bot-1', ChatDefaultOffModule::ID, 200));
    }

    public function testDefaultChatEnabledFalseKeepsChatsOffWhenBotRowIsEnabled(): void
    {
        $this->seedActivation('bot-1', ChatDefaultOffModule::ID, ModuleActivationReader::STATUS_ENABLED);

        self::assertFalse($this->reader->isEffectivelyEnabledForChat('bot-1', ChatDefaultOffModule::ID, 100));
        self::assertFalse($this->enablement->isEnabled(ChatDefaultOffModule::ID, 'bot-1', 100));
        self::assertTrue($this->reader->isEffectivelyEnabled('bot-1', ChatDefaultOffModule::ID), 'settings write must not change bot scope');
    }

    public function testPlatformGateBeatsChatTrueForDefaultChatEnabledFalseModule(): void
    {
        $reader = new ModuleActivationReader($this->db, $this->registry([
            ChatDefaultOffModule::class => false,
        ]));

        $this->seedActivation('bot-1', ChatDefaultOffModule::ID, ModuleActivationReader::STATUS_ENABLED, [
            $this->chatKey(100) => true,
        ]);

        self::assertFalse($reader->isEffectivelyEnabledForChat('bot-1', ChatDefaultOffModule::ID, 100));
    }

    /** @param array<string, mixed> $moduleSettings */
    private function seedActivation(string $botId, string $moduleId, string $status, array $moduleSettings = []): void
    {
        $this->db->table('bot_module_activations')->insert([
            'bot_id' => $botId,
            'module_id' => $moduleId,
            'status' => $status,
            'module_settings' => $moduleSettings === [] ? null : json_encode($moduleSettings, JSON_THROW_ON_ERROR),
            'revision' => 1,
            'created_at' => '2026-09-29 00:00:00',
            'updated_at' => '2026-09-29 00:00:00',
        ]);
    }

    private function chatKey(int $chatId): string
    {
        return $chatId.':'.ModuleActivationReader::CHAT_ENABLED_KEY;
    }
}
