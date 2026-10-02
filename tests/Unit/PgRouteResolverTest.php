<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use BAGArt\TelegramModuleEngine\Routing\PgRouteResolver;
use BAGArt\TelegramModuleEngine\Routing\RouteResolver;
use BAGArt\TelegramModuleEngine\Tenancy\BotContext;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\DefaultOffModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\MenuModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

final class PgRouteResolverTest extends EngineSqliteTestCase
{
    public function test_disabled_module_is_absent_from_routing_results(): void
    {
        $this->seedRoute('bot-1', TestModule::ID, '/hello');
        $this->seedRoute('bot-1', MenuModule::ID, '/menu');
        $service = $this->service();
        $service->enable('bot-1', MenuModule::ID);
        $resolver = $this->resolver();

        $allOn = $resolver->resolve(BotContext::forBot('bot-1'));
        self::assertSame([MenuModule::ID, TestModule::ID], $allOn->moduleIds());

        $service->disable('bot-1', MenuModule::ID);
        $resolver->invalidateBot('bot-1');

        $afterDisable = $resolver->resolve(BotContext::forBot('bot-1'));
        self::assertSame([TestModule::ID], $afterDisable->moduleIds());
        self::assertSame([], $afterDisable->forModule(MenuModule::ID));
        self::assertSame('/hello', $afterDisable->forModule(TestModule::ID)[0]->entryKey);
    }

    public function test_legacy_default_enabled_module_resolves_without_activation_rows(): void
    {
        $this->seedRoute('bot-1', TestModule::ID, '/hello');
        $this->seedRoute('bot-1', DefaultOffModule::ID, '/opt-in');

        $table = $this->resolver()->resolve(BotContext::forBot('bot-1'));

        // defaultEnabled = true + no rows → legacy "enabled everywhere" parity.
        self::assertSame('/hello', $table->forModule(TestModule::ID)[0]->entryKey);
        // defaultEnabled = false + no rows → opt-in module stays hidden.
        self::assertSame([], $table->forModule(DefaultOffModule::ID));
        self::assertSame([TestModule::ID], $table->moduleIds());
    }

    public function test_bot_a_activation_state_is_invisible_to_bot_b_queries(): void
    {
        $this->seedRoute('bot-a', MenuModule::ID, '/menu-a');
        $this->seedRoute('bot-b', MenuModule::ID, '/menu-b');
        $service = $this->service();
        $service->enable('bot-a', MenuModule::ID);
        $service->disable('bot-b', MenuModule::ID);

        $resolver = $this->resolver();

        $tableA = $resolver->resolve(BotContext::forBot('bot-a'));
        $tableB = $resolver->resolve(BotContext::forBot('bot-b'));

        self::assertSame(['/menu-a'], array_map(
            static fn ($entry): string => $entry->entryKey,
            $tableA->forModule(MenuModule::ID),
        ));
        self::assertSame([], $tableB->forModule(MenuModule::ID), 'bot A enablement must not leak into bot B routing');
        self::assertSame('bot-a', $tableA->botId);
    }

    public function test_entries_are_ordered_by_priority_then_key_and_carry_payload(): void
    {
        $this->seedRoute('bot-1', TestModule::ID, '/zeta', priority: 5);
        $this->seedRoute('bot-1', TestModule::ID, '/alpha', priority: 5);
        $this->seedRoute('bot-1', TestModule::ID, '/low', priority: 1, payload: ['kind' => 'special']);

        $entries = $this->resolver()->resolve(BotContext::forBot('bot-1'))->entries;

        self::assertSame(['/alpha', '/zeta', '/low'], array_map(
            static fn ($entry): string => $entry->entryKey,
            $entries,
        ));
        self::assertSame(['kind' => 'special'], $entries[2]->payload);
        self::assertSame('command', $entries[0]->entryType);
        self::assertSame(TestModule::ID, $entries[0]->moduleId);
    }

    public function test_resolver_satisfies_the_strict_contract(): void
    {
        $resolver = $this->resolver();

        self::assertInstanceOf(RouteResolver::class, $resolver);
        self::assertSame([], $resolver->resolve(BotContext::forBot('unknown-bot'))->entries);
    }

    private function service(): ModuleActivationService
    {
        $registry = $this->registry([
            TestModule::class => true,
            MenuModule::class => true,
            DefaultOffModule::class => true,
        ]);

        return new ModuleActivationService($this->db, $registry, new ModuleActivationReader($this->db, $registry));
    }

    private function resolver(): PgRouteResolver
    {
        $registry = $this->registry([
            TestModule::class => true,
            MenuModule::class => true,
            DefaultOffModule::class => true,
        ]);

        return new PgRouteResolver(
            $this->db,
            new ModuleActivationReader($this->db, $registry),
            new Repository(new ArrayStore()),
        );
    }
}
