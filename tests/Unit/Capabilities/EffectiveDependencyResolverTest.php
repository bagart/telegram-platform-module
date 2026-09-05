<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit\Capabilities;

use BAGArt\TelegramBot\Modules\TgModuleCapability;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramModuleEngine\Capabilities\BlockReasonCode;
use BAGArt\TelegramModuleEngine\Capabilities\CapabilityDeclaration;
use BAGArt\TelegramModuleEngine\Capabilities\CapabilityRequirement;
use BAGArt\TelegramModuleEngine\Capabilities\EffectiveDependencyResolver;
use BAGArt\TelegramModuleEngine\Capabilities\ModuleCapabilityRegistry;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use PHPUnit\Framework\TestCase;

final class EffectiveDependencyResolverTest extends TestCase
{
    public function test_capability_availability_is_computed_from_activation_set_not_stored(): void
    {
        $resolver = $this->resolver(capabilityRequirements: [
            'mafia' => [new CapabilityRequirement('menu.ui')],
        ]);

        // bot A: menu active -> capability available, mafia active
        $botA = $resolver->resolve(['menu', 'mafia']);
        self::assertSame(['mafia', 'menu'], $botA->activeModuleIds);
        self::assertFalse($botA->isReduced('mafia'));

        // bot B: menu NOT in the activation set -> mafia blocked, nothing stored anywhere
        $botB = $resolver->resolve(['mafia']);
        self::assertSame(['mafia'], array_keys($botB->blocked));
        self::assertNotContains('mafia', $botB->activeModuleIds);
    }

    public function test_optional_capability_missing_yields_reduced_mode(): void
    {
        $resolver = $this->resolver(capabilityRequirements: [
            'mafia' => [
                new CapabilityRequirement('analytics.report', optional: true),
                new CapabilityRequirement('menu.ui'),
            ],
        ]);

        $graph = $resolver->resolve(['menu', 'mafia']);

        self::assertContains('mafia', $graph->activeModuleIds);
        self::assertTrue($graph->isReduced('mafia'));
        self::assertSame(['analytics.report'], $graph->reducedMode['mafia']);
        self::assertSame([], $graph->reasonsFor('mafia'));
    }

    public function test_disabled_dependency_blocks_dependent_with_reason(): void
    {
        // mafia active for the bot, but its required 'menu' module is not
        $graph = $this->resolver()->resolve(['mafia']);

        self::assertSame([], $graph->activeModuleIds);
        $reasons = $graph->reasonsFor('mafia');
        self::assertCount(1, $reasons);
        self::assertSame(BlockReasonCode::DependencyDisabled, $reasons[0]->code);
        self::assertSame('menu', $reasons[0]->blockingModuleId);
        self::assertStringContainsString("required module 'menu' is not active", $reasons[0]->message());

        $shape = $reasons[0]->toArray();
        self::assertSame('dependency_disabled', $shape['code']);
        self::assertSame('menu', $shape['blocking_module']);
    }

    public function test_missing_dependency_is_reported_differently_from_disabled(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('mafia', requiresModules: ['ghost' => '^2.0']),
        ]);
        $resolver = new EffectiveDependencyResolver($registry, ModuleCapabilityRegistry::fromDefinitions(
            $registry->all()
        ));

        $graph = $resolver->resolve(['mafia']);

        $reasons = $graph->reasonsFor('mafia');
        self::assertCount(1, $reasons);
        self::assertSame(BlockReasonCode::DependencyMissing, $reasons[0]->code);
        self::assertSame('ghost', $reasons[0]->blockingModuleId);
    }

    public function test_conflict_is_surfaced_with_human_readable_reason(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('scheduler-cron', conflictsWith: ['scheduler-queue']),
            $this->definition('scheduler-queue', conflictsWith: ['scheduler-cron']),
        ]);
        $resolver = new EffectiveDependencyResolver($registry, ModuleCapabilityRegistry::fromDefinitions(
            $registry->all()
        ));

        $graph = $resolver->resolve(['scheduler-cron', 'scheduler-queue']);

        self::assertSame([], $graph->activeModuleIds);
        $cronReasons = $graph->reasonsFor('scheduler-cron');
        self::assertCount(1, $cronReasons);
        self::assertSame(BlockReasonCode::DependencyConflict, $cronReasons[0]->code);
        self::assertSame('scheduler-queue', $cronReasons[0]->blockingModuleId);
        self::assertSame(
            "Module 'scheduler-cron' is blocked because conflicts with active module 'scheduler-queue' "
            .'(module \'scheduler-queue\') [dependency_conflict]',
            $cronReasons[0]->message(),
        );
    }

    public function test_descriptor_declared_required_capability_blocks_without_provider(): void
    {
        // 'analytics.ui' capability is provided only by analytics; analytics
        // is not in the activation set → consumer is blocked via the
        // descriptor declaration alone (no resolver-input requirements).
        $registry = new EngineModuleRegistry([
            $this->definition('analytics', capabilities: [TgModuleCapability::Ui]),
            $this->definition('report', requiresCapabilities: ['analytics.ui']),
        ]);

        $capabilities = ModuleCapabilityRegistry::fromDefinitions($registry->all());

        $graph = (new EffectiveDependencyResolver($registry, $capabilities))
            ->resolve(['report']);

        expect($graph->isActive('report'))->toBeFalse()
            ->and($graph->reasonsFor('report')[0]->code)->toBe(BlockReasonCode::CapabilityUnavailable);
    }

    public function test_required_capability_without_provider_blocks_module(): void
    {
        $resolver = $this->resolver(capabilityRequirements: [
            'mafia' => [new CapabilityRequirement('llm.text-generation')],
        ]);

        $graph = $resolver->resolve(['menu', 'mafia']);

        $reasons = $graph->reasonsFor('mafia');
        self::assertCount(1, $reasons);
        self::assertSame(BlockReasonCode::CapabilityUnavailable, $reasons[0]->code);
        self::assertSame('', $reasons[0]->blockingModuleId);
        self::assertStringContainsString("no active provider for capability 'llm.text-generation'", $reasons[0]->message());
    }

    public function test_static_validation_reports_cycles_and_exclusive_conflicts(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('a', requiresModules: ['b' => '*']),
            $this->definition('b', requiresModules: ['a' => '*']),
            $this->definition('x', capabilities: [TgModuleCapability::Cron]),
            $this->definition('y', capabilities: [TgModuleCapability::Cron]),
        ]);
        $capabilities = new ModuleCapabilityRegistry([
            'x' => [new CapabilityDeclaration('x', 'only.scheduler', TgModuleCapability::Cron, exclusive: true)],
            'y' => [new CapabilityDeclaration('y', 'only.scheduler', TgModuleCapability::Cron, exclusive: true)],
        ]);
        $resolver = new EffectiveDependencyResolver($registry, $capabilities);

        $reasons = $resolver->validateStatic();

        $codes = array_map(static fn ($r) => $r->code, $reasons);
        self::assertContains(BlockReasonCode::CycleDetected, $codes);
        self::assertContains(BlockReasonCode::ExclusiveCapabilityConflict, $codes);

        $cycles = $resolver->staticGraph()->cycles();
        self::assertSame([['a', 'b']], $cycles);
    }

    public function test_platform_disabled_module_is_never_effective(): void
    {
        $registry = new EngineModuleRegistry([
            $this->definition('ghost-off', enabled: false),
        ]);
        $resolver = new EffectiveDependencyResolver($registry, ModuleCapabilityRegistry::fromDefinitions(
            $registry->all()
        ));

        $graph = $resolver->resolve(['ghost-off']);

        self::assertSame([], $graph->activeModuleIds);
        self::assertSame([], $graph->blocked);
    }

    /**
     * Default fixture set: menu (provides menu.ui), mafia (requires menu).
     *
     * @param  array<string, list<CapabilityRequirement>>  $capabilityRequirements
     */
    private function resolver(array $capabilityRequirements = []): EffectiveDependencyResolver
    {
        $registry = new EngineModuleRegistry([
            $this->definition('menu', capabilities: [TgModuleCapability::Ui]),
            $this->definition('mafia', requiresModules: ['menu' => '^1.0']),
        ]);

        return new EffectiveDependencyResolver(
            $registry,
            ModuleCapabilityRegistry::fromDefinitions($registry->all()),
            $capabilityRequirements,
        );
    }

    /**
     * @param  array<string, string>  $requiresModules
     * @param  list<string>  $conflictsWith
     * @param  list<TgModuleCapability>  $capabilities
     * @param  list<string>  $requiresCapabilities
     */
    private function definition(
        string $id,
        bool $enabled = true,
        array $requiresModules = [],
        array $conflictsWith = [],
        array $capabilities = [],
        array $requiresCapabilities = [],
    ): TgModuleDefinition {
        return new TgModuleDefinition(
            configKey: $id,
            provider: 'BAGArt\\Fake\\'.ucfirst(str_replace('-', '', $id)),
            descriptor: new TgModuleDescriptor(
                id: $id,
                name: $id,
                version: '1.0.0',
                requiresModules: $requiresModules,
                conflictsWith: $conflictsWith,
                capabilities: $capabilities,
                requiresCapabilities: $requiresCapabilities,
            ),
            enabled: $enabled,
        );
    }
}
