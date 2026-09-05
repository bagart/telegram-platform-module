<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Unit;

use BAGArt\TelegramModuleEngine\Config\TgModuleConfig;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Registry\ModuleRegistryBuilder;
use BAGArt\TelegramModuleEngine\Registry\RegistryErrorCode;
use BAGArt\TelegramModuleEngine\Registry\RegistryResult;
use BAGArt\TelegramModuleEngine\Registry\RegistryValidationException;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\Commands\AlphaCommand;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\Commands\BetaCommand;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\Commands\NotACommand;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\IdMismatchModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\NotAModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\TestModule;
use BAGArt\TelegramModuleEngine\Tests\Fixtures\ThrowingDescriptorModule;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryBuilderTest extends TestCase
{
    public function test_builds_registry_from_valid_config(): void
    {
        $result = $this->build([
            'strict' => false,
            'modules' => [
                'test' => new TgModuleConfig(enabled: true, provider: TestModule::class),
            ],
        ]);

        self::assertTrue($result->isValid());
        self::assertSame(1, $result->registry->count());

        $definition = $result->registry->get('test');
        self::assertNotNull($definition);
        self::assertTrue($definition->enabled);
        self::assertSame(TestModule::class, $definition->provider);
        self::assertSame('test', $definition->id());
        self::assertSame('Test Module', $definition->descriptor->name);
    }

    public function test_disabled_module_is_registered_but_not_enabled(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(enabled: false, provider: TestModule::class),
            ],
        ]);

        self::assertTrue($result->isValid());
        self::assertCount(1, $result->registry->all());
        self::assertCount(0, $result->registry->enabled());
    }

    public function test_id_mismatch_is_reported(): void
    {
        $result = $this->build([
            'modules' => [
                IdMismatchModule::CONFIG_KEY => new TgModuleConfig(enabled: true, provider: IdMismatchModule::class),
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertSame(RegistryErrorCode::IdMismatch, $result->errors[0]->code);
        self::assertSame(IdMismatchModule::CONFIG_KEY, $result->errors[0]->moduleKey);
        self::assertSame(0, $result->registry->count());
    }

    public function test_missing_provider_class_is_reported(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(enabled: true, provider: 'BAGArt\\Missing\\Nope'),
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertSame(RegistryErrorCode::ProviderMissing, $result->errors[0]->code);
    }

    public function test_non_contract_provider_is_reported(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(enabled: true, provider: NotAModule::class),
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertSame(RegistryErrorCode::ProviderNotContract, $result->errors[0]->code);
    }

    public function test_throwing_descriptor_is_isolated(): void
    {
        $result = $this->build([
            'modules' => [
                'throwing' => new TgModuleConfig(enabled: true, provider: ThrowingDescriptorModule::class),
                'test' => new TgModuleConfig(enabled: true, provider: TestModule::class),
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertSame(RegistryErrorCode::DescriptorFailed, $result->errors[0]->code);
        self::assertStringContainsString('descriptor exploded', $result->errors[0]->message);

        // degraded mode: the valid module is still registered
        self::assertSame(1, $result->registry->count());
        self::assertNotNull($result->registry->get('test'));
    }

    public function test_invalid_entry_type_is_reported(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => ['enabled' => true, 'provider' => TestModule::class],
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertSame(RegistryErrorCode::InvalidEntry, $result->errors[0]->code);
    }

    public function test_commands_are_copied_into_definition(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    commands: [AlphaCommand::class, BetaCommand::class],
                ),
            ],
        ]);

        self::assertTrue($result->isValid());
        self::assertSame(
            [AlphaCommand::class, BetaCommand::class],
            $result->registry->get('test')->commands,
        );
    }

    public function test_missing_command_class_is_reported(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    commands: ['BAGArt\\Missing\\NopeCommand'],
                ),
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertSame(RegistryErrorCode::CommandMissing, $result->errors[0]->code);
        self::assertSame('test', $result->errors[0]->moduleKey);
        self::assertSame(0, $result->registry->count());
    }

    public function test_non_command_class_is_reported(): void
    {
        $result = $this->build([
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    commands: [NotACommand::class],
                ),
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertSame(RegistryErrorCode::CommandNotCommand, $result->errors[0]->code);
        self::assertSame(0, $result->registry->count());
    }

    public function test_strict_mode_throws_on_invalid_command(): void
    {
        $this->expectException(RegistryValidationException::class);

        $this->build([
            'strict' => true,
            'modules' => [
                'test' => new TgModuleConfig(
                    enabled: true,
                    provider: TestModule::class,
                    commands: [NotACommand::class],
                ),
            ],
        ]);
    }

    public function test_config_dto_is_immutable(): void
    {
        $config = new TgModuleConfig(enabled: true, provider: TestModule::class);

        $this->expectException(\Error::class);
        $config->enabled = false;
    }

    public function test_strict_mode_throws_on_first_config_error(): void
    {
        $this->expectException(RegistryValidationException::class);

        $this->build([
            'strict' => true,
            'modules' => [
                'test' => new TgModuleConfig(enabled: true, provider: NotAModule::class),
            ],
        ]);
    }

    public function test_strict_mode_passes_on_valid_config(): void
    {
        $result = $this->build([
            'strict' => true,
            'modules' => [
                'test' => new TgModuleConfig(enabled: true, provider: TestModule::class),
            ],
        ]);

        self::assertTrue($result->isValid());
        self::assertInstanceOf(EngineModuleRegistry::class, $result->registry);
    }

    public function test_empty_config_yields_empty_registry(): void
    {
        $result = $this->build([]);

        self::assertTrue($result->isValid());
        self::assertSame(0, $result->registry->count());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function build(array $config): RegistryResult
    {
        return (new ModuleRegistryBuilder($config))->build();
    }
}
