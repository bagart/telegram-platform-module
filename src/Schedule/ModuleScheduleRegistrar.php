<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Schedule;

use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Registers module scheduler entries (TgModuleConfig::$schedule) on the
 * console Schedule, applying schedule-overrides.php user overrides
 * (disabled flag / cron expression) — the declarative replacement for the
 * legacy App\Console\ModuleTaskScheduler over telegram.modules_schedule.
 */
final readonly class ModuleScheduleRegistrar
{
    /**
     * @param  array<string, mixed>  $overrides  config/schedule-overrides.php,
     *                                            keyed by command name
     */
    public function __construct(
        private Schedule $schedule,
        private EngineModuleRegistry $registry,
        private array $overrides,
    ) {}

    public function register(): void
    {
        foreach ($this->registry->scheduleEntries() as $entries) {
            foreach ($entries as $entry) {
                // scheduleEntries() only covers platform-enabled modules; the
                // per-entry enabled flag is a static config default, so a
                // disabled entry is simply not scheduled (the registry, and
                // with it the schedule, rebuilds on process restart).
                if (! $entry->enabled) {
                    continue;
                }

                $override = (array) ($this->overrides[$entry->command] ?? []);
                if ((bool) ($override['disabled'] ?? false)) {
                    continue;
                }

                $expression = is_string($override['expression'] ?? null)
                    ? strval($override['expression'])
                    : $entry->expression;

                $this->schedule->command($entry->command)
                    ->cron($expression)
                    ->name($entry->command)
                    ->withoutOverlapping();
            }
        }
    }
}
