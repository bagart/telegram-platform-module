<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Routing;

/**
 * Immutable result of one resolution: every consumer of the same operation
 * sees the same entries (doc 12 §29-30). Disabled modules never appear.
 */
final readonly class RoutingTable
{
    /**
     * @param  string  $botId  bot the table was resolved for
     * @param  list<RouteEntry>  $entries  deterministic order: priority desc,
     *                                     then entry key asc
     */
    public function __construct(
        public string $botId,
        public array $entries,
    ) {
    }

    /** @return list<string> unique sorted module ids present in the table */
    public function moduleIds(): array
    {
        $ids = [];
        foreach ($this->entries as $entry) {
            $ids[$entry->moduleId] = true;
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * All entries contributed by one module, table order preserved.
     *
     * @return list<RouteEntry>
     */
    public function forModule(string $moduleId): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (RouteEntry $entry): bool => $entry->moduleId === $moduleId,
        ));
    }
}
