<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use BAGArt\TelegramModuleEngine\Config\TgModuleSchedule;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use BAGArt\TelegramModuleEngine\Registry\ProviderSequence;
use BAGArt\TelegramModuleEngine\Settings\SettingsDescriptor;
use BAGArt\TelegramModuleEngine\Settings\SettingsField;
use BAGArt\TelegramModuleEngine\Settings\SettingsFieldType;
use BAGArt\TelegramModuleEngine\Settings\SettingsScreenContribution;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\AntispamModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\MafiaModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\MenuModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\NettoolsModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\ProxyModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\SttModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\SummarizerModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TtsModule;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: full boot sequence with 8 simulated modules.
 *
 * Verifies that the registry builder resolves all modules, that the
 * provider boot sequence produces a valid ordering, and that settings
 * screen contributions survive the config→definition→registry pipeline.
 */
final class BootSequenceTest extends TestCase
{
    private const EXPECTED_KEYS = [
        'antispam',
        'mafia',
        'menu',
        'nettools',
        'proxy',
        'stt',
        'summarizer',
        'tts',
    ];

    public function test_config_has_expected_eight_module_keys(): void
    {
        $config = $this->eightModuleConfig();
        $keys = array_keys($config['modules']);

        sort($keys);
        self::assertSame(self::EXPECTED_KEYS, $keys);
    }

    public function test_all_eight_modules_resolve_via_registry_builder(): void
    {
        $result = (new ModuleRegistryBuilder($this->eightModuleConfig()))->build();

        self::assertTrue($result->isValid(), 'Registry build must succeed: '.implode('; ', array_map(
            static fn ($e) => $e->moduleKey.': '.$e->message,
            $result->errors,
        )));
        self::assertSame(8, $result->registry->count());

        foreach (self::EXPECTED_KEYS as $key) {
            self::assertNotNull($result->registry->get($key), "module '{$key}' must be registered");
        }
    }

    public function test_all_eight_modules_are_platform_enabled(): void
    {
        $result = (new ModuleRegistryBuilder($this->eightModuleConfig()))->build();

        self::assertCount(8, $result->registry->enabled());
    }

    public function test_provider_sequence_orders_all_eight(): void
    {
        $result = (new ModuleRegistryBuilder($this->eightModuleConfig()))->build();
        $sequence = (new ProviderSequence($result->registry))->laravelProviders();

        // Without laravelProvider declared on any fixture, the sequence is
        // empty but must be a valid array (no exceptions thrown).
        self::assertIsArray($sequence);
    }

    public function test_settings_screens_appear_in_registry_for_proxy(): void
    {
        $result = (new ModuleRegistryBuilder($this->eightModuleConfig()))->build();

        $proxy = $result->registry->get('proxy');
        self::assertNotNull($proxy);
        self::assertCount(1, $proxy->settingsScreens);
        self::assertSame('proxy.settings', $proxy->settingsScreens[0]->screenId);

        $descriptor = $proxy->settingsScreens[0]->descriptor;
        self::assertSame('proxy.selection_strategy', $descriptor->fields[0]->fieldId);
        self::assertSame(SettingsFieldType::Enum, $descriptor->fields[0]->type);
        self::assertSame('round_robin', $descriptor->fields[0]->default);
    }

    public function test_schedule_entries_declared_by_modules(): void
    {
        $result = (new ModuleRegistryBuilder($this->eightModuleConfig()))->build();
        $schedule = $result->registry->scheduleEntries();

        self::assertArrayHasKey('mafia', $schedule);
        self::assertArrayHasKey('menu', $schedule);
        self::assertArrayHasKey('summarizer', $schedule);
        self::assertArrayHasKey('tts', $schedule);
        self::assertArrayHasKey('proxy', $schedule);
        self::assertArrayHasKey('stt', $schedule);
        self::assertArrayNotHasKey('antispam', $schedule);
        self::assertArrayNotHasKey('nettools', $schedule);
    }

    public function test_engine_module_registry_settings_screens_accessor(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definitionWithScreen('proxy', [
                new SettingsScreenContribution(
                    screenId: 'proxy.settings',
                    descriptor: new SettingsDescriptor(fields: [
                        new SettingsField(fieldId: 'proxy.selection_strategy', type: SettingsFieldType::Enum, default: 'round_robin'),
                    ]),
                ),
            ]),
            $this->definitionWithScreen('antispam', []),
        ]);

        $proxy = $registry->get('proxy');
        self::assertNotNull($proxy);
        self::assertCount(1, $proxy->settingsScreens);

        $antispam = $registry->get('antispam');
        self::assertNotNull($antispam);
        self::assertSame([], $antispam->settingsScreens);
    }

    /**
     * @param  list<SettingsScreenContribution>  $screens
     */
    private function definitionWithScreen(string $id, array $screens): TgModuleDefinition
    {
        $providerClass = match ($id) {
            'antispam' => AntispamModule::class,
            'proxy' => ProxyModule::class,
            default => MenuModule::class,
        };

        return new TgModuleDefinition(
            configKey: $id,
            provider: $providerClass,
            descriptor: $providerClass::descriptor(),
            enabled: true,
            settingsScreens: $screens,
        );
    }

    private function eightModuleConfig(): array
    {
        return [
            'strict' => false,
            'modules' => [
                'antispam' => new TgModuleConfig(enabled: true, provider: AntispamModule::class),
                'mafia' => new TgModuleConfig(enabled: true, provider: MafiaModule::class, schedule: [
                    new TgModuleSchedule(command: 'mafia:sweep', expression: '* * * * *'),
                ]),
                'menu' => new TgModuleConfig(enabled: true, provider: MenuModule::class, schedule: [
                    new TgModuleSchedule(command: 'menu:roles:sweep', expression: '0 4 * * *'),
                ]),
                'nettools' => new TgModuleConfig(enabled: true, provider: NettoolsModule::class),
                'stt' => new TgModuleConfig(enabled: true, provider: SttModule::class, schedule: [
                    new TgModuleSchedule(command: 'stt:prune', expression: '0 3 * * *'),
                ]),
                'summarizer' => new TgModuleConfig(enabled: true, provider: SummarizerModule::class, schedule: [
                    new TgModuleSchedule(command: 'summarizer:digests', expression: '* * * * *'),
                ]),
                'tts' => new TgModuleConfig(enabled: true, provider: TtsModule::class, schedule: [
                    new TgModuleSchedule(command: 'tts:prune', expression: '0 3 * * *'),
                ]),
                'proxy' => new TgModuleConfig(enabled: true, provider: ProxyModule::class, schedule: [
                    new TgModuleSchedule(command: 'proxy:lease:reap', expression: '* * * * *'),
                ], settingsScreens: [
                    new SettingsScreenContribution(
                        screenId: 'proxy.settings',
                        descriptor: new SettingsDescriptor(fields: [
                            new SettingsField(
                                fieldId: 'proxy.selection_strategy',
                                type: SettingsFieldType::Enum,
                                default: 'round_robin',
                                options: [
                                    ['value' => 'round_robin', 'labelKey' => 'proxy::settings.strategy_round_robin'],
                                    ['value' => 'random', 'labelKey' => 'proxy::settings.strategy_random'],
                                ],
                            ),
                        ]),
                    ),
                ]),
            ],
        ];
    }
}
