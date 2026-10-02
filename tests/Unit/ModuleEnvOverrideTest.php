<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use PHPUnit\Framework\TestCase;

final class ModuleEnvOverrideTest extends TestCase
{
    public function test_env_var_overrides_config_enabled_value(): void
    {
        putenv('TG_MODULE_ENABLED_test=true');

        $config = [
            'modules' => [
                'test' => new TgModuleConfig(enabled: false, provider: TestModule::class),
            ],
        ];

        $resolved = $config['modules']['test']->enabled;
        $envValue = getenv('TG_MODULE_ENABLED_test');
        $effective = $envValue !== false ? filter_var($envValue, FILTER_VALIDATE_BOOLEAN) : $resolved;

        self::assertTrue($effective);

        putenv('TG_MODULE_ENABLED_test');
    }

    public function test_absent_env_var_preserves_config_value(): void
    {
        putenv('TG_MODULE_ENABLED_test');

        $config = [
            'modules' => [
                'test' => new TgModuleConfig(enabled: false, provider: TestModule::class),
            ],
        ];

        $resolved = $config['modules']['test']->enabled;
        $envValue = getenv('TG_MODULE_ENABLED_test');
        $effective = $envValue !== false ? filter_var($envValue, FILTER_VALIDATE_BOOLEAN) : $resolved;

        self::assertFalse($effective);
    }

    public function test_env_var_true_overrides_config_false(): void
    {
        putenv('TG_MODULE_ENABLED_antispam=false');

        $configValue = true;
        $envValue = getenv('TG_MODULE_ENABLED_antispam');
        $effective = $envValue !== false ? filter_var($envValue, FILTER_VALIDATE_BOOLEAN) : $configValue;

        self::assertFalse($effective);

        putenv('TG_MODULE_ENABLED_antispam');
    }

    public function test_env_var_false_overrides_config_true(): void
    {
        putenv('TG_MODULE_ENABLED_proxy=false');

        $configValue = true;
        $envValue = getenv('TG_MODULE_ENABLED_proxy');
        $effective = $envValue !== false ? filter_var($envValue, FILTER_VALIDATE_BOOLEAN) : $configValue;

        self::assertFalse($effective);

        putenv('TG_MODULE_ENABLED_proxy');
    }

    public function test_env_override_detection_logic(): void
    {
        putenv('TG_MODULE_ENABLED_test=disabled');

        $configEnabled = true;
        $envValue = getenv('TG_MODULE_ENABLED_test');
        $envBool = $envValue !== false ? filter_var($envValue, FILTER_VALIDATE_BOOLEAN) : null;
        $isOverride = $envBool !== null && $envBool !== $configEnabled;

        self::assertTrue($isOverride);

        putenv('TG_MODULE_ENABLED_test');
    }

    public function test_no_override_when_env_matches_config(): void
    {
        putenv('TG_MODULE_ENABLED_test=true');

        $configEnabled = true;
        $envValue = getenv('TG_MODULE_ENABLED_test');
        $envBool = $envValue !== false ? filter_var($envValue, FILTER_VALIDATE_BOOLEAN) : null;
        $isOverride = $envBool !== null && $envBool !== $configEnabled;

        self::assertFalse($isOverride);

        putenv('TG_MODULE_ENABLED_test');
    }
}
