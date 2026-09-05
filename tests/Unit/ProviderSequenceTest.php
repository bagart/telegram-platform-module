<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Registry\ProviderSequence;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use PHPUnit\Framework\TestCase;

final class ProviderSequenceTest extends TestCase
{
    public function testDependencyProviderIsOrderedBeforeDependent(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('dependent', deps: ['base']),
            $this->definition('base'),
        ]);

        $sequence = (new ProviderSequence($registry))->laravelProviders();

        self::assertSame(['prov:base', 'prov:dependent'], $sequence);
    }

    public function testDependencyChainIsResolvedTransitively(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('c', deps: ['b']),
            $this->definition('b', deps: ['a']),
            $this->definition('a'),
        ]);

        $sequence = (new ProviderSequence($registry))->laravelProviders();

        self::assertSame(['prov:a', 'prov:b', 'prov:c'], $sequence);
    }

    public function testDisabledAndUnknownDependenciesDoNotContributeProviders(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('dependent', deps: ['disabled-dep', 'unknown-dep']),
            $this->definition('disabled-dep', enabled: false),
        ]);

        $sequence = (new ProviderSequence($registry))->laravelProviders();

        self::assertSame(['prov:dependent'], $sequence);
    }

    public function testModulesWithoutLaravelProviderAreSkipped(): void
    {
        $registry = new EngineModuleRegistry([
            $this->contractOnlyDefinition('contract-only'),
        ]);

        self::assertSame([], (new ProviderSequence($registry))->laravelProviders());
    }

    private function definition(string $id, array $deps = [], bool $enabled = true): TgModuleDefinition
    {
        return new TgModuleDefinition(
            configKey: $id,
            provider: 'Module\\'.$id,
            descriptor: new TgModuleDescriptor(
                id: $id,
                name: $id,
                version: '1.0.0',
                requiresModules: array_fill_keys($deps, '*'),
            ),
            enabled: $enabled,
            laravelProvider: 'prov:'.$id,
        );
    }

    private function contractOnlyDefinition(string $id): TgModuleDefinition
    {
        return new TgModuleDefinition(
            configKey: $id,
            provider: 'Module\\'.$id,
            descriptor: new TgModuleDescriptor(id: $id, name: $id, version: '1.0.0'),
            enabled: true,
        );
    }
}
