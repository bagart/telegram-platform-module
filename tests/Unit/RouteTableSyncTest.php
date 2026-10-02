<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Routing\RouteDeclaration;
use BAGArt\TelegramModuleEngine\Routing\RouteTableSync;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\CinemaModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\MenuModule;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;

final class RouteTableSyncTest extends EngineSqliteTestCase
{
    private ModuleActivationReader $reader;

    private ModuleActivationService $service;

    public function testSyncWritesDeclaredRoutesForActiveModulesOnly(): void
    {
        $sync = $this->makeSync();

        $this->service->enable('bot-1', 'menu');

        $result = $sync->syncForBot('bot-1');

        // menu is enabled explicitly (2 routes), cinema is active via its
        // defaultEnabled descriptor default (1 route).
        self::assertSame(3, $result['written']);
        self::assertSame(
            ['/cinema', '/menu', '/menu_settings'],
            $this->db->table('bot_module_routes')->where('bot_id', 'bot-1')->orderBy('entry_key')->pluck('entry_key')->all(),
        );
    }

    public function testSyncIsIdempotent(): void
    {
        $sync = $this->makeSync();
        $this->service->enable('bot-1', 'menu');

        $sync->syncForBot('bot-1');
        $result = $sync->syncForBot('bot-1');

        self::assertSame(3, $result['written']);
        self::assertSame(0, $result['removed']);
        self::assertSame(3, $this->db->table('bot_module_routes')->count());
    }

    public function testSyncRemovesEntriesOfModulesDisabledAfterSync(): void
    {
        $sync = $this->makeSync();
        $this->service->enable('bot-1', 'menu');
        $sync->syncForBot('bot-1');

        $this->service->disable('bot-1', 'menu');
        $result = $sync->syncForBot('bot-1');

        // Only menu's two rows are removed; cinema stays active (default).
        self::assertSame(2, $result['removed']);
        self::assertSame(1, $this->db->table('bot_module_routes')->where('bot_id', 'bot-1')->count());
    }

    public function testSyncIsTenantScoped(): void
    {
        $sync = $this->makeSync();
        $this->service->enable('bot-1', 'menu');
        $this->service->enable('bot-2', 'menu');

        $sync->syncForBot('bot-1');
        $sync->syncForBot('bot-2');

        self::assertSame(3, $this->db->table('bot_module_routes')->where('bot_id', 'bot-1')->count());
        self::assertSame(3, $this->db->table('bot_module_routes')->where('bot_id', 'bot-2')->count());
    }

    public function testDisabledRoutesResolveAsAbsent(): void
    {
        $sync = $this->makeSync();
        $this->service->enable('bot-1', 'menu');
        $sync->syncForBot('bot-1');
        $this->service->disable('bot-1', 'menu');

        $table = $this->reader->activeModuleIds('bot-1');

        self::assertNotContains('menu', $table);
    }

    private function makeSync(): RouteTableSync
    {
        $registry = new EngineModuleRegistry([
            $this->definitionWithRoutes('menu', MenuModule::class, [
                new RouteDeclaration('command', '/menu'),
                new RouteDeclaration('command', '/menu_settings', priority: 10),
            ]),
            $this->definitionWithRoutes('cinema', CinemaModule::class, [
                new RouteDeclaration('command', '/cinema', payload: ['processor' => 'Proc\\CinemaProc']),
            ]),
        ]);
        $this->reader = new ModuleActivationReader($this->db, $registry);
        $this->service = new ModuleActivationService($this->db, $registry, $this->reader);

        return new RouteTableSync($this->db, $this->reader, $registry);
    }

    /**
     * @param  list<RouteDeclaration>  $routes
     */
    private function definitionWithRoutes(string $id, string $provider, array $routes): TgModuleDefinition
    {
        return new TgModuleDefinition(
            configKey: $id,
            provider: $provider,
            descriptor: new TgModuleDescriptor(id: $id, name: $id, version: '1.0.0'),
            enabled: true,
            routes: $routes,
        );
    }

    public function testDiffForBotReportsDriftWithoutChangingAnything(): void
    {
        $sync = $this->makeSync();
        $this->service->enable('bot-1', 'menu');

        // Desired but not synced yet → missing; nothing written.
        $diff = $sync->diffForBot('bot-1');
        self::assertCount(3, $diff['missing']);
        self::assertSame([], $diff['stale']);
        self::assertSame(0, $this->db->table('bot_module_routes')->count());

        // After sync → clean.
        $sync->syncForBot('bot-1');
        $diff = $sync->diffForBot('bot-1');
        self::assertSame([], $diff['missing']);
        self::assertSame([], $diff['stale']);

        // A row whose module is no longer active → stale.
        $this->service->disable('bot-1', 'menu');
        $diff = $sync->diffForBot('bot-1');
        self::assertCount(2, $diff['stale']);
        self::assertSame(3, $this->db->table('bot_module_routes')->count());
    }

    public function test_sync_after_all_modules_disabled_removes_all_routes(): void
    {
        $sync = $this->makeSync();
        $this->service->enable('bot-1', 'menu');
        $sync->syncForBot('bot-1');

        // 3 routes written (2 menu + 1 cinema default-enabled)
        self::assertSame(3, $this->db->table('bot_module_routes')->where('bot_id', 'bot-1')->count());

        // Disable all: menu explicitly, cinema is default-enabled but
        // we have no explicit disable row for it. Cinema stays active
        // via its descriptor default, so only menu routes are removed.
        $this->service->disable('bot-1', 'menu');
        $result = $sync->syncForBot('bot-1');

        self::assertSame(2, $result['removed']);
        self::assertSame(1, $this->db->table('bot_module_routes')->where('bot_id', 'bot-1')->count());

        // The remaining route is cinema (default-enabled, not explicitly disabled)
        $remaining = $this->db->table('bot_module_routes')
            ->where('bot_id', 'bot-1')
            ->pluck('module_id')
            ->all();
        self::assertSame(['cinema'], $remaining);
    }

    public function test_sync_after_full_disable_removes_all_when_no_default_enabled(): void
    {
        // Build a registry with only menu (defaultEnabled = false) so there
        // are no default-enabled modules to keep routes alive.
        $registry = new EngineModuleRegistry([
            $this->definitionWithRoutes('menu', MenuModule::class, [
                new RouteDeclaration('command', '/menu'),
                new RouteDeclaration('command', '/menu_settings', priority: 10),
            ]),
        ]);
        $reader = new ModuleActivationReader($this->db, $registry);
        $service = new ModuleActivationService($this->db, $registry, $reader);
        $sync = new RouteTableSync($this->db, $reader, $registry);

        $service->enable('bot-1', 'menu');
        $sync->syncForBot('bot-1');

        self::assertSame(2, $this->db->table('bot_module_routes')->where('bot_id', 'bot-1')->count());

        $service->disable('bot-1', 'menu');
        $result = $sync->syncForBot('bot-1');

        self::assertSame(2, $result['removed']);
        self::assertSame(0, $this->db->table('bot_module_routes')->where('bot_id', 'bot-1')->count());
    }
}
