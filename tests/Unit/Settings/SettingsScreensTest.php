<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit\Settings;

use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use BAGArt\TelegramModuleEngine\Settings\SettingsDescriptor;
use BAGArt\TelegramModuleEngine\Settings\SettingsField;
use BAGArt\TelegramModuleEngine\Settings\SettingsFieldType;
use BAGArt\TelegramModuleEngine\Settings\SettingsScreenContribution;
use BAGArt\TelegramModuleEngine\Settings\WebAccessLevel;
use BAGArt\TelegramModuleEngine\Settings\WebScreenBinding;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that settingsScreens declared in TgModuleConfig survive the
 * full config→definition→registry pipeline and are accessible on the
 * built EngineModuleRegistry.
 */
final class SettingsScreensTest extends TestCase
{
    public function test_settings_screen_appears_in_registry_after_build(): void
    {
        $config = $this->configWithScreens([
            new SettingsScreenContribution(
                screenId: 'proxy.settings',
                descriptor: new SettingsDescriptor(fields: [
                    new SettingsField(
                        fieldId: 'proxy.selection_strategy',
                        type: SettingsFieldType::Enum,
                        default: 'round_robin',
                        options: [
                            ['value' => 'round_robin', 'labelKey' => 'proxy::settings.round_robin'],
                        ],
                    ),
                ]),
                web: new WebScreenBinding(component: 'Settings/Proxy'),
            ),
        ]);

        $result = (new ModuleRegistryBuilder($config))->build();
        self::assertTrue($result->isValid());

        $definition = $result->registry->get('test');
        self::assertNotNull($definition);
        self::assertCount(1, $definition->settingsScreens);
        self::assertSame('proxy.settings', $definition->settingsScreens[0]->screenId);
    }

    public function test_multiple_settings_screens_preserve_order(): void
    {
        $config = $this->configWithScreens([
            new SettingsScreenContribution(
                screenId: 'screen-a',
                descriptor: new SettingsDescriptor(fields: [
                    new SettingsField(fieldId: 'a.field', type: SettingsFieldType::String),
                ]),
            ),
            new SettingsScreenContribution(
                screenId: 'screen-b',
                descriptor: new SettingsDescriptor(fields: [
                    new SettingsField(fieldId: 'b.field', type: SettingsFieldType::Bool, default: false),
                ]),
            ),
        ]);

        $result = (new ModuleRegistryBuilder($config))->build();
        $definition = $result->registry->get('test');

        self::assertCount(2, $definition->settingsScreens);
        self::assertSame('screen-a', $definition->settingsScreens[0]->screenId);
        self::assertSame('screen-b', $definition->settingsScreens[1]->screenId);
    }

    public function test_settings_screens_field_details_survive_pipeline(): void
    {
        $field = new SettingsField(
            fieldId: 'proxy.lease_ttl_seconds',
            type: SettingsFieldType::Int,
            default: 300,
            labelKey: 'proxy::settings.lease_ttl',
            descriptionKey: 'proxy::settings.lease_ttl_desc',
            min: 60,
            max: 3600,
            required: true,
        );
        $config = $this->configWithScreens([
            new SettingsScreenContribution(
                screenId: 'proxy.settings',
                descriptor: new SettingsDescriptor(fields: [$field]),
            ),
        ]);

        $result = (new ModuleRegistryBuilder($config))->build();
        $built = $result->registry->get('test')->settingsScreens[0]->descriptor->fields[0];

        self::assertSame('proxy.lease_ttl_seconds', $built->fieldId);
        self::assertSame(SettingsFieldType::Int, $built->type);
        self::assertSame(300, $built->default);
        self::assertSame('proxy::settings.lease_ttl', $built->labelKey);
        self::assertSame('proxy::settings.lease_ttl_desc', $built->descriptionKey);
        self::assertSame(60, $built->min);
        self::assertSame(3600, $built->max);
        self::assertTrue($built->required);
    }

    public function test_empty_settings_screens_stays_empty(): void
    {
        $config = $this->configWithScreens([]);
        $result = (new ModuleRegistryBuilder($config))->build();
        $definition = $result->registry->get('test');

        self::assertSame([], $definition->settingsScreens);
    }

    public function test_disabled_module_settings_screens_not_in_enabled_list(): void
    {
        $config = [
            'strict' => false,
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: false,
                    provider: TestModule::class,
                    settingsScreens: [
                        new SettingsScreenContribution(
                            screenId: 'test.settings',
                            descriptor: new SettingsDescriptor(),
                        ),
                    ],
                ),
            ],
        ];

        $result = (new ModuleRegistryBuilder($config))->build();
        self::assertSame(1, $result->registry->count());

        // The definition is registered (even though disabled), so the screens
        // are accessible on the definition, but the module is not in enabled().
        $definition = $result->registry->get('test');
        self::assertFalse($definition->enabled);
        self::assertCount(1, $definition->settingsScreens);
    }

    public function test_web_binding_survives_pipeline(): void
    {
        $config = $this->configWithScreens([
            new SettingsScreenContribution(
                screenId: 'proxy.settings',
                descriptor: new SettingsDescriptor(),
                web: new WebScreenBinding(
                    component: 'Settings/Proxy',
                    accessLevel: WebAccessLevel::PlatformAdmin,
                ),
            ),
        ]);

        $result = (new ModuleRegistryBuilder($config))->build();
        $screen = $result->registry->get('test')->settingsScreens[0];

        self::assertNotNull($screen->web);
        self::assertSame('Settings/Proxy', $screen->web->component);
        self::assertSame(WebAccessLevel::PlatformAdmin, $screen->web->accessLevel);
    }

    public function test_antispam_settings_screen_has_expected_fields(): void
    {
        $config = $this->loadPlatformConfig();
        $result = (new ModuleRegistryBuilder($config))->build();
        $definition = $result->registry->get('antispam');

        self::assertNotNull($definition);
        self::assertCount(1, $definition->settingsScreens);
        self::assertSame('antispam.settings', $definition->settingsScreens[0]->screenId);

        $fields = $definition->settingsScreens[0]->descriptor->fields;
        self::assertCount(5, $fields);
        self::assertSame('antispam.counter_driver', $fields[0]->fieldId);
        self::assertSame(SettingsFieldType::Enum, $fields[0]->type);
        self::assertSame('antispam.ai.enabled', $fields[1]->fieldId);
        self::assertSame(SettingsFieldType::Bool, $fields[1]->type);
        self::assertSame('antispam.blocklist.retention_days', $fields[2]->fieldId);
        self::assertSame(1, $fields[2]->min);
        self::assertSame(365, $fields[2]->max);
        self::assertSame('antispam.cache_ttl_seconds', $fields[3]->fieldId);
        self::assertSame(30, $fields[3]->min);
        self::assertSame(600, $fields[3]->max);
        self::assertSame('antispam.instrumentation', $fields[4]->fieldId);
        self::assertSame(SettingsFieldType::Bool, $fields[4]->type);

        self::assertNotNull($definition->settingsScreens[0]->web);
        self::assertSame('Settings/Antispam', $definition->settingsScreens[0]->web->component);
        self::assertSame(WebAccessLevel::PlatformAdmin, $definition->settingsScreens[0]->web->accessLevel);
    }

    public function test_summarizer_settings_screen_has_expected_fields(): void
    {
        $config = $this->loadPlatformConfig();
        $result = (new ModuleRegistryBuilder($config))->build();
        $definition = $result->registry->get('summarizer');

        self::assertNotNull($definition);
        self::assertCount(1, $definition->settingsScreens);
        self::assertSame('summarizer.settings', $definition->settingsScreens[0]->screenId);

        $fields = $definition->settingsScreens[0]->descriptor->fields;
        self::assertCount(5, $fields);
        self::assertSame('summarizer.retention_days', $fields[0]->fieldId);
        self::assertSame(1, $fields[0]->min);
        self::assertSame(90, $fields[0]->max);
        self::assertSame('summarizer.transcript_budget_chars', $fields[1]->fieldId);
        self::assertSame(1000, $fields[1]->min);
        self::assertSame(500000, $fields[1]->max);
        self::assertSame('summarizer.max_transcript_messages', $fields[2]->fieldId);
        self::assertSame(100, $fields[2]->min);
        self::assertSame(10000, $fields[2]->max);
        self::assertSame('summarizer.llm_timeout_seconds', $fields[3]->fieldId);
        self::assertSame(5, $fields[3]->min);
        self::assertSame(300, $fields[3]->max);
        self::assertSame('summarizer.pending_input_ttl_minutes', $fields[4]->fieldId);
        self::assertSame(1, $fields[4]->min);
        self::assertSame(120, $fields[4]->max);

        self::assertNotNull($definition->settingsScreens[0]->web);
        self::assertSame('Settings/Summarizer', $definition->settingsScreens[0]->web->component);
    }

    public function test_tts_settings_screen_has_expected_fields(): void
    {
        $config = $this->loadPlatformConfig();
        $result = (new ModuleRegistryBuilder($config))->build();
        $definition = $result->registry->get('tts');

        self::assertNotNull($definition);
        self::assertCount(1, $definition->settingsScreens);
        self::assertSame('tts.settings', $definition->settingsScreens[0]->screenId);

        $fields = $definition->settingsScreens[0]->descriptor->fields;
        self::assertCount(4, $fields);
        self::assertSame('tts.budget_seconds', $fields[0]->fieldId);
        self::assertSame(5, $fields[0]->min);
        self::assertSame(120, $fields[0]->max);
        self::assertSame('tts.global_concurrency', $fields[1]->fieldId);
        self::assertSame(1, $fields[1]->min);
        self::assertSame(20, $fields[1]->max);
        self::assertSame('tts.timeout_seconds', $fields[2]->fieldId);
        self::assertSame(5, $fields[2]->min);
        self::assertSame(60, $fields[2]->max);
        self::assertSame('tts.retention_days', $fields[3]->fieldId);
        self::assertSame(1, $fields[3]->min);
        self::assertSame(90, $fields[3]->max);

        self::assertNotNull($definition->settingsScreens[0]->web);
        self::assertSame('Settings/Tts', $definition->settingsScreens[0]->web->component);
    }

    public function test_nettools_settings_screen_has_expected_fields(): void
    {
        $config = $this->loadPlatformConfig();
        $result = (new ModuleRegistryBuilder($config))->build();
        $definition = $result->registry->get('nettools');

        self::assertNotNull($definition);
        self::assertCount(1, $definition->settingsScreens);
        self::assertSame('nettools.settings', $definition->settingsScreens[0]->screenId);

        $fields = $definition->settingsScreens[0]->descriptor->fields;
        self::assertCount(9, $fields);
        self::assertSame('nettools.features.recon', $fields[0]->fieldId);
        self::assertTrue($fields[0]->default);
        self::assertSame('nettools.features.active', $fields[1]->fieldId);
        self::assertSame('nettools.features.audit', $fields[2]->fieldId);
        self::assertSame('nettools.features.portscan', $fields[3]->fieldId);
        self::assertFalse($fields[3]->default);
        self::assertSame('nettools.features.dnsbl', $fields[4]->fieldId);
        self::assertFalse($fields[4]->default);
        self::assertSame('nettools.quotas.daily_units', $fields[5]->fieldId);
        self::assertSame(1, $fields[5]->min);
        self::assertSame(500, $fields[5]->max);
        self::assertSame('nettools.quotas.chat_ceiling', $fields[6]->fieldId);
        self::assertSame(10, $fields[6]->min);
        self::assertSame(2000, $fields[6]->max);
        self::assertSame('nettools.ui.heavy_confirm', $fields[7]->fieldId);
        self::assertSame('nettools.memory.enabled', $fields[8]->fieldId);

        self::assertNotNull($definition->settingsScreens[0]->web);
        self::assertSame('Settings/Nettools', $definition->settingsScreens[0]->web->component);
    }

    /**
     * @return array{strict: bool, modules: array<string, TgModuleConfig>}
     */
    private function loadPlatformConfig(): array
    {
        $root = dirname(__DIR__, 6);
        $configPath = $root . '/config/tg_modules.php';

        if (! file_exists($configPath)) {
            self::markTestSkipped("Platform config not found at {$configPath}");
        }

        // The config file uses base_path(); Laravel's helper delegates to
        // app()->basePath(). In plain pest context app() is a bare Container,
        // so swap in a minimal Application for the require, then restore.
        $previous = Container::getInstance();
        $swapped = ! method_exists($previous, 'basePath');

        if ($swapped) {
            Container::setInstance(new Application($root));
        }

        try {
            return require $configPath;
        } finally {
            if ($swapped) {
                Container::setInstance($previous);
            }
        }
    }

    /**
     * @param  list<SettingsScreenContribution>  $screens
     * @return array{strict: bool, modules: array{test: TgModuleConfig}}
     */
    private function configWithScreens(array $screens): array
    {
        return [
            'strict' => false,
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    settingsScreens: $screens,
                ),
            ],
        ];
    }
}
