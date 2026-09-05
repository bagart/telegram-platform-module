<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit\Settings;

use BAGArt\TelegramModuleEngine\Settings\SettingsDescriptor;
use BAGArt\TelegramModuleEngine\Settings\SettingsField;
use BAGArt\TelegramModuleEngine\Settings\SettingsFieldType;
use PHPUnit\Framework\TestCase;

final class SettingsDescriptorTest extends TestCase
{
    public function test_field_returns_matching_field(): void
    {
        $field = new SettingsField(
            fieldId: 'summarizer.model',
            type: SettingsFieldType::Enum,
            default: 'gpt-4',
            options: [
                ['value' => 'gpt-4', 'labelKey' => 'models.gpt4'],
                ['value' => 'gpt-3.5', 'labelKey' => 'models.gpt35'],
            ],
        );

        $descriptor = new SettingsDescriptor(fields: [$field]);

        self::assertSame($field, $descriptor->field('summarizer.model'));
    }

    public function test_field_returns_null_for_unknown_id(): void
    {
        $descriptor = new SettingsDescriptor(fields: []);

        self::assertNull($descriptor->field('unknown.field'));
    }

    public function test_descriptor_is_immutable(): void
    {
        $descriptor = new SettingsDescriptor(fields: [
            new SettingsField(fieldId: 'a', type: SettingsFieldType::Bool, default: true),
        ]);

        // Accessing fields should not throw
        self::assertCount(1, $descriptor->fields);
    }
}
