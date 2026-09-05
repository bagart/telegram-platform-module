<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures\Commands;

use Illuminate\Console\Command;

final class BetaCommand extends Command
{
    protected $signature = 'beta:ping';

    protected $description = 'Fixture command for module beta';
}
