<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Console;

use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Diagnostics\ActivationStateProbe;
use BAGArt\TelegramModuleEngine\Diagnostics\EngineMetrics;
use BAGArt\TelegramModuleEngine\Diagnostics\ModuleDiagnostics;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use BAGArt\TelegramModuleEngine\Registry\RegistryResult;
use BAGArt\TelegramModuleEngine\Registry\RegistryValidationException;
use BAGArt\TelegramModuleEngine\Routing\RouteResolver;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnostics CLI (doc 07 phase 6): registry summary, validation errors with
 * codes/module keys and — with --bot — per-module activation state and
 * routing entry counts. Degrades gracefully when database tables are missing
 * (states reported as "unavailable"). Exit code 0 even with degraded (non
 * strict) errors; strict registry validation failure exits 5.
 */
final class TgModulesDiagnoseCommand extends Command
{
    /** Exit code for strict-mode registry validation failure (roadmap phase 6). */
    public const int STRICT_VALIDATION_FAILURE = 5;

    protected $signature
        = 'tg:modules:diagnose
            {--bot= : Bot id to resolve activation state and routing for}
            {--format=text : Output format: text or json}';

    protected $description = 'Diagnose the Telegram Module Engine registry, activations and routing';

    public function handle(): int
    {
        try {
            $result = $this->laravel->make(ModuleRegistryBuilder::class)->build();
        } catch (RegistryValidationException $exception) {
            if ($this->option('format') === 'json') {
                $this->output->writeln(json_encode([
                    'modules' => [],
                    'errors' => [
                        [
                            'code' => 'strict_validation_failed',
                            'module' => '*',
                            'message' => $exception->getMessage(),
                        ],
                    ],
                ], JSON_THROW_ON_ERROR));

                return self::STRICT_VALIDATION_FAILURE;
            }

            $this->error(sprintf('[strict] registry: %s', $exception->getMessage()));

            return self::STRICT_VALIDATION_FAILURE;
        }

        $botId = $this->option('bot');
        $botId = is_string($botId) && $botId !== '' ? $botId : null;

        $diagnostics = new ModuleDiagnostics(
            $result,
            $this->activationProbe(),
            $this->laravel->make(RouteResolver::class),
            $this->laravel->bound(EngineMetrics::class) ? $this->laravel->make(EngineMetrics::class) : null,
        );

        if ($this->option('format') === 'json') {
            $this->output->writeln(json_encode($diagnostics->toArray($botId), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderText($result, $diagnostics->toArray($botId));

        return self::SUCCESS;
    }

    /**
     * Activation probe backed by the shared reader; null when the reader
     * itself cannot be resolved (no bot section is emitted in that case).
     */
    private function activationProbe(): ?ActivationStateProbe
    {
        try {
            $reader = $this->laravel->make(ModuleActivationReader::class);
        } catch (Throwable) {
            return null;
        }

        return new class($reader) implements ActivationStateProbe
        {
            public function __construct(
                private readonly ModuleActivationReader $reader,
            ) {}

            public function isEffectivelyEnabled(string $botId, string $moduleId): bool
            {
                return $this->reader->isEffectivelyEnabled($botId, $moduleId);
            }
        };
    }

    /**
     * @param  array<string, mixed>  $payload  ModuleDiagnostics::toArray() shape
     */
    private function renderText(RegistryResult $result, array $payload): void
    {
        foreach ($result->errors as $error) {
            $this->warn(sprintf('[%s] %s: %s', $error->code->value, $error->moduleKey, $error->message));
        }

        $this->line(sprintf(
            'Registry: %d module(s), %d validation error(s).',
            $result->registry->count(),
            count($result->errors),
        ));

        if ($result->registry->count() === 0) {
            $this->info('No modules configured (config/tg_modules.php).');

            return;
        }

        $rows = array_map(
            static fn (array $module): array => [
                $module['id'],
                $module['version'],
                $module['policy'],
                (string) $module['commands'],
                $module['activation'] ?? '-',
                isset($module['routes']) ? (string) $module['routes'] : '-',
            ],
            $payload['modules'],
        );

        if (isset($payload['bot'])) {
            $headers = ['Module', 'Version', 'Policy', 'Commands', 'Activation', 'Routes'];
        } else {
            $headers = ['Module', 'Version', 'Policy', 'Commands'];
            $rows = array_map(
                static fn (array $row): array => array_slice($row, 0, 4),
                $rows,
            );
        }

        $this->table($headers, $rows);

        if (isset($payload['bot'])) {
            $bot = $payload['bot'];
            $this->line(sprintf(
                'Bot %s: activation %s, routing %s.',
                $bot['id'],
                $bot['activation_available'] ? 'available' : 'unavailable',
                $bot['routing_available'] ? 'available' : 'unavailable',
            ));
        }
    }
}
