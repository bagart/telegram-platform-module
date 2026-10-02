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

    public function test_validate_below_min_returns_error(): void
    {
        $field = new SettingsField(
            fieldId: 'proxy.lease_ttl',
            type: SettingsFieldType::Int,
            min: 10,
            max: 3600,
        );

        $errors = $field->validate(5);

        self::assertCount(1, $errors);
        self::assertStringContainsString('at least', $errors[0]);
    }

    public function test_validate_at_min_passes(): void
    {
        $field = new SettingsField(
            fieldId: 'proxy.lease_ttl',
            type: SettingsFieldType::Int,
            min: 10,
            max: 3600,
        );

        self::assertSame([], $field->validate(10));
    }

    public function test_validate_at_max_passes(): void
    {
        $field = new SettingsField(
            fieldId: 'proxy.lease_ttl',
            type: SettingsFieldType::Int,
            min: 10,
            max: 3600,
        );

        self::assertSame([], $field->validate(3600));
    }

    public function test_validate_above_max_returns_error(): void
    {
        $field = new SettingsField(
            fieldId: 'proxy.lease_ttl',
            type: SettingsFieldType::Int,
            min: 10,
            max: 3600,
        );

        $errors = $field->validate(3601);

        self::assertCount(1, $errors);
        self::assertStringContainsString('at most', $errors[0]);
    }

    public function test_validate_non_numeric_for_int_field(): void
    {
        $field = new SettingsField(
            fieldId: 'proxy.count',
            type: SettingsFieldType::Int,
            min: 0,
            max: 100,
        );

        $errors = $field->validate('not-a-number');

        self::assertCount(1, $errors);
        self::assertStringContainsString('numeric', $errors[0]);
    }

    public function test_validate_null_required_field_returns_error(): void
    {
        $field = new SettingsField(
            fieldId: 'required.field',
            type: SettingsFieldType::String,
            required: true,
        );

        $errors = $field->validate(null);

        self::assertCount(1, $errors);
        self::assertStringContainsString('required', $errors[0]);
    }

    public function test_validate_null_optional_field_passes(): void
    {
        $field = new SettingsField(
            fieldId: 'optional.field',
            type: SettingsFieldType::String,
            required: false,
        );

        self::assertSame([], $field->validate(null));
    }

    public function test_validate_invalid_enum_value(): void
    {
        $field = new SettingsField(
            fieldId: 'tts.voice',
            type: SettingsFieldType::Enum,
            options: [
                ['value' => 'alloy', 'labelKey' => 'voice.alloy'],
                ['value' => 'echo', 'labelKey' => 'voice.echo'],
            ],
        );

        $errors = $field->validate('invalid');

        self::assertCount(1, $errors);
        self::assertStringContainsString('one of', $errors[0]);
    }

    public function test_validate_valid_enum_value_passes(): void
    {
        $field = new SettingsField(
            fieldId: 'tts.voice',
            type: SettingsFieldType::Enum,
            options: [
                ['value' => 'alloy', 'labelKey' => 'voice.alloy'],
                ['value' => 'echo', 'labelKey' => 'voice.echo'],
            ],
        );

        self::assertSame([], $field->validate('alloy'));
    }

    public function test_validate_float_field_respects_range(): void
    {
        $field = new SettingsField(
            fieldId: 'proxy.ratio',
            type: SettingsFieldType::Float,
            min: 0,
            max: 1,
        );

        self::assertSame([], $field->validate(0.5));
        self::assertSame([], $field->validate(0));
        self::assertSame([], $field->validate(1));
        self::assertCount(1, $field->validate(-0.1));
        self::assertCount(1, $field->validate(1.1));
    }

    public function test_validate_no_min_max_passes_any_numeric(): void
    {
        $field = new SettingsField(
            fieldId: 'proxy.unbounded',
            type: SettingsFieldType::Int,
        );

        self::assertSame([], $field->validate(-9999));
        self::assertSame([], $field->validate(9999));
        self::assertSame([], $field->validate(0));
    }
}
