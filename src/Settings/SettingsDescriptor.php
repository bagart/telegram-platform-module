<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

/**
 * Typed field list for a settings screen — the declarative descriptor
 * that a generic renderer (web or Telegram) consumes to build a form.
 *
 * Pure DTO: no I/O, no behavior. Modules contribute one of these per
 * settings screen; the engine merges and resolves per bot/chat scope.
 */
final readonly class SettingsDescriptor
{
    /**
     * @param  list<SettingsField>  $fields  Ordered field definitions.
     */
    public function __construct(
        public array $fields = [],
    ) {
    }

    /**
     * Find a field by ID.
     */
    public function field(string $fieldId): ?SettingsField
    {
        foreach ($this->fields as $field) {
            if ($field->fieldId === $fieldId) {
                return $field;
            }
        }

        return null;
    }
}
