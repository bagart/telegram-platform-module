<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Activation;

use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Diagnostics\EngineMetrics;
use BAGArt\TelegramModuleEngine\Events\BotModuleDisabled;
use BAGArt\TelegramModuleEngine\Events\BotModuleEnabled;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Lifecycle service for bot module activations (doc 40 §5-6: the centralized
 * state machine; nothing else mutates bindings). Idempotent enable/disable
 * with optimistic locking via expectedRevision (stale revision → structured
 * CONCURRENT_MODIFICATION, never silent last-write-wins) and pre-persistence
 * dependency validation returning structured blockers — business outcomes
 * are values, not exceptions. No Telegram transport, usable from CLI, admin
 * UI, API and tests.
 */
final class ModuleActivationService implements ModuleActivationWriterContract
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly EngineModuleRegistry $registry,
        private readonly ModuleActivationReader $activations,
        private readonly ?EngineMetrics $metrics = null,
    ) {
    }

    /**
     * Narrow writer entry used by EngineSettingsWriter: flips enabled state
     * without exposing the full enable/disable result surface. No-op when
     * $botId is null (platform-level scope has no activation row).
     */
    public function setEnabled(string $moduleId, ?string $botId, bool $enabled, ?string $actorId = null, ?string $actorType = null): void
    {
        if ($botId === null) {
            return;
        }

        if ($enabled) {
            $this->enable($botId, $moduleId, null, $actorId, $actorType);
        } else {
            $this->disable($botId, $moduleId, null, $actorId, $actorType);
        }
    }

    /**
     * Enable a module for a bot. Validates platform registration and required
     * dependencies BEFORE persisting (doc 38 §47: validate → persist); the
     * second identical call is a no-op without a revision bump.
     */
    public function enable(string $botId, string $moduleId, ?int $expectedRevision = null, ?string $actorId = null, ?string $actorType = null): ActivationResult
    {
        $definition = $this->registry->get($moduleId);
        if ($definition === null || ! $definition->enabled) {
            $this->metrics?->increment('activation_denied');

            return $this->notRegistered($botId, $moduleId);
        }

        return $this->connection->transaction(
            fn (): ActivationResult => $this->mutate(
                $botId,
                $moduleId,
                ModuleActivationReader::STATUS_ENABLED,
                $expectedRevision,
                $actorId,
                $actorType,
            ),
        );
    }

    /**
     * Disable a module for a bot. Never deletes the binding or any
     * configuration (doc 40 §6); repeated calls are no-ops.
     */
    public function disable(string $botId, string $moduleId, ?int $expectedRevision = null, ?string $actorId = null, ?string $actorType = null): ActivationResult
    {
        return $this->connection->transaction(
            fn (): ActivationResult => $this->mutate(
                $botId,
                $moduleId,
                ModuleActivationReader::STATUS_DISABLED,
                $expectedRevision,
                $actorId,
                $actorType,
            ),
        );
    }

    /** Shared write path for enable/disable with optimistic locking. */
    private function mutate(string $botId, string $moduleId, string $status, ?int $expectedRevision, ?string $actorId = null, ?string $actorType = null): ActivationResult
    {
        $row = $this->activations->rowFor($botId, $moduleId);
        $target = $status === ModuleActivationReader::STATUS_ENABLED
            ? ActivationOutcome::Enabled
            : ActivationOutcome::Disabled;

        // Dependencies must be validated before the idempotency guard so
        // that re-enabling an already-enabled module whose dependency was
        // concurrently disabled returns Blocked, not AlreadyEnabled.
        if ($status === ModuleActivationReader::STATUS_ENABLED) {
            $definition = $this->registry->get($moduleId);
            if ($definition !== null) {
                $blockers = $this->dependencyBlockers($botId, $definition);
                if ($blockers !== []) {
                    $this->metrics?->increment('activation_denied');

                    return new ActivationResult(
                        ActivationOutcome::Blocked,
                        $this->currentRevision($botId, $moduleId),
                        blockers: $blockers,
                    );
                }
            }
        }

        // Idempotency: an already-satisfied desired state is a no-op
        // with no revision bump, even when expectedRevision is supplied.
        if ($row !== null && $row->status === $status) {
            return new ActivationResult($target === ActivationOutcome::Enabled
                ? ActivationOutcome::AlreadyEnabled
                : ActivationOutcome::AlreadyDisabled, (int) $row->revision);
        }

        // Disable with no binding at all: for a default-disabled module the
        // absence of a row IS the disabled state (doc 40 §15 no-op); a
        // default-enabled module needs an explicit DISABLED row to override
        // the descriptor default, so the insert below applies.
        if ($row === null && $status === ModuleActivationReader::STATUS_DISABLED) {
            $definition = $this->registry->get($moduleId);
            if ($definition === null || ! $definition->descriptor->defaultEnabled) {
                return new ActivationResult(ActivationOutcome::AlreadyDisabled, 0);
            }
        }

        $currentRevision = $row === null ? 0 : (int) $row->revision;
        if ($expectedRevision !== null && $currentRevision !== $expectedRevision) {
            return new ActivationResult(
                ActivationOutcome::ConcurrentModification,
                $currentRevision,
                conflict: new ActivationConflict($expectedRevision, $currentRevision),
            );
        }

        if ($row === null) {
            try {
                $this->connection->table($this->activations->table())->insert([
                    'bot_id' => $botId,
                    'module_id' => $moduleId,
                    'status' => $status,
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }

                $row = $this->activations->rowFor($botId, $moduleId);
                if ($row === null) {
                    throw $e;
                }

                if ($row->status === $status) {
                    return new ActivationResult(
                        $target === ActivationOutcome::Enabled
                            ? ActivationOutcome::AlreadyEnabled
                            : ActivationOutcome::AlreadyDisabled,
                        (int) $row->revision,
                    );
                }

                $currentRevision = (int) $row->revision;
                if ($expectedRevision !== null && $currentRevision !== $expectedRevision) {
                    return new ActivationResult(
                        ActivationOutcome::ConcurrentModification,
                        $currentRevision,
                        conflict: new ActivationConflict($expectedRevision, $currentRevision),
                    );
                }
            }

            if ($row === null) {
                $this->dispatchEvent($status, $botId, $moduleId, 1, $actorId, $actorType);

                return new ActivationResult($target, 1);
            }
        }

        $affected = $this->connection->table($this->activations->table())
            ->where('bot_id', $botId)
            ->where('module_id', $moduleId)
            ->where('revision', $currentRevision)
            ->update([
                'status' => $status,
                'revision' => $currentRevision + 1,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            // Lost a real race between read and write: report, never overwrite.
            return $this->conflict($botId, $moduleId, $expectedRevision ?? $currentRevision);
        }

        $newRevision = $currentRevision + 1;
        $this->dispatchEvent($status, $botId, $moduleId, $newRevision, $actorId, $actorType);

        return new ActivationResult($target, $newRevision);
    }

    /**
     * Structured "blocked because" reasons for enabling $definition on $botId
     * (doc 40 §7-8: no silent auto-enable of dependencies).
     *
     * @return list<ActivationBlocker>
     */
    private function dependencyBlockers(string $botId, TgModuleDefinition $definition): array
    {
        $blockers = [];
        foreach (array_keys($definition->descriptor->requiresModules) as $dependencyId) {
            $dependency = $this->registry->get($dependencyId);
            if ($dependency === null || ! $dependency->enabled) {
                $blockers[] = new ActivationBlocker(
                    $dependencyId,
                    ActivationErrorCode::DependencyNotRegistered,
                    sprintf('Required module "%s" is not installed or is platform-disabled.', $dependencyId),
                );

                continue;
            }

            if ($this->activations->isEffectivelyEnabled($botId, $dependencyId)) {
                continue;
            }

            $blockers[] = new ActivationBlocker(
                $dependencyId,
                $this->activations->isExplicitlyDisabled($botId, $dependencyId)
                    ? ActivationErrorCode::DependencyDisabled
                    : ActivationErrorCode::DependencyNotEnabled,
                sprintf(
                    'Required module "%s" is not enabled for bot "%s".',
                    $dependencyId,
                    $botId,
                ),
            );
        }

        return $blockers;
    }

    private function notRegistered(string $botId, string $moduleId): ActivationResult
    {
        $this->metrics?->increment('activation_denied');

        return new ActivationResult(
            ActivationOutcome::Blocked,
            $this->currentRevision($botId, $moduleId),
            blockers: [new ActivationBlocker(
                $moduleId,
                ActivationErrorCode::ModuleNotRegistered,
                sprintf('Module "%s" is not registered or is platform-disabled.', $moduleId),
            )],
        );
    }

    private function conflict(string $botId, string $moduleId, int $expectedRevision): ActivationResult
    {
        $this->metrics?->increment('activation_conflict');
        $currentRevision = $this->currentRevision($botId, $moduleId);

        return new ActivationResult(
            ActivationOutcome::ConcurrentModification,
            $currentRevision,
            conflict: new ActivationConflict($expectedRevision, $currentRevision),
        );
    }

    private function currentRevision(string $botId, string $moduleId): int
    {
        $row = $this->activations->rowFor($botId, $moduleId);

        return $row === null ? 0 : (int) $row->revision;
    }

    private function dispatchEvent(string $status, string $botId, string $moduleId, int $revision, ?string $actorId = null, ?string $actorType = null): void
    {
        $event = $status === ModuleActivationReader::STATUS_ENABLED
            ? new BotModuleEnabled($botId, $moduleId, $revision, $actorId, $actorType)
            : new BotModuleDisabled($botId, $moduleId, $revision, $actorId, $actorType);

        event($event);
    }
}
