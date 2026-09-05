<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit\Settings;

use BAGArt\TelegramModuleEngine\Settings\SettingsField;
use BAGArt\TelegramModuleEngine\Settings\SettingsFieldType;
use PHPUnit\Framework\TestCase;

final class SettingsFieldTest extends TestCase
{
    public function test_field_creation(): void
    {
        $field = new SettingsField(
            fieldId: 'summarizer.interval',
            type: SettingsFieldType::Int,
            default: 60,
            min: 10,
            max: 3600,
            labelKey: 'summarizer.interval.label',
            descriptionKey: 'summarizer.interval.desc',
        );

        self::assertSame('summarizer.interval', $field->fieldId);
        self::assertSame(SettingsFieldType::Int, $field->type);
        self::assertSame(60, $field->default);
        self::assertSame(10, $field->min);
        self::assertSame(3600, $field->max);
        self::assertSame('summarizer.interval.label', $field->labelKey);
        self::assertSame('summarizer.interval.desc', $field->descriptionKey);
        self::assertTrue($field->required);
    }

    public function test_optional_field(): void
    {
        $field = new SettingsField(
            fieldId: 'proxy.api_key',
            type: SettingsFieldType::String,
            required: false,
        );

        self::assertFalse($field->required);
        self::assertNull($field->default);
    }

    public function test_enum_field_with_options(): void
    {
        $field = new SettingsField(
            fieldId: 'tts.voice',
            type: SettingsFieldType::Enum,
            default: 'alloy',
            options: [
                ['value' => 'alloy', 'labelKey' => 'voice.alloy'],
                ['value' => 'echo', 'labelKey' => 'voice.echo'],
            ],
        );

        self::assertSame(SettingsFieldType::Enum, $field->type);
        self::assertCount(2, $field->options);
        self::assertSame('alloy', $field->options[0]['value']);
    }
}
