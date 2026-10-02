<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit\Settings;

use BAGArt\TelegramModuleEngine\Settings\JsonConfigFileAccess;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class JsonConfigFileAccessTest extends TestCase
{
    private string $tmpDir;
    private JsonConfigFileAccess $access;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/json_config_access_test_'.uniqid('', true);
        $this->filesystem = new Filesystem();
        $this->access = new JsonConfigFileAccess($this->filesystem, $this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->filesystem->deleteDirectory($this->tmpDir);
        }
    }

    public function test_read_returns_empty_array_when_file_does_not_exist(): void
    {
        self::assertSame([], $this->access->read('nonexistent-module'));
    }

    public function test_read_returns_decoded_json_for_valid_file(): void
    {
        $path = $this->tmpDir.'/app/modules/test-module/settings.json';
        $this->filesystem->makeDirectory(dirname($path), 0755, true);
        $this->filesystem->put($path, json_encode(['key' => 'value', 'count' => 42]));

        $result = $this->access->read('test-module');

        self::assertSame(['key' => 'value', 'count' => 42], $result);
    }

    public function test_read_returns_empty_array_for_non_array_json(): void
    {
        $path = $this->tmpDir.'/app/modules/test-module/settings.json';
        $this->filesystem->makeDirectory(dirname($path), 0755, true);
        $this->filesystem->put($path, '"just a string"');

        $result = $this->access->read('test-module');

        self::assertSame([], $result);
    }

    public function test_read_returns_empty_array_for_empty_json_object(): void
    {
        $path = $this->tmpDir.'/app/modules/test-module/settings.json';
        $this->filesystem->makeDirectory(dirname($path), 0755, true);
        $this->filesystem->put($path, '{}');

        $result = $this->access->read('test-module');

        self::assertSame([], $result);
    }

    public function test_read_throws_on_malformed_json(): void
    {
        $path = $this->tmpDir.'/app/modules/test-module/settings.json';
        $this->filesystem->makeDirectory(dirname($path), 0755, true);
        $this->filesystem->put($path, '{broken json');

        $this->expectException(\JsonException::class);
        $this->access->read('test-module');
    }

    public function test_write_creates_file_and_directory_structure(): void
    {
        $this->access->write('my-module', ['setting_a' => 1]);

        $path = $this->tmpDir.'/app/modules/my-module/settings.json';
        self::assertFileExists($path);

        $decoded = json_decode(file_get_contents($path), true);
        self::assertSame(['setting_a' => 1], $decoded);
    }

    public function test_write_merges_with_existing_values(): void
    {
        $path = $this->tmpDir.'/app/modules/test-module/settings.json';
        $this->filesystem->makeDirectory(dirname($path), 0755, true);
        $this->filesystem->put($path, json_encode(['existing' => 'preserved', 'to_overwrite' => 'old']));

        $this->access->write('test-module', ['to_overwrite' => 'new', 'added' => true]);

        $result = $this->access->read('test-module');
        self::assertSame('preserved', $result['existing']);
        self::assertSame('new', $result['to_overwrite']);
        self::assertTrue($result['added']);
    }

    public function test_get_returns_specific_key(): void
    {
        $path = $this->tmpDir.'/app/modules/test-module/settings.json';
        $this->filesystem->makeDirectory(dirname($path), 0755, true);
        $this->filesystem->put($path, json_encode(['a' => 1, 'b' => 2]));

        self::assertSame(1, $this->access->get('test-module', 'a'));
        self::assertSame(2, $this->access->get('test-module', 'b'));
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $path = $this->tmpDir.'/app/modules/test-module/settings.json';
        $this->filesystem->makeDirectory(dirname($path), 0755, true);
        $this->filesystem->put($path, json_encode(['a' => 1]));

        self::assertNull($this->access->get('test-module', 'nonexistent'));
    }

    public function test_get_returns_null_for_missing_file(): void
    {
        self::assertNull($this->access->get('nonexistent', 'key'));
    }

    public function test_set_writes_single_key(): void
    {
        $this->access->set('test-module', 'my_key', 'my_value');

        self::assertSame('my_value', $this->access->get('test-module', 'my_key'));
    }

    public function test_forget_removes_key(): void
    {
        $this->access->write('test-module', ['a' => 1, 'b' => 2]);

        $this->access->forget('test-module', 'a');

        $result = $this->access->read('test-module');
        self::assertArrayNotHasKey('a', $result);
        self::assertSame(2, $result['b']);
    }

    public function test_forget_on_missing_file_creates_empty_file(): void
    {
        $this->access->forget('nonexistent', 'key');

        // forget() reads → empty, removes key, writes the result → creates file with {}
        $path = $this->tmpDir.'/app/modules/nonexistent/settings.json';
        self::assertFileExists($path);
        self::assertSame([], json_decode(file_get_contents($path), true));
    }

    public function test_path_returns_correct_resolved_path(): void
    {
        $expected = $this->tmpDir.'/app/modules/alpha/settings.json';
        self::assertSame($expected, $this->access->path('alpha'));
    }

    public function test_mtime_returns_zero_for_missing_file(): void
    {
        self::assertSame(0, $this->access->mtime('nonexistent'));
    }

    public function test_mtime_returns_file_modification_time(): void
    {
        $path = $this->tmpDir.'/app/modules/test-module/settings.json';
        $this->filesystem->makeDirectory(dirname($path), 0755, true);
        $this->filesystem->put($path, '{}');

        $mtime = $this->access->mtime('test-module');

        self::assertGreaterThan(0, $mtime);
    }

    public function test_read_json_array_with_unicode(): void
    {
        $path = $this->tmpDir.'/app/modules/i18n-module/settings.json';
        $this->filesystem->makeDirectory(dirname($path), 0755, true);
        $this->filesystem->put($path, json_encode(['label' => 'Привет мир', 'emoji' => '🎉'], JSON_UNESCAPED_UNICODE));

        $result = $this->access->read('i18n-module');

        self::assertSame('Привет мир', $result['label']);
        self::assertSame('🎉', $result['emoji']);
    }
}
