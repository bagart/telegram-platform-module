<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

/**
 * A typed setting field contribution from a module.
 *
 * Pure DTO — describes one configurable field with its type, default, range,
 * and i18n keys. No I/O, no behavior, same style as TelegramModuleConfig.
 */
final readonly class SettingsField
{
    /**
     * @param  string  $fieldId  Stable identifier, e.g. 'summarizer.model'.
     * @param  SettingsFieldType  $type  Field type for rendering.
     * @param  mixed  $default  Default value.
     * @param  string|null  $labelKey  i18n key for the field label.
     * @param  string|null  $descriptionKey  i18n key for help text.
     * @param  int|null  $min  Minimum value (for int/float fields).
     * @param  int|null  $max  Maximum value (for int/float fields).
     * @param  list<array{value: string, labelKey: string}>|null  $options  Enum options (for enum fields).
     * @param  bool  $required  Whether the field is required.
     */
    public function __construct(
        public string $fieldId,
        public SettingsFieldType $type,
        public mixed $default = null,
        public ?string $labelKey = null,
        public ?string $descriptionKey = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?array $options = null,
        public bool $required = true,
    ) {
    }
}
