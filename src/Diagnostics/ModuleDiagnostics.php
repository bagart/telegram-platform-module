<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Diagnostics;

use BAGArt\TelegramModuleEngine\Registry\RegistryError;
use BAGArt\TelegramModuleEngine\Registry\RegistryResult;
use BAGArt\TelegramModuleEngine\Routing\RouteResolver;
use BAGArt\TelegramModuleEngine\Tenancy\BotContext;
use Illuminate\Database\QueryException;

/**
 * Pure diagnostics presenter (doc 07 phase 6): builds a plain, machine
 * readable data array describing the module registry, its validation errors
 * and — for a given bot — per-module activation state and routing entry
 * counts. No I/O of its own: activation/routing probes and the routing
 * resolver are injected; database failures (missing tables etc.) degrade to
 * the literal state "unavailable" instead of crashing the caller.
 *
 * Output shape (all keys always present unless noted):
 * - "modules": list of {id, version, policy, activation?, routes?};
 *   "activation"/"routes" rows only exist when a bot id is given;
 *   activation is "enabled"|"disabled"|"unavailable", routes is int|"unavailable"
 * - "errors": list of RegistryError::toArray()
 * - "bot": {id, activation_available, routing_available} — only with a bot id
 * - "metrics": EngineMetrics summary — only when non-empty
 */
final readonly class ModuleDiagnostics
{
    public const string STATE_UNAVAILABLE = 'unavailable';

    public function __construct(
        private RegistryResult $result,
        private ?ActivationStateProbe $activationProbe = null,
        private ?RouteResolver $routeResolver = null,
        private ?EngineMetrics $metrics = null,
    ) {
    }

    /**
     * Build the full diagnostics payload.
     *
     * @return array{
     *     modules: list<array{
     *         id: string, version: string, policy: string, commands: int,
     *         activation?: string, routes?: int|string,
     *     }>,
     *     errors: list<array{code: string, module: string, message: string}>,
     *     bot?: array{id: string, activation_available: bool, routing_available: bool},
     *     metrics?: array<string, int>,
     * }
     */
    public function toArray(?string $botId = null): array
    {
        $activationAvailable = true;
        $routingAvailable = true;
        $routingCounts = [];

        if ($botId !== null) {
            [$routingCounts, $routingAvailable] = $this->resolveRoutingCounts($botId);
        }

        $modules = [];
        foreach ($this->result->registry->all() as $definition) {
            $row = [
                'id' => $definition->id(),
                'version' => $definition->descriptor->version,
                'policy' => $definition->enabled ? 'enabled' : 'disabled',
                'commands' => count($definition->commands),
            ];

            if ($botId !== null) {
                $row['activation'] = $this->activationState($botId, $definition->id(), $activationAvailable);
                $row['routes'] = $routingCounts[$definition->id()] ?? ($routingAvailable ? 0 : self::STATE_UNAVAILABLE);
            }

            $modules[] = $row;
        }

        $payload = [
            'modules' => $modules,
            'errors' => array_map(
                static fn (RegistryError $error): array => $error->toArray(),
                $this->result->errors,
            ),
        ];

        if ($botId !== null) {
            $payload['bot'] = [
                'id' => $botId,
                'activation_available' => $activationAvailable,
                'routing_available' => $routingAvailable,
            ];
        }

        $metrics = $this->metrics?->summary() ?? [];
        if ($metrics !== []) {
            $payload['metrics'] = $metrics;
        }

        return $payload;
    }

    /**
     * @return array{0: array<string, int>, 1: bool} per-module route entry
     *                                               counts and whether the routing source was reachable
     */
    private function resolveRoutingCounts(string $botId): array
    {
        if ($this->routeResolver === null) {
            return [[], false];
        }

        try {
            $table = $this->routeResolver->resolve(BotContext::forBot($botId));
        } catch (QueryException) {
            return [[], false];
        }

        $counts = [];
        foreach ($table->entries as $entry) {
            $counts[$entry->moduleId] = ($counts[$entry->moduleId] ?? 0) + 1;
        }

        return [$counts, true];
    }

    private function activationState(string $botId, string $moduleId, bool &$available): string
    {
        if ($this->activationProbe === null || ! $available) {
            return self::STATE_UNAVAILABLE;
        }

        try {
            return $this->activationProbe->isEffectivelyEnabled($botId, $moduleId)
                ? 'enabled'
                : 'disabled';
        } catch (QueryException) {
            $available = false;

            return self::STATE_UNAVAILABLE;
        }
    }
}
