<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

/**
 * Setting field type enum for rendering dispatch.
 */
enum SettingsFieldType: string
{
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case String = 'string';
    case Enum = 'enum';
    case Text = 'text';
}
