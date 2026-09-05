<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures\Commands;

use Illuminate\Console\Command;

final class AlphaCommand extends Command
{
    protected $signature = 'alpha:ping';

    protected $description = 'Fixture command for module alpha';
}
