<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Resources;

/**
 * Marker contract for all engine resource descriptors.
 * Resource descriptors are immutable data objects — no behavior, no side effects.
 */
interface ResourceContract
{
    /** Stable identity: {moduleId}.{resourceType}.{localId}. */
    public function id(): string;

    /** Owning module ID. */
    public function moduleId(): string;

    /** Resource type for registry catalog. */
    public function type(): string;
}
