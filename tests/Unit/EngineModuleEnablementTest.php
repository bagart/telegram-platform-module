<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Activation\EngineModuleEnablement;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\CinemaModule;
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
}
