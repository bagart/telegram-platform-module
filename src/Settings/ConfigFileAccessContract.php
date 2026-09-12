<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

/**
 * Contract for reading and writing module config files (JSON/YML).
 *
 * Platform and module settings live in config files, NOT in DB.
 * This contract provides a uniform interface for admin interfaces
 * (Management web, Menu Telegram) to read and edit these files.
 *
 * Each module owns its own config file. The engine provides the
 * path resolution; modules declare their config schema via
 * SettingsScreenContribution.
 */
interface ConfigFileAccessContract
{
    /**
     * Read all settings from a module's config file.
     *
     * @return array<string, mixed>  key => value pairs
     */
    public function read(string $moduleId): array;

    /**
     * Write settings to a module's config file.
     *
     * Merges with existing values (partial update). Keys not present
     * in $values are preserved. The file format (JSON/YML) is determined
     * by the module's config file extension.
     *
     * @param  array<string, mixed>  $values  key => value pairs to write
     */
    public function write(string $moduleId, array $values): void;

    /**
     * Read a single setting from a module's config file.
     *
     * @return mixed|null  value or null if key not found
     */
    public function get(string $moduleId, string $key): mixed;

    /**
     * Write a single setting to a module's config file.
     */
    public function set(string $moduleId, string $key, mixed $value): void;

    /**
     * Delete a setting from a module's config file (revert to default).
     */
    public function forget(string $moduleId, string $key): void;

    /**
     * Get the absolute path to a module's config file.
     */
    public function path(string $moduleId): string;

    /**
     * Get the file mtime for optimistic concurrency.
     */
    public function mtime(string $moduleId): int;
}
