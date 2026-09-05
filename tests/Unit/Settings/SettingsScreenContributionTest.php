<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit\Settings;

use BAGArt\TelegramModuleEngine\Settings\SettingsDescriptor;
use BAGArt\TelegramModuleEngine\Settings\SettingsField;
use BAGArt\TelegramModuleEngine\Settings\SettingsFieldType;
use BAGArt\TelegramModuleEngine\Settings\SettingsScreenContribution;
use BAGArt\TelegramModuleEngine\Settings\TelegramScreenBinding;
use BAGArt\TelegramModuleEngine\Settings\WebAccessLevel;
use BAGArt\TelegramModuleEngine\Settings\WebScreenBinding;
use PHPUnit\Framework\TestCase;

final class SettingsScreenContributionTest extends TestCase
{
    public function test_contribution_with_web_only(): void
    {
        $contribution = new SettingsScreenContribution(
            screenId: 'summarizer.settings',
            descriptor: new SettingsDescriptor(fields: [
                new SettingsField(fieldId: 'model', type: SettingsFieldType::String, default: 'gpt-4'),
            ]),
            web: new WebScreenBinding(component: 'Settings/Summarizer'),
        );

        self::assertSame('summarizer.settings', $contribution->screenId);
        self::assertNotNull($contribution->web);
        self::assertNull($contribution->telegram);
        self::assertSame('Settings/Summarizer', $contribution->web->component);
        self::assertSame(WebAccessLevel::BotAdmin, $contribution->web->accessLevel);
    }

    public function test_contribution_with_telegram_binding(): void
    {
        $contribution = new SettingsScreenContribution(
            screenId: 'tts.settings',
            descriptor: new SettingsDescriptor(),
            telegram: new TelegramScreenBinding(
                screenId: 'tts_menu_settings',
                requiredCapability: 'module.config.change',
            ),
        );

        self::assertNull($contribution->web);
        self::assertNotNull($contribution->telegram);
        self::assertSame('tts_menu_settings', $contribution->telegram->screenId);
        self::assertSame('module.config.change', $contribution->telegram->requiredCapability);
    }

    public function test_contribution_with_null_bindings_is_descriptor_only(): void
    {
        $contribution = new SettingsScreenContribution(
            screenId: 'proxy.settings',
            descriptor: new SettingsDescriptor(),
        );

        self::assertNull($contribution->web);
        self::assertNull($contribution->telegram);
    }

    public function test_contribution_is_immutable(): void
    {
        $contribution = new SettingsScreenContribution(
            screenId: 'test',
            descriptor: new SettingsDescriptor(),
        );

        $this->expectException(\Error::class);
        $contribution->screenId = 'changed';
    }
}
