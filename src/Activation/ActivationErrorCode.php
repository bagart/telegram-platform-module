<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

/**
 * Machine-readable reason codes for structured activation outcomes
 * (doc 40 §7 dependency errors, doc 38 §47: results are typed, never bare
 * false). Business outcomes are returned, never thrown.
 */
enum ActivationErrorCode: string
{
    /** Module has no definition in the registry or is platform-disabled. */
    case ModuleNotRegistered = 'module_not_registered';

    /** Required dependency module does not exist / is platform-disabled. */
    case DependencyNotRegistered = 'dependency_not_registered';

    /** Required dependency has an activation row explicitly disabled for the bot. */
    case DependencyDisabled = 'dependency_disabled';

    /** Required dependency has no activation row and its descriptor default is disabled. */
    case DependencyNotEnabled = 'dependency_not_enabled';
}
