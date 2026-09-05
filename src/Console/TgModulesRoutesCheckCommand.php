<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Console;

use BAGArt\TelegramModuleEngine\Routing\RouteTableSync;
use Illuminate\Console\Command;

/**
 * Drift detection for the routing table (roadmap phase 6): dry-run of
 * tg:modules:routes:sync. Exit 0 = in sync, 5 = drift found (baseline CLI
 * contract: policy failure).
 */
final class TgModulesRoutesCheckCommand extends Command
{
    protected $signature = 'tg:modules:routes:check {botId : Bot id}';

    protected $description = 'Report routing-table drift for a bot without changing anything';

    public function handle(RouteTableSync $sync): int
    {
        $diff = $sync->diffForBot((string) $this->argument('botId'));

        foreach ($diff['missing'] as $row) {
            $this->line(sprintf(
                'MISSING  %s | %s | %s',
                $row['module_id'],
                $row['entry_type'],
                $row['entry_key'],
            ));
        }

        foreach ($diff['stale'] as $row) {
            $this->line(sprintf(
                'STALE    %s | %s | %s',
                $row['module_id'] ?? '?',
                $row['entry_type'] ?? '?',
                $row['entry_key'] ?? '?',
            ));
        }

        if ($diff['missing'] === [] && $diff['stale'] === []) {
            $this->info('routing table in sync');

            return 0;
        }

        $this->warn(sprintf('drift: %d missing, %d stale (run tg:modules:routes:sync to repair)', count($diff['missing']), count($diff['stale'])));

        return 5;
    }
}
