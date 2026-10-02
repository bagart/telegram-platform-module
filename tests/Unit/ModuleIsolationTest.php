<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Architecture tests enforcing module-engine isolation boundaries.
 *
 * These tests are intentionally in the Unit suite (no Laravel bootstrap)
 * to keep them fast and free of host-app coupling.
 */
final class ModuleIsolationTest extends TestCase
{
    /**
     * T4 — Host code (app/) must not import module-specific classes.
     *
     * Module implementation classes (Antispam, Nettools, TTS, Summarizer,
     * Mafia, Proxy, STT) are internal to their packages. The host app may
     * import engine contracts (TelegramModuleEngine), lib contracts
     * (TelegramBot, TelegramBotMenu), and async-kernel wrappers — but NOT
     * module implementation details.
     */
    public function test_host_code_must_not_import_module_classes(): void
    {
        $blockedNamespaces = [
            'BAGArt\\TelegramBotAntispam\\',
            'BAGArt\\TelegramBotNettools\\',
            'BAGArt\\TelegramBotTts\\',
            'BAGArt\\TelegramBotSummarizer\\',
            'BAGArt\\TelegramBotStt\\',
            'BAGArt\\TgBotGameMafia\\',
            'BAGArt\\ProxyOperations\\',
        ];

        $appDir = dirname(__DIR__, 4) . '/../../app';
        $violations = $this->scanForImports($appDir, $blockedNamespaces);

        self::assertEmpty(
            $violations,
            'Host app/ must not import module implementation classes: ' . implode('; ', $violations),
        );
    }

    /**
     * T5 — Config DTOs under TelegramModuleEngine\Config\ must be final + readonly.
     *
     * Config DTOs are immutable value objects; they must not be extendable
     * or mutable.
     */
    public function test_config_dtos_are_final_and_readonly(): void
    {
        $configDir = dirname(__DIR__, 2) . '/src/Config';
        self::assertDirectoryExists($configDir);

        $files = glob($configDir . '/*.php');
        self::assertNotEmpty($files, 'Config DTO directory should contain at least one class');

        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $basename = basename($file, '.php');

            if (str_contains($content, 'abstract class') || str_contains($content, 'interface')) {
                continue;
            }

            if (! str_contains($content, 'final readonly class')) {
                $violations[] = "{$basename}: missing final readonly";
            }
        }

        self::assertEmpty(
            $violations,
            'All concrete Config DTOs must be final readonly: ' . implode('; ', $violations),
        );
    }

    /**
     * T6 — No raw env() calls in the engine namespace.
     *
     * Config values must be accessed via config() or injected DTOs,
     * never via env() directly.
     */
    public function test_no_env_calls_in_engine_namespace(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        self::assertDirectoryExists($srcDir);

        $violations = $this->scanForFunctionCalls($srcDir, 'env');

        self::assertEmpty(
            $violations,
            'Engine code must not call env() directly: ' . implode('; ', $violations),
        );
    }

    /**
     * Scan PHP files in a directory for use-statements matching blocked namespaces.
     *
     * @param  list<string>  $blockedNamespaces
     * @return list<string>  formatted violation messages
     */
    private function scanForImports(string $directory, array $blockedNamespaces): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($fileInfo->getPathname());

            foreach ($blockedNamespaces as $namespace) {
                $pattern = preg_quote($namespace, '/');
                if (preg_match('/use\s+' . $pattern . '\S+/', $content)) {
                    $shortPath = str_replace(dirname($directory) . '/', '', $fileInfo->getPathname());
                    $violations[] = "{$shortPath} imports {$namespace}*";
                }
            }
        }

        return $violations;
    }

    /**
     * Scan PHP files for direct calls to a given function.
     *
     * @return list<string>  formatted violation messages
     */
    private function scanForFunctionCalls(string $directory, string $functionName): array
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $pattern = '/(?<!\w)' . preg_quote($functionName, '/') . '\s*\(/';

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($fileInfo->getPathname());
            if (preg_match($pattern, $content)) {
                $shortPath = str_replace(dirname(dirname($directory)) . '/', '', $fileInfo->getPathname());
                $violations[] = "{$shortPath} calls {$functionName}()";
            }
        }

        return $violations;
    }
}
