<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

use Illuminate\Filesystem\Filesystem;

/**
 * JSON-file implementation of ConfigFileAccessContract.
 *
 * Reads/writes module settings as JSON files in storage/app/modules/{moduleId}/settings.json.
 * Each module gets its own file; the engine resolves the path.
 */
final readonly class JsonConfigFileAccess implements ConfigFileAccessContract
{
    public function __construct(
        private Filesystem $filesystem,
        private string $storagePath,
    ) {
    }

    public function read(string $moduleId): array
    {
        $path = $this->resolvePath($moduleId);

        if (! $this->filesystem->exists($path)) {
            return [];
        }

        $content = $this->filesystem->get($path);
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    public function write(string $moduleId, array $values): void
    {
        $existing = $this->read($moduleId);
        $merged = array_replace($existing, $values);

        $this->ensureDirectory($moduleId);
        $path = $this->resolvePath($moduleId);

        $this->filesystem->put(
            $path,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    public function get(string $moduleId, string $key): mixed
    {
        $all = $this->read($moduleId);

        return $all[$key] ?? null;
    }

    public function set(string $moduleId, string $key, mixed $value): void
    {
        $this->write($moduleId, [$key => $value]);
    }

    public function forget(string $moduleId, string $key): void
    {
        $all = $this->read($moduleId);
        unset($all[$key]);

        $this->ensureDirectory($moduleId);
        $path = $this->resolvePath($moduleId);

        $this->filesystem->put(
            $path,
            json_encode($all, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    public function path(string $moduleId): string
    {
        return $this->resolvePath($moduleId);
    }

    public function mtime(string $moduleId): int
    {
        $path = $this->resolvePath($moduleId);

        if (! $this->filesystem->exists($path)) {
            return 0;
        }

        return $this->filesystem->lastModified($path);
    }

    private function resolvePath(string $moduleId): string
    {
        return $this->storagePath . '/app/modules/' . $moduleId . '/settings.json';
    }

    private function ensureDirectory(string $moduleId): void
    {
        $dir = $this->storagePath . '/app/modules/' . $moduleId;

        if (! $this->filesystem->isDirectory($dir)) {
            $this->filesystem->makeDirectory($dir, 0755, true);
        }
    }
}
