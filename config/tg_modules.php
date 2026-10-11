<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Telegram Module Engine — config publisher stub
|--------------------------------------------------------------------------
|
| This stub is published by `php artisan vendor:publish --tag=tg-modules-config`
| for NEW host installations. The canonical, git-versioned policy file lives
| in the host repository at config/tg_modules.php — never secrets here.
|
| Rules (docs/architecture/32.md):
|  - array key MUST equal the module's descriptor id;
|  - entries MUST be BAGArt\TelegramModuleEngine\Config\TgModuleConfig DTOs;
|  - `strict` => true fails platform boot on any invalid entry.
*/

use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;

return [
    'strict' => false,

    // Dispatch driver for the lib ModuleEnablementContract (doc 05):
    // 'engine' = engine adapter over bot_module_activations (shipped
    // default); 'legacy' = management service over tg_module_enablements —
    // test-fixture support for unmigrated dev DBs only (the table is
    // dropped by migration 2026_09_20_000002).
    'enablement_driver' => 'engine',

    'modules' => [
        //
        // 'my-module' => new TgModuleConfig(
        //     enabled: true,
        //     provider: MyModule::class,
        //     laravelProvider: MyServiceProvider::class,
        //     seeders: [],
        //     routes: [],
        // ),
    ],
];
