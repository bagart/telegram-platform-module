<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Routing;

/**
 * One declarative route entry a module contributes to the per-bot routing
 * table (TgModuleConfig::$routes). Mirrors a bot_module_routes row minus the
 * bot/tenant dimensions.
 */
final readonly class RouteDeclaration
{
    public function __construct(
        /** Entry type, e.g. 'command', 'callback', 'http.route'. */
        public string $type,
        /** External key within the type, e.g. '/menu'. */
        public string $key,
        public int $priority = 0,
        /** @var array<string, mixed>|null free-form payload persisted as JSON */
        public ?array $payload = null,
    ) {}
}
