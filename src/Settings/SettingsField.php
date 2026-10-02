<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

/**
 * A typed setting field contribution from a module.
 *
 * Describes one configurable field with its type, default, range, and i18n
 * keys, and validates candidate values against those constraints. No I/O.
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

    /**
     * Validate a candidate value against this field's constraints.
     *
     * @return list<string> Error messages, empty when the value is valid.
     */
    public function validate(mixed $value): array
    {
        if ($value === null) {
            return $this->required
                ? [sprintf('%s is required', $this->fieldId)]
                : [];
        }

        if ($this->type === SettingsFieldType::Int || $this->type === SettingsFieldType::Float) {
            if (! is_numeric($value)) {
                return [sprintf('%s must be numeric', $this->fieldId)];
            }

            $errors = [];

            if ($this->min !== null && $value < $this->min) {
                $errors[] = sprintf('%s must be at least %s', $this->fieldId, $this->min);
            }

            if ($this->max !== null && $value > $this->max) {
                $errors[] = sprintf('%s must be at most %s', $this->fieldId, $this->max);
            }

            return $errors;
        }

        if ($this->type === SettingsFieldType::Enum && $this->options !== null) {
            $allowed = array_column($this->options, 'value');

            if (! in_array($value, $allowed, true)) {
                return [sprintf('%s must be one of: %s', $this->fieldId, implode(', ', $allowed))];
            }
        }

        return [];
    }
}
