<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use PHPUnit\Framework\TestCase;

final class ModuleSourcePathResolutionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/tg-mod-srcpath-'.uniqid('', true);
        mkdir($this->tmpDir.'/routes', 0755, true);
        file_put_contents($this->tmpDir.'/routes/web.php', '<?php // route file');
        mkdir($this->tmpDir.'/resources/js/pages', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_descriptor_source_path_defaults_to_null(): void
    {
        $descriptor = new TgModuleDescriptor(
            id: 'test',
            name: 'Test',
            version: '1.0.0',
        );

        self::assertNull($descriptor->sourcePath);
    }

    public function test_descriptor_accepts_source_path(): void
    {
        $descriptor = new TgModuleDescriptor(
            id: 'test',
            name: 'Test',
            version: '1.0.0',
            sourcePath: '/some/path',
        );

        self::assertSame('/some/path', $descriptor->sourcePath);
    }

    public function test_config_source_path_defaults_to_null(): void
    {
        $config = new TgModuleConfig(enabled: true, provider: TestModule::class);

        self::assertNull($config->sourcePath);
    }

    public function test_relative_http_routes_resolved_against_source_path(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    httpRoutes: ['routes/web.php'],
                    sourcePath: $this->tmpDir,
                ),
            ],
        ]);

        self::assertTrue($result->isValid());
        $definition = $result->registry->get('test');
        self::assertNotNull($definition);
        self::assertSame([$this->tmpDir.'/routes/web.php'], $definition->httpRoutes);
    }

    public function test_absolute_http_routes_kept_as_is(): void
    {
        $absolutePath = $this->tmpDir.'/routes/web.php';
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    httpRoutes: [$absolutePath],
                    sourcePath: $this->tmpDir,
                ),
            ],
        ]);

        self::assertTrue($result->isValid());
        $definition = $result->registry->get('test');
        self::assertNotNull($definition);
        self::assertSame([$absolutePath], $definition->httpRoutes);
    }

    public function test_relative_frontend_pages_resolved_against_source_path(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    frontendPages: ['resources/js/pages'],
                    sourcePath: $this->tmpDir,
                ),
            ],
        ]);

        self::assertTrue($result->isValid());
        $definition = $result->registry->get('test');
        self::assertNotNull($definition);
        self::assertSame([$this->tmpDir.'/resources/js/pages'], $definition->frontendPages);
    }

    public function test_null_source_path_keeps_paths_unchanged(): void
    {
        $absolutePath = $this->tmpDir.'/routes/web.php';
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    httpRoutes: [$absolutePath],
                ),
            ],
        ]);

        self::assertTrue($result->isValid());
        $definition = $result->registry->get('test');
        self::assertNotNull($definition);
        self::assertSame([$absolutePath], $definition->httpRoutes);
    }

    public function test_source_path_stored_in_definition(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    sourcePath: $this->tmpDir,
                ),
            ],
        ]);

        self::assertTrue($result->isValid());
        $definition = $result->registry->get('test');
        self::assertNotNull($definition);
        self::assertSame($this->tmpDir, $definition->sourcePath);
    }

    public function test_validation_uses_resolved_paths(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    httpRoutes: ['routes/web.php'],
                    sourcePath: $this->tmpDir,
                ),
            ],
        ]);

        self::assertTrue($result->isValid(), 'Validation should pass with resolved paths');
    }

    public function test_validation_fails_for_nonexistent_resolved_path(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    httpRoutes: ['nonexistent/file.php'],
                    sourcePath: $this->tmpDir,
                ),
            ],
        ]);

        self::assertFalse($result->isValid());
    }

    public function test_mixed_relative_and_absolute_paths(): void
    {
        $absolutePath = $this->tmpDir.'/routes/web.php';
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    httpRoutes: [$absolutePath, 'routes/web.php'],
                    sourcePath: $this->tmpDir,
                ),
            ],
        ]);

        self::assertTrue($result->isValid());
        $definition = $result->registry->get('test');
        self::assertNotNull($definition);
        self::assertSame([$absolutePath, $this->tmpDir.'/routes/web.php'], $definition->httpRoutes);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function build(array $config): \BAGArt\TelegramModuleEngine\Registry\RegistryResult
    {
        return (new ModuleRegistryBuilder($config))->build();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }
}
