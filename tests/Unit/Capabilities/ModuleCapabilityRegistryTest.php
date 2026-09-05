<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit\Capabilities;

use BAGArt\TelegramBot\Modules\TgModuleCapability;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramModuleEngine\Capabilities\BlockReasonCode;
use BAGArt\TelegramModuleEngine\Capabilities\CapabilityDeclaration;
use BAGArt\TelegramModuleEngine\Capabilities\ModuleCapabilityRegistry;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use PHPUnit\Framework\TestCase;

final class ModuleCapabilityRegistryTest extends TestCase
{
    public function test_derives_capability_ids_from_descriptors(): void
    {
        $registry = ModuleCapabilityRegistry::fromDefinitions($this->definitions());

        $menu = $registry->forModule('menu');
        self::assertCount(2, $menu);
        self::assertSame('menu.command', $menu[0]->capabilityId);
        self::assertSame(TgModuleCapability::Command, $menu[0]->kind);
        self::assertSame('menu.ui', $menu[1]->capabilityId);

        self::assertSame(['menu'], $registry->providersOf('menu.command'));
        self::assertTrue($registry->provides('menu', 'menu.ui'));
        self::assertFalse($registry->provides('mafia', 'menu.ui'));
    }

    public function test_two_modules_claiming_one_exclusive_capability_is_reported(): void
    {
        $registry = new ModuleCapabilityRegistry([
            'scheduler-a' => [
                new CapabilityDeclaration('scheduler-a', 'platform.scheduler', TgModuleCapability::Cron, exclusive: true),
            ],
            'scheduler-b' => [
                new CapabilityDeclaration('scheduler-b', 'platform.scheduler', TgModuleCapability::Cron, exclusive: true),
            ],
        ]);

        $conflicts = $registry->exclusiveConflicts();

        self::assertCount(2, $conflicts);
        self::assertContains('scheduler-a', ['scheduler-a', 'scheduler-b']);
        self::assertSame(BlockReasonCode::ExclusiveCapabilityConflict, $conflicts[0]->code);
        self::assertSame('scheduler-a', $conflicts[0]->moduleId);
        self::assertSame('scheduler-b', $conflicts[0]->blockingModuleId);
        self::assertSame('platform.scheduler', $this->capabilityIdFromDetail($conflicts[0]->detail));

        // mirrored reason: each claimant names the other
        self::assertSame('scheduler-b', $conflicts[1]->moduleId);
        self::assertSame('scheduler-a', $conflicts[1]->blockingModuleId);
    }

    public function test_single_exclusive_claim_and_non_exclusive_sharing_are_clean(): void
    {
        $registry = new ModuleCapabilityRegistry([
            'a' => [
                new CapabilityDeclaration('a', 'shared.kind', TgModuleCapability::Command),
                new CapabilityDeclaration('a', 'only.scheduler', TgModuleCapability::Cron, exclusive: true),
            ],
            'b' => [
                new CapabilityDeclaration('b', 'shared.kind', TgModuleCapability::Command),
            ],
        ]);

        self::assertSame([], $registry->exclusiveConflicts());
    }

    /**
     * @return array<string, TgModuleDefinition>
     */
    private function definitions(): array
    {
        return [
            'menu' => new TgModuleDefinition(
                configKey: 'menu',
                provider: 'BAGArt\\Fake\\MenuModule',
                descriptor: new TgModuleDescriptor(
                    id: 'menu',
                    name: 'Menu',
                    version: '1.0.0',
                    capabilities: [TgModuleCapability::Command, TgModuleCapability::Ui],
                ),
                enabled: true,
            ),
            'mafia' => new TgModuleDefinition(
                configKey: 'mafia',
                provider: 'BAGArt\\Fake\\MafiaModule',
                descriptor: new TgModuleDescriptor(id: 'mafia', name: 'Mafia', version: '1.0.0'),
                enabled: true,
            ),
        ];
    }

    private function capabilityIdFromDetail(string $detail): string
    {
        self::assertSame(1, preg_match("/capability '([^']+)'/", $detail, $matches));

        return $matches[1];
    }
}
