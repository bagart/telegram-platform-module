<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Console;

use BAGArt\TelegramModuleEngine\Activation\ActivationOutcome;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use Illuminate\Console\Command;

/**
 * Control-path CLI for bot-level activation (phase 2 lifecycle ops).
 * Exit codes: 0 = applied or already in target state, 5 = blocked
 * (dependency validation) or stale revision — baseline CLI contract.
 */
final class TgModulesEnableCommand extends Command
{
    protected $signature = 'tg:modules:enable {botId : Bot id} {moduleId : Module id from config/tg_modules.php} {--revision= : Expected revision for optimistic locking}';

    protected $description = 'Enable a module for a specific bot (engine activation store)';

    public function handle(ModuleActivationService $activations): int
    {
        return self::report(
            $this->output,
            $activations->enable(
                botId: (string) $this->argument('botId'),
                moduleId: (string) $this->argument('moduleId'),
                expectedRevision: $this->option('revision') !== null ? (int) $this->option('revision') : null,
            ),
        );
    }

    /**
     * Shared result rendering for enable and disable commands.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     */
    public static function report($output, \BAGArt\TelegramModuleEngine\Activation\ActivationResult $result): int
    {
        $failed = $result->outcome === ActivationOutcome::Blocked
            || $result->outcome === ActivationOutcome::ConcurrentModification;

        $output->writeln(sprintf(
            '<fg=%s>%s</> (revision %d)',
            $failed ? 'red' : 'green',
            $result->outcome->value,
            $result->revision,
        ));

        foreach ($result->blockers as $blocker) {
            $output->writeln(sprintf('  blocked: [%s] %s', $blocker->reason->value, $blocker->message));
        }

        if ($result->conflict !== null) {
            $output->writeln(sprintf('  conflict: expected revision %d, current %d', $result->conflict->expectedRevision, $result->conflict->currentRevision));
        }

        return $failed ? 5 : 0;
    }
}
