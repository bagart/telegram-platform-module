<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Config\TgModuleSchedule;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use PHPUnit\Framework\TestCase;

final class RegistryScheduleTest extends TestCase
{
    public function test_schedule_entries_are_keyed_by_module_id(): void
    {
        $entry = new TgModuleSchedule(command: 'alpha:ping', expression: '* * * * *');
        $registry = new EngineModuleRegistry([
            $this->definition('test', true, [$entry]),
        ]);

        self::assertSame(['test' => [$entry]], $registry->scheduleEntries());
    }

    public function test_disabled_modules_contribute_no_schedule_entries(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('alpha', false, [
                new TgModuleSchedule(command: 'alpha:ping', expression: '* * * * *'),
            ]),
        ]);

        self::assertSame([], $registry->scheduleEntries());
    }

    public function test_list_accessors_deduplicate_in_module_id_order(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition(
                'test',
                true,
                httpRoutes: ['/b/routes.php', '/a/routes.php'],
                routeMiddleware: ['zeta' => 'ZetaClass', 'alpha' => 'AlphaClass'],
                exceptionRenderables: ['RenderableB', 'RenderableA'],
                frontendPages: ['/b/pages', '/a/pages'],
                pageGenerators: ['b:pages', 'a:pages'],
            ),
            $this->definition(
                'alpha',
                true,
                httpRoutes: ['/a/routes.php', '/c/routes.php'],
                routeMiddleware: ['alpha' => 'AlphaClass'],
            ),
            $this->definition('gamma', false, httpRoutes: ['/disabled/routes.php']),
        ]);

        // registry sorts by id: alpha before test; disabled gamma contributes
        self::assertSame(['/a/routes.php', '/c/routes.php', '/b/routes.php'], $registry->httpRoutes());
        self::assertSame(['alpha' => 'AlphaClass', 'zeta' => 'ZetaClass'], $registry->routeMiddleware());
        self::assertSame(['RenderableB', 'RenderableA'], $registry->exceptionRenderables());
        self::assertSame(['/b/pages', '/a/pages'], $registry->frontendPages());
        self::assertSame(['b:pages', 'a:pages'], $registry->pageGenerators());
    }

    /**
     * @param  list<TgModuleSchedule>  $schedule
     * @param  list<string>  $httpRoutes
     * @param  array<string, class-string>  $routeMiddleware
     * @param  list<class-string|callable>  $exceptionRenderables
     * @param  list<string>  $frontendPages
     * @param  list<string>  $pageGenerators
     */
    private function definition(
        string $id,
        bool $enabled,
        array $schedule = [],
        array $httpRoutes = [],
        array $routeMiddleware = [],
        array $exceptionRenderables = [],
        array $frontendPages = [],
        array $pageGenerators = [],
    ): TgModuleDefinition {
        return new TgModuleDefinition(
            configKey: $id,
            provider: TestModule::class,
            descriptor: new TgModuleDescriptor(id: $id, name: ucfirst($id), version: '0.1.0'),
            enabled: $enabled,
            schedule: $schedule,
            httpRoutes: $httpRoutes,
            routeMiddleware: $routeMiddleware,
            exceptionRenderables: $exceptionRenderables,
            frontendPages: $frontendPages,
            pageGenerators: $pageGenerators,
        );
    }
}
