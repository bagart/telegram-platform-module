<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Activation\EngineModuleEnablement;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\CinemaModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\ChatDefaultOffModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\MenuModule;

final class EngineModuleEnablementTest extends EngineSqliteTestCase
{
    private ModuleActivationService $service;

    private ModuleActivationReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $registry = $this->registry([
            CinemaModule::class => true,
            MenuModule::class => true,
            ChatDefaultOffModule::class => true,
        ]);
        $this->reader = new ModuleActivationReader($this->db, $registry);
        $this->service = new ModuleActivationService($this->db, $registry, $this->reader);
    }

    public function testIsEnabledFollowsEngineActivationStore(): void
    {
        $enablement = new EngineModuleEnablement($this->reader);

        // cinema: descriptor defaultEnabled = true; menu: opt-in (default false).
        self::assertTrue($enablement->isEnabled('cinema', 'bot-1', 100));
        self::assertFalse($enablement->isEnabled('menu', 'bot-1', 100));

        $this->service->enable('bot-1', 'menu');
        $enablement->refresh('bot-1');

        self::assertTrue($enablement->isEnabled('menu', 'bot-1', 100));
    }

    public function testMemoAvoidsRepeatedReadsUntilRefresh(): void
    {
        $enablement = new EngineModuleEnablement($this->reader);

        self::assertTrue($enablement->isEnabled('cinema', 'bot-1', 100));

        // Store changes behind the adapter's back: memo keeps the stale answer.
        $this->service->disable('bot-1', 'cinema');
        self::assertTrue($enablement->isEnabled('cinema', 'bot-1', 100));

        $enablement->refresh('bot-1');
        self::assertFalse($enablement->isEnabled('cinema', 'bot-1', 100));
    }

    public function testRefreshForOneBotDoesNotTouchOtherBotsMemo(): void
    {
        $enablement = new EngineModuleEnablement($this->reader);
        $this->service->disable('bot-2', 'cinema');

        self::assertFalse($enablement->isEnabled('cinema', 'bot-2', 100));
        self::assertTrue($enablement->isEnabled('cinema', 'bot-1', 100));

        $this->service->enable('bot-2', 'cinema');
        $enablement->refresh('bot-1');

        // bot-2 memo untouched (stale false), bot-1 re-read (still true).
        self::assertFalse($enablement->isEnabled('cinema', 'bot-2', 100));
        self::assertTrue($enablement->isEnabled('cinema', 'bot-1', 100));
    }

    public function testTwoServiceInstancesShareFreshStateAfterRefresh(): void
    {
        $reader1 = new ModuleActivationReader($this->db, $this->registry([
            CinemaModule::class => true,
            MenuModule::class => true,
        ]));
        $reader2 = new ModuleActivationReader($this->db, $this->registry([
            CinemaModule::class => true,
            MenuModule::class => true,
        ]));

        $instanceA = new EngineModuleEnablement($reader1);
        $instanceB = new EngineModuleEnablement($reader2);

        self::assertTrue($instanceA->isEnabled('cinema', 'bot-1', 100));
        self::assertTrue($instanceB->isEnabled('cinema', 'bot-1', 100));

        $this->service->disable('bot-1', 'cinema');

        $instanceA->refresh('bot-1');
        self::assertFalse($instanceA->isEnabled('cinema', 'bot-1', 100));

        $instanceB->refresh('bot-1');
        self::assertFalse($instanceB->isEnabled('cinema', 'bot-1', 100));
    }

    public function testNullChatIdResolvesBotScopeDecision(): void
    {
        $enablement = new EngineModuleEnablement($this->reader);

        self::assertTrue($enablement->isEnabled('cinema', 'bot-1'), 'defaultEnabled=true, no row');
        self::assertFalse($enablement->isEnabled('menu', 'bot-1'), 'opt-in module without a row');

        $this->service->disable('bot-1', 'cinema');
        $enablement->refresh('bot-1');

        self::assertFalse($enablement->isEnabled('cinema', 'bot-1'));
    }

    public function testBotScopeAndChatScopeMemosUseDistinctKeys(): void
    {
        $enablement = new EngineModuleEnablement($this->reader);

        // chat-default-off: bot scope ON (defaultEnabled), chat scope OFF (defaultChatEnabled).
        self::assertTrue($enablement->isEnabled(ChatDefaultOffModule::ID, 'bot-1'));
        self::assertFalse($enablement->isEnabled(ChatDefaultOffModule::ID, 'bot-1', 100));
        self::assertTrue($enablement->isEnabled(ChatDefaultOffModule::ID, 'bot-1'), 'bot-scope memo must not be overwritten by the chat entry');
        self::assertFalse($enablement->isEnabled(ChatDefaultOffModule::ID, 'bot-1', 100), 'chat-scope memo must not be overwritten by the bot entry');
    }

    public function testRefreshWithNullChatClearsBotScopeAndChatMemos(): void
    {
        $enablement = new EngineModuleEnablement($this->reader);

        self::assertTrue($enablement->isEnabled('cinema', 'bot-1'));
        self::assertTrue($enablement->isEnabled('cinema', 'bot-1', 100));

        $this->service->disable('bot-1', 'cinema');
        self::assertTrue($enablement->isEnabled('cinema', 'bot-1'), 'memo keeps the stale answer');
        self::assertTrue($enablement->isEnabled('cinema', 'bot-1', 100), 'memo keeps the stale answer');

        $enablement->refresh('bot-1');

        self::assertFalse($enablement->isEnabled('cinema', 'bot-1'), 'null chat = bot level and below');
        self::assertFalse($enablement->isEnabled('cinema', 'bot-1', 100), 'null chat = bot level and below');
    }

    public function testRefreshForOneChatKeepsBotScopeAndSiblingChatMemos(): void
    {
        $enablement = new EngineModuleEnablement($this->reader);

        self::assertTrue($enablement->isEnabled('cinema', 'bot-1'));
        self::assertTrue($enablement->isEnabled('cinema', 'bot-1', 100));
        self::assertTrue($enablement->isEnabled('cinema', 'bot-1', 200));

        $this->service->disable('bot-1', 'cinema');

        $enablement->refresh('bot-1', 100);

        self::assertFalse($enablement->isEnabled('cinema', 'bot-1', 100), 'refreshed chat re-reads the store');
        self::assertTrue($enablement->isEnabled('cinema', 'bot-1', 200), 'sibling chat memo untouched');
        self::assertTrue($enablement->isEnabled('cinema', 'bot-1'), 'bot-scope memo untouched by a chat-scoped refresh');
    }
}
