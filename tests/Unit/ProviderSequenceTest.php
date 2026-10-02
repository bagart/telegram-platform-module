<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

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

    public function test_cyclic_dependency_throws_cyclic_dependency_exception(): void
    {
        // A → B → A: a simple 2-node cycle
        $registry = new EngineModuleRegistry([
            $this->definition('alpha', deps: ['beta']),
            $this->definition('beta', deps: ['alpha']),
        ]);

        $sequence = new ProviderSequence($registry);

        $this->expectException(\BAGArt\TelegramModuleEngine\Registry\CyclicDependencyException::class);
        $sequence->laravelProviders();
    }

    public function test_three_node_cycle_throws(): void
    {
        // A → B → C → A
        $registry = new EngineModuleRegistry([
            $this->definition('alpha', deps: ['beta']),
            $this->definition('beta', deps: ['gamma']),
            $this->definition('gamma', deps: ['alpha']),
        ]);

        $sequence = new ProviderSequence($registry);

        $this->expectException(\BAGArt\TelegramModuleEngine\Registry\CyclicDependencyException::class);
        $sequence->laravelProviders();
    }

    public function test_self_referencing_module_throws(): void
    {
        // A → A (self-cycle)
        $registry = new EngineModuleRegistry([
            $this->definition('alpha', deps: ['alpha']),
        ]);

        $sequence = new ProviderSequence($registry);

        $this->expectException(\BAGArt\TelegramModuleEngine\Registry\CyclicDependencyException::class);
        $sequence->laravelProviders();
    }

    public function test_cycle_exception_message_contains_cycle_head(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('alpha', deps: ['beta']),
            $this->definition('beta', deps: ['alpha']),
        ]);

        $sequence = new ProviderSequence($registry);

        try {
            $sequence->laravelProviders();
            self::fail('Expected CyclicDependencyException');
        } catch (\BAGArt\TelegramModuleEngine\Registry\CyclicDependencyException $e) {
            self::assertStringContainsString($e->cycleHead, $e->getMessage());
            self::assertSame('alpha', $e->cycleHead);
        }
    }

    public function test_cycle_does_not_infinite_loop(): void
    {
        // Guard against infinite loop: the visit() method uses GRAY/BLACK
        // coloring, so a cycle MUST throw rather than loop forever.
        $registry = new EngineModuleRegistry([
            $this->definition('a', deps: ['b', 'c']),
            $this->definition('b', deps: ['a']),
            $this->definition('c'),
        ]);

        $sequence = new ProviderSequence($registry);

        $this->expectException(\BAGArt\TelegramModuleEngine\Registry\CyclicDependencyException::class);
        $sequence->laravelProviders();
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
