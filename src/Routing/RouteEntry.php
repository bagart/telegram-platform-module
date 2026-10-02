<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Routing;

/**
 * One routing entry a module contributes for one bot (doc 33 Part 1: the
 * dispatcher's routing table is built on the control path and stored in
 * PostgreSQL; the engine is only a dumb proxy over it).
 */
final readonly class RouteEntry
{
    /**
     * @param  string  $moduleId  owning module (stable logical id)
     * @param  string  $entryType  typed entry kind, e.g. "command", "callback"
     * @param  string  $entryKey  dispatch key within the type, e.g. "/start"
     * @param  int  $priority  secondary ordering hint; dependencies matter more
     * @param  array<string, mixed>|null  $payload  opaque dispatcher payload
     */
    public function __construct(
        public string $moduleId,
        public string $entryType,
        public string $entryKey,
        public int $priority = 0,
        public ?array $payload = null,
    ) {
    }
}
