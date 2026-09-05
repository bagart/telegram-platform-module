<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Capabilities;

/**
 * Machine-readable reason codes for dependency/capability diagnostics
 * (16 §102). A reason code is always paired with a human-readable message in
 * BlockReason so the Admin UI never re-derives blocking causes.
 */
enum BlockReasonCode: string
{
    case DependencyMissing = 'dependency_missing';
    case DependencyDisabled = 'dependency_disabled';
    case DependencyConflict = 'dependency_conflict';
    case CapabilityUnavailable = 'capability_unavailable';
    case ExclusiveCapabilityConflict = 'exclusive_capability_conflict';
    case CycleDetected = 'cycle_detected';
}
