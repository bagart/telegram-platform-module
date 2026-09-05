<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Console;

use BAGArt\TelegramModuleEngine\Routing\RouteTableSync;
use Illuminate\Console\Command;

/**
 * Materializes active modules' registered commands into the per-bot routing
 * table (control path consumed by the read-only PgRouteResolver).
 */
final class TgModulesRoutesSyncCommand extends Command
{
    protected $signature = 'tg:modules:routes:sync {botId : Bot id}';

    protected $description = 'Sync the routing table entries of active modules for a bot';

    public function handle(RouteTableSync $sync): int
    {
        $result = $sync->syncForBot((string) $this->argument('botId'));

        $this->info(sprintf('routing table synced: %d written, %d removed', $result['written'], $result['removed']));

        return 0;
    }
}
