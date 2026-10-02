<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Config\TgModuleSchedule;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Schedule\ModuleScheduleRegistrar;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class ModuleScheduleRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        // Schedule mutexes need a cache factory; provide a minimal container
        // with an in-memory repository (no Laravel application is booted).
        $container = new Container();
        $container->singleton(CacheFactory::class, static fn (): CacheFactory => new class (new CacheRepository(new ArrayStore())) implements CacheFactory {
            public function __construct(private readonly CacheRepository $repository)
            {
            }

            public function store($name = null)
            {
                return $this->repository;
            }
        });
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }
    public function test_registers_enabled_module_entries_with_default_expression(): void
    {
        $schedule = new Schedule(new \DateTimeZone('UTC'));
        $registry = $this->registry('alpha', true, new TgModuleSchedule(
            command: 'alpha:ping',
            expression: '0 3 * * *',
        ));

        $this->registrar($schedule, $registry)->register();

        $event = $this->eventByName($schedule, 'alpha:ping');
        self::assertNotNull($event);
        self::assertSame('0 3 * * *', $event->expression);
    }

    public function test_skips_disabled_entries(): void
    {
        $schedule = new Schedule(new \DateTimeZone('UTC'));
        $registry = $this->registry('alpha', true, new TgModuleSchedule(
            command: 'alpha:ping',
            expression: '* * * * *',
            enabled: false,
        ));

        $this->registrar($schedule, $registry)->register();

        self::assertNull($this->eventByName($schedule, 'alpha:ping'));
    }

    public function test_disabled_modules_contribute_nothing(): void
    {
        $schedule = new Schedule(new \DateTimeZone('UTC'));
        $registry = $this->registry('alpha', false, new TgModuleSchedule(
            command: 'alpha:ping',
            expression: '* * * * *',
        ));

        $this->registrar($schedule, $registry)->register();

        self::assertNull($this->eventByName($schedule, 'alpha:ping'));
    }

    public function test_user_override_disables_entry(): void
    {
        $schedule = new Schedule(new \DateTimeZone('UTC'));
        $registry = $this->registry('alpha', true, new TgModuleSchedule(
            command: 'alpha:ping',
            expression: '* * * * *',
        ));

        $this->registrar($schedule, $registry, ['alpha:ping' => ['disabled' => true]])->register();

        self::assertNull($this->eventByName($schedule, 'alpha:ping'));
    }

    public function test_user_override_replaces_expression(): void
    {
        $schedule = new Schedule(new \DateTimeZone('UTC'));
        $registry = $this->registry('alpha', true, new TgModuleSchedule(
            command: 'alpha:ping',
            expression: '* * * * *',
        ));

        $this->registrar($schedule, $registry, ['alpha:ping' => ['expression' => '*/5 * * * *']])->register();

        $event = $this->eventByName($schedule, 'alpha:ping');
        self::assertNotNull($event);
        self::assertSame('*/5 * * * *', $event->expression);
    }

    private function registrar(Schedule $schedule, EngineModuleRegistry $registry, array $overrides = []): ModuleScheduleRegistrar
    {
        return new ModuleScheduleRegistrar($schedule, $registry, $overrides);
    }

    private function registry(string $id, bool $enabled, TgModuleSchedule $entry): EngineModuleRegistry
    {
        return new EngineModuleRegistry([
            new TgModuleDefinition(
                configKey: $id,
                provider: TestModule::class,
                descriptor: new TgModuleDescriptor(id: $id, name: ucfirst($id), version: '0.1.0'),
                enabled: $enabled,
                schedule: [$entry],
            ),
        ]);
    }

    private function eventByName(Schedule $schedule, string $name): ?Event
    {
        return collect($schedule->events())->first(
            fn (Event $event): bool => $event->description === $name,
        );
    }
}
