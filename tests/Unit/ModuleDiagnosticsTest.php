<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Diagnostics\ActivationStateProbe;
use BAGArt\TelegramModuleEngine\Diagnostics\EngineMetrics;
use BAGArt\TelegramModuleEngine\Diagnostics\ModuleDiagnostics;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Registry\RegistryError;
use BAGArt\TelegramModuleEngine\Registry\RegistryErrorCode;
use BAGArt\TelegramModuleEngine\Registry\RegistryResult;
use BAGArt\TelegramModuleEngine\Routing\RouteEntry;
use BAGArt\TelegramModuleEngine\Routing\RouteResolver;
use BAGArt\TelegramModuleEngine\Routing\RoutingTable;
use BAGArt\TelegramModuleEngine\Tenancy\BotContext;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ModuleDiagnosticsTest extends TestCase
{
    public function test_builds_module_rows_for_valid_registry_without_bot(): void
    {
        $payload = $this->diagnostics()->toArray();

        self::assertSame([
            ['id' => 'alpha', 'version' => '1.0.0', 'policy' => 'enabled', 'commands' => 0],
            ['id' => 'beta', 'version' => '0.2.0', 'policy' => 'disabled', 'commands' => 0],
        ], $payload['modules']);
        self::assertSame([], $payload['errors']);
        self::assertArrayNotHasKey('bot', $payload);
        self::assertArrayNotHasKey('metrics', $payload);
    }

    public function test_degraded_registry_includes_error_rows(): void
    {
        $result = new RegistryResult(
            new EngineModuleRegistry([]),
            [new RegistryError(RegistryErrorCode::ProviderMissing, 'ghost', 'provider class missing')],
        );

        $payload = (new ModuleDiagnostics($result))->toArray();

        self::assertSame([
            ['code' => 'PROVIDER_MISSING', 'module' => 'ghost', 'message' => 'provider class missing'],
        ], $payload['errors']);
    }

    public function test_resolves_activation_and_routing_per_module_for_bot(): void
    {
        $probe = new class implements ActivationStateProbe
        {
            public function isEffectivelyEnabled(string $botId, string $moduleId): bool
            {
                return $moduleId === 'alpha';
            }
        };

        $resolver = new class implements RouteResolver
        {
            public function resolve(BotContext $context): RoutingTable
            {
                return new RoutingTable($context->botId, [
                    new RouteEntry('alpha', 'telegram.command', '/a1'),
                    new RouteEntry('alpha', 'telegram.command', '/a2'),
                    new RouteEntry('beta', 'telegram.message', 'b1'),
                ]);
            }
        };

        $payload = (new ModuleDiagnostics($this->registryResult(), $probe, $resolver))->toArray('bot-1');

        self::assertSame('enabled', $payload['modules'][0]['activation']);
        self::assertSame(2, $payload['modules'][0]['routes']);
        self::assertSame('disabled', $payload['modules'][1]['activation']);
        self::assertSame(1, $payload['modules'][1]['routes']);
        self::assertSame([
            'id' => 'bot-1',
            'activation_available' => true,
            'routing_available' => true,
        ], $payload['bot']);
    }

    public function test_missing_database_tables_degrade_to_unavailable_without_throwing(): void
    {
        $probe = new class implements ActivationStateProbe
        {
            public function isEffectivelyEnabled(string $botId, string $moduleId): bool
            {
                throw new QueryException('sqlite', 'select 1', [], new PDOException('no such table'));
            }
        };

        $resolver = new class implements RouteResolver
        {
            public function resolve(BotContext $context): RoutingTable
            {
                throw new QueryException('sqlite', 'select 1', [], new PDOException('no such table'));
            }
        };

        $payload = (new ModuleDiagnostics($this->registryResult(), $probe, $resolver))->toArray('bot-1');

        self::assertSame(ModuleDiagnostics::STATE_UNAVAILABLE, $payload['modules'][0]['activation']);
        self::assertSame(ModuleDiagnostics::STATE_UNAVAILABLE, $payload['modules'][0]['routes']);
        self::assertFalse($payload['bot']['activation_available']);
        self::assertFalse($payload['bot']['routing_available']);
    }

    public function test_json_encoding_shape_has_modules_and_errors_keys(): void
    {
        $json = json_encode($this->diagnostics()->toArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('modules', $decoded);
        self::assertArrayHasKey('errors', $decoded);
        self::assertArrayNotHasKey('bot', $decoded);
    }

    public function test_metrics_summary_is_included_when_populated(): void
    {
        $metrics = new EngineMetrics;
        $metrics->increment('activation_denied', 3);
        $metrics->observeDurationMs('registry_lookup_ms', 12.4);

        $payload = (new ModuleDiagnostics($this->registryResult(), metrics: $metrics))->toArray();

        self::assertSame(['activation_denied' => 3, 'registry_lookup_ms' => 12], $payload['metrics']);
    }

    public function test_engine_metrics_counters_accumulate(): void
    {
        $metrics = new EngineMetrics;
        $metrics->increment('activation_denied');
        $metrics->increment('activation_denied');
        $metrics->increment('other');

        self::assertSame(['activation_denied' => 2, 'other' => 1], $metrics->summary());
    }

    private function registryResult(): RegistryResult
    {
        return new RegistryResult(new EngineModuleRegistry([
            $this->definition('beta', '0.2.0', false),
            $this->definition('alpha', '1.0.0', true),
        ]), []);
    }

    private function diagnostics(): ModuleDiagnostics
    {
        return new ModuleDiagnostics($this->registryResult());
    }

    private function definition(string $id, string $version, bool $enabled): TgModuleDefinition
    {
        return new TgModuleDefinition(
            configKey: $id,
            provider: 'Some\\Provider\\'.$id,
            descriptor: new TgModuleDescriptor(id: $id, name: ucfirst($id), version: $version),
            enabled: $enabled,
        );
    }
}
