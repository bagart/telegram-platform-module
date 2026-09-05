<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Base class for persistence-backed engine tests: boots an in-memory sqlite
 * connection via Capsule, wires the Schema facade against it, and applies the
 * engine migration. No host database is touched.
 */
abstract class EngineSqliteTestCase extends TestCase
{
    protected ConnectionInterface $db;

    protected function setUp(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $this->db = $capsule->getConnection();

        $container = new Container;
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $this->db->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $migration = require dirname(__DIR__, 2).'/src/Persistence/2026_08_27_000001_create_bot_module_tables.php';
        $migration->up();
    }

    /**
     * Registry of platform-enabled definitions built from module fixtures.
     *
     * @param  array<class-string<TgModuleContract>, bool>  $modules  class => platform enabled
     */
    protected function registry(array $modules): EngineModuleRegistry
    {
        $definitions = [];
        foreach ($modules as $provider => $enabled) {
            $definitions[] = new TgModuleDefinition(
                configKey: $provider::descriptor()->id,
                provider: $provider,
                descriptor: $provider::descriptor(),
                enabled: $enabled,
            );
        }

        return new EngineModuleRegistry($definitions);
    }

    /**
     * Seed one routing-table row for a bot.
     *
     * @param  array<string, mixed>|null  $payload
     */
    protected function seedRoute(string $botId, string $moduleId, string $entryKey = '/start', int $priority = 0, ?array $payload = null): void
    {
        $this->db->table('bot_module_routes')->insert([
            'bot_id' => $botId,
            'module_id' => $moduleId,
            'entry_type' => 'telegram.command',
            'entry_key' => $entryKey,
            'priority' => $priority,
            'payload' => $payload === null ? null : json_encode($payload),
            'created_at' => '2026-08-27 00:00:00',
            'updated_at' => '2026-08-27 00:00:00',
        ]);
    }
}
