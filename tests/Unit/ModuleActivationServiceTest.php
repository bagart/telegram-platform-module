<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Activation\ActivationErrorCode;
use BAGArt\TelegramModuleEngine\Activation\ActivationOutcome;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use BAGArt\TelegramModuleEngine\Events\BotModuleDisabled;
use BAGArt\TelegramModuleEngine\Events\BotModuleEnabled;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\CinemaModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\DefaultOffModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\MenuModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;

final class ModuleActivationServiceTest extends EngineSqliteTestCase
{
    public function test_enable_is_idempotent_and_does_not_bump_revision(): void
    {
        $service = $this->service();

        $first = $service->enable('bot-1', TestModule::ID);
        $second = $service->enable('bot-1', TestModule::ID);

        self::assertSame(ActivationOutcome::Enabled, $first->outcome);
        self::assertSame(1, $first->revision);
        self::assertSame(ActivationOutcome::AlreadyEnabled, $second->outcome);
        self::assertSame(1, $second->revision, 'idempotent re-enable must not bump the revision');
        self::assertFalse($second->applied());

        $row = $this->activationRow('bot-1', TestModule::ID);
        self::assertSame('enabled', $row->status);
        self::assertSame(1, (int) $row->revision);
    }

    public function test_disable_is_idempotent_including_when_never_enabled(): void
    {
        $service = $this->service();
        $service->enable('bot-1', TestModule::ID);

        $first = $service->disable('bot-1', TestModule::ID);
        $second = $service->disable('bot-1', TestModule::ID);

        self::assertSame(ActivationOutcome::Disabled, $first->outcome);
        self::assertSame(2, $first->revision);
        self::assertSame(ActivationOutcome::AlreadyDisabled, $second->outcome);
        self::assertSame(2, $second->revision, 'idempotent re-disable must not bump the revision');

        $nothing = $service->disable('bot-9', TestModule::ID);
        self::assertSame(ActivationOutcome::Disabled, $nothing->outcome);
        self::assertSame(1, $nothing->revision);
        // A default-enabled module MUST get an explicit row to override the
        // descriptor default; a default-disabled one must not.
        self::assertNotNull($this->findRow('bot-9', TestModule::ID), 'default-enabled module disable must persist an explicit row');
        $offNothing = $service->disable('bot-9', DefaultOffModule::ID);
        self::assertSame(ActivationOutcome::AlreadyDisabled, $offNothing->outcome);
        self::assertNull($this->findRow('bot-9', DefaultOffModule::ID), 'disabling a default-disabled module must not create a binding');
    }

    public function test_enable_with_missing_dependency_returns_structured_reason(): void
    {
        // menu is platform-registered but neither explicitly enabled nor default-enabled.
        $result = $this->service()->enable('bot-1', CinemaModule::ID);

        self::assertSame(ActivationOutcome::Blocked, $result->outcome);
        self::assertFalse($result->applied());
        self::assertCount(1, $result->blockers);
        self::assertSame(MenuModule::ID, $result->blockers[0]->moduleId);
        self::assertSame(ActivationErrorCode::DependencyNotEnabled, $result->blockers[0]->reason);
        self::assertNull($this->findRow('bot-1', CinemaModule::ID), 'blocked enable must not persist a binding');
    }

    public function test_enable_with_explicitly_disabled_dependency_names_the_reason(): void
    {
        $service = $this->service();
        $service->enable('bot-1', MenuModule::ID);
        $service->disable('bot-1', MenuModule::ID);

        $result = $service->enable('bot-1', CinemaModule::ID);

        self::assertSame(ActivationOutcome::Blocked, $result->outcome);
        self::assertSame(MenuModule::ID, $result->blockers[0]->moduleId);
        self::assertSame(ActivationErrorCode::DependencyDisabled, $result->blockers[0]->reason);
    }

    public function test_enable_succeeds_once_required_dependency_is_enabled(): void
    {
        $service = $this->service();
        $service->enable('bot-1', MenuModule::ID);

        $result = $service->enable('bot-1', CinemaModule::ID);

        self::assertSame(ActivationOutcome::Enabled, $result->outcome);
        self::assertSame('enabled', $this->activationRow('bot-1', CinemaModule::ID)->status);
    }

    public function test_enable_of_unregistered_or_platform_disabled_module_is_blocked(): void
    {
        $service = $this->service();

        $unknown = $service->enable('bot-1', 'nope');
        self::assertSame(ActivationOutcome::Blocked, $unknown->outcome);
        self::assertSame(ActivationErrorCode::ModuleNotRegistered, $unknown->blockers[0]->reason);
        self::assertNull($this->findRow('bot-1', 'nope'));

        $registry = $this->registry([DefaultOffModule::class => false]);
        $disabled = new ModuleActivationService($this->db, $registry, $this->reader($registry));

        $platformDisabled = $disabled->enable('bot-1', DefaultOffModule::ID);
        self::assertSame(ActivationOutcome::Blocked, $platformDisabled->outcome);
        self::assertSame(ActivationErrorCode::ModuleNotRegistered, $platformDisabled->blockers[0]->reason);
    }

    public function test_stale_expected_revision_on_enable_returns_concurrent_modification(): void
    {
        $service = $this->service();
        self::assertSame(1, $service->enable('bot-1', TestModule::ID)->revision);

        // A second writer changed the binding meanwhile (revision is now 2);
        // the stale writer still expects revision 1.
        $service->disable('bot-1', TestModule::ID);

        $stale = $service->enable('bot-1', TestModule::ID, expectedRevision: 1);

        self::assertSame(ActivationOutcome::ConcurrentModification, $stale->outcome);
        self::assertFalse($stale->applied());
        self::assertNotNull($stale->conflict);
        self::assertSame(1, $stale->conflict->expectedRevision);
        self::assertSame(2, $stale->conflict->currentRevision);
        self::assertSame(2, (int) $this->activationRow('bot-1', TestModule::ID)->revision, 'conflict must leave the row untouched');
    }

    public function test_stale_expected_revision_on_disable_returns_concurrent_modification(): void
    {
        $service = $this->service();
        $service->enable('bot-1', TestModule::ID);

        $stale = $service->disable('bot-1', TestModule::ID, expectedRevision: 99);

        self::assertSame(ActivationOutcome::ConcurrentModification, $stale->outcome);
        self::assertNotNull($stale->conflict);
        self::assertSame(99, $stale->conflict->expectedRevision);
        self::assertSame(1, $stale->conflict->currentRevision);
        self::assertSame('enabled', $this->activationRow('bot-1', TestModule::ID)->status);
    }

    public function test_activations_of_one_bot_are_invisible_to_another_bot(): void
    {
        $this->service()->enable('bot-a', MenuModule::ID);

        $reader = $this->reader();

        self::assertTrue($reader->isEffectivelyEnabled('bot-a', MenuModule::ID));
        self::assertFalse($reader->isEffectivelyEnabled('bot-b', MenuModule::ID), 'bot A activation must not leak to bot B');
        // cinema has no rows and defaultEnabled = true, so it is effectively on for both.
        self::assertSame([CinemaModule::ID, MenuModule::ID, TestModule::ID], $reader->activeModuleIds('bot-a'));
        self::assertSame([CinemaModule::ID, TestModule::ID], $reader->activeModuleIds('bot-b'));
    }

    public function test_legacy_default_enabled_module_without_activation_rows_resolves_enabled(): void
    {
        $reader = $this->reader();

        // TestModule: descriptor defaultEnabled = true, no activation rows at all
        // (cinema shares that default and resolves alongside it).
        self::assertTrue($reader->isEffectivelyEnabled('bot-legacy', TestModule::ID));
        self::assertSame([CinemaModule::ID, TestModule::ID], $reader->activeModuleIds('bot-legacy'));

        // DefaultOffModule: descriptor defaultEnabled = false, no rows → absent.
        self::assertFalse($reader->isEffectivelyEnabled('bot-legacy', DefaultOffModule::ID));
        self::assertNotContains(DefaultOffModule::ID, $reader->activeModuleIds('bot-legacy'));
    }

    public function test_insert_race_returns_already_enabled_when_concurrent_row_matches(): void
    {
        $service = $this->service();

        // Simulate a concurrent inserter: insert the row before enable() runs.
        $this->db->table('bot_module_activations')->insert([
            'bot_id' => 'bot-1',
            'module_id' => TestModule::ID,
            'status' => 'enabled',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // enable() will try INSERT → unique violation → re-read → already enabled.
        $result = $service->enable('bot-1', TestModule::ID);

        self::assertSame(ActivationOutcome::AlreadyEnabled, $result->outcome);
        self::assertSame(1, $result->revision);
        self::assertFalse($result->applied());
    }

    public function test_insert_race_falls_through_to_update_when_status_differs(): void
    {
        $service = $this->service();

        // Simulate a concurrent inserter that inserted with DISABLED status.
        $this->db->table('bot_module_activations')->insert([
            'bot_id' => 'bot-1',
            'module_id' => TestModule::ID,
            'status' => 'disabled',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // enable() will try INSERT → unique violation → re-read → status differs
        // → falls through to the update path.
        $result = $service->enable('bot-1', TestModule::ID);

        self::assertSame(ActivationOutcome::Enabled, $result->outcome);
        self::assertSame(2, $result->revision);
        self::assertTrue($result->applied());
    }

    public function test_dependency_check_inside_transaction_blocks_when_dependency_disabled_concurrently(): void
    {
        $service = $this->service();

        // cinema depends on menu; enable menu first, then cinema.
        $service->enable('bot-1', MenuModule::ID);
        $service->enable('bot-1', CinemaModule::ID);

        // Disable menu — now cinema's dependency is gone.
        $service->disable('bot-1', MenuModule::ID);

        // Re-enable cinema: dependency check inside transaction must block.
        $result = $service->enable('bot-1', CinemaModule::ID);

        self::assertSame(ActivationOutcome::Blocked, $result->outcome);
        self::assertSame(MenuModule::ID, $result->blockers[0]->moduleId);
        self::assertSame(ActivationErrorCode::DependencyDisabled, $result->blockers[0]->reason);
    }

    public function test_enable_dispatches_event_with_actor_info(): void
    {
        $events = [];
        $dispatcher = $this->recordingDispatcher($events);
        $service = $this->serviceWithDispatcher($dispatcher);

        $result = $service->enable('bot-1', TestModule::ID, actorId: 'admin-user', actorType: 'user');

        self::assertSame(ActivationOutcome::Enabled, $result->outcome);

        $fired = array_filter($events, static fn (mixed $e): bool => $e instanceof BotModuleEnabled);
        self::assertNotEmpty($fired);
        $event = array_values($fired)[0];
        self::assertSame('bot-1', $event->botId);
        self::assertSame(TestModule::ID, $event->moduleId);
        self::assertSame('admin-user', $event->actorId);
        self::assertSame('user', $event->actorType);
    }

    public function test_disable_dispatches_event_with_actor_info(): void
    {
        $events = [];
        $dispatcher = $this->recordingDispatcher($events);
        $service = $this->serviceWithDispatcher($dispatcher);

        $service->enable('bot-1', TestModule::ID);
        $result = $service->disable('bot-1', TestModule::ID, actorId: 'bot-owner', actorType: 'owner');

        self::assertSame(ActivationOutcome::Disabled, $result->outcome);

        $fired = array_filter($events, static fn (mixed $e): bool => $e instanceof BotModuleDisabled);
        self::assertNotEmpty($fired);
        $event = array_values($fired)[0];
        self::assertSame('bot-1', $event->botId);
        self::assertSame(TestModule::ID, $event->moduleId);
        self::assertSame('bot-owner', $event->actorId);
        self::assertSame('owner', $event->actorType);
    }

    public function test_enable_event_without_actor_has_null_actor_fields(): void
    {
        $events = [];
        $dispatcher = $this->recordingDispatcher($events);
        $service = $this->serviceWithDispatcher($dispatcher);

        $service->enable('bot-1', TestModule::ID);

        $fired = array_filter($events, static fn (mixed $e): bool => $e instanceof BotModuleEnabled);
        self::assertNotEmpty($fired);
        $event = array_values($fired)[0];
        self::assertNull($event->actorId);
        self::assertNull($event->actorType);
    }

    public function test_disable_event_without_actor_has_null_actor_fields(): void
    {
        $events = [];
        $dispatcher = $this->recordingDispatcher($events);
        $service = $this->serviceWithDispatcher($dispatcher);

        $service->enable('bot-1', TestModule::ID);
        $service->disable('bot-1', TestModule::ID);

        $fired = array_filter($events, static fn (mixed $e): bool => $e instanceof BotModuleDisabled);
        self::assertNotEmpty($fired);
        $event = array_values($fired)[0];
        self::assertNull($event->actorId);
        self::assertNull($event->actorType);
    }

    public function test_enable_idempotent_does_not_dispatch_event(): void
    {
        $events = [];
        $dispatcher = $this->recordingDispatcher($events);
        $service = $this->serviceWithDispatcher($dispatcher);

        $service->enable('bot-1', TestModule::ID);
        $service->enable('bot-1', TestModule::ID);

        $enabledEvents = array_filter($events, static fn (mixed $e): bool => $e instanceof BotModuleEnabled);
        self::assertCount(1, $enabledEvents);
    }

    private function service(): ModuleActivationService
    {
        $registry = $this->registry([
            TestModule::class => true,
            MenuModule::class => true,
            CinemaModule::class => true,
            DefaultOffModule::class => true,
        ]);

        return new ModuleActivationService($this->db, $registry, $this->reader($registry));
    }

    /**
     * @param  array<array-key, object>  $events  populated by reference
     */
    private function recordingDispatcher(array &$events): Dispatcher
    {
        $container = Container::getInstance();
        $dispatcher = new Dispatcher($container);
        $dispatcher->listen(BotModuleEnabled::class, static function (BotModuleEnabled $event) use (&$events): void {
            $events[] = $event;
        });
        $dispatcher->listen(BotModuleDisabled::class, static function (BotModuleDisabled $event) use (&$events): void {
            $events[] = $event;
        });

        return $dispatcher;
    }

    private function serviceWithDispatcher(Dispatcher $dispatcher): ModuleActivationService
    {
        $registry = $this->registry([
            TestModule::class => true,
            MenuModule::class => true,
            CinemaModule::class => true,
            DefaultOffModule::class => true,
        ]);

        // Temporarily replace the events binding so event() dispatches through our recording dispatcher
        Container::getInstance()->instance('events', $dispatcher);

        return new ModuleActivationService($this->db, $registry, $this->reader($registry));
    }

    /**
     * @param  array<class-string, bool>  $modules
     */
    private function reader(?EngineModuleRegistry $registry = null): ModuleActivationReader
    {
        return new ModuleActivationReader(
            $this->db,
            $registry ?? $this->registry([
                TestModule::class => true,
                MenuModule::class => true,
                CinemaModule::class => true,
                DefaultOffModule::class => true,
            ]),
        );
    }

    private function findRow(string $botId, string $moduleId): ?object
    {
        $row = $this->db->table('bot_module_activations')
            ->where('bot_id', $botId)
            ->where('module_id', $moduleId)
            ->first();

        return $row ?? null;
    }

    private function activationRow(string $botId, string $moduleId): object
    {
        $row = $this->findRow($botId, $moduleId);
        self::assertNotNull($row, "expected an activation row for {$botId}/{$moduleId}");

        return $row;
    }
}
