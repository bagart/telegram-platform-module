<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit\Config;

use BAGArt\TelegramModuleEngine\Config\TgModuleSchedule;
use PHPUnit\Framework\TestCase;

final class TgModuleScheduleTest extends TestCase
{
    public function test_default_values_for_enabled_and_defaults(): void
    {
        $schedule = new TgModuleSchedule(command: 'test:cmd', expression: '0 * * * *');

        self::assertSame('test:cmd', $schedule->command);
        self::assertSame('0 * * * *', $schedule->expression);
        self::assertTrue($schedule->enabled, 'enabled must default to true');
    }

    public function test_disabled_schedule_entry(): void
    {
        $schedule = new TgModuleSchedule(
            command: 'test:cmd',
            expression: '* * * * *',
            enabled: false,
        );

        self::assertFalse($schedule->enabled);
    }

    public function test_all_cron_expressions_accepted_as_value_objects(): void
    {
        // TgModuleSchedule is a pure DTO — it does not validate cron
        // expressions (that is the scheduler registrar's responsibility).
        // All expressions must be accepted without error.
        $expressions = [
            '* * * * *',
            '0 0 * * *',
            '*/5 * * * *',
            '0 4 * * 0',
            '30 2 1 * *',
            '0 22 * * 1-5',
            '@daily',
            '@weekly',
        ];

        foreach ($expressions as $expr) {
            $schedule = new TgModuleSchedule(command: 'test:cmd', expression: $expr);
            self::assertSame($expr, $schedule->expression);
        }
    }

    public function test_schedule_is_immutable(): void
    {
        $schedule = new TgModuleSchedule(command: 'test:cmd', expression: '* * * * *');

        $this->expectException(\Error::class);
        $schedule->command = 'changed';
    }
}
