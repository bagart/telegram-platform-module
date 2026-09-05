<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

/**
 * Machine-readable failure taxonomy for registry building (plan docs 32/41).
 * Diagnostics must render human-readable messages from these codes.
 */
enum RegistryErrorCode: string
{
    /** config entry is not a TgModuleConfig DTO */
    case InvalidEntry = 'INVALID_ENTRY';

    /** provider class-string does not exist */
    case ProviderMissing = 'PROVIDER_MISSING';

    /** provider class exists but does not implement TgModuleContract */
    case ProviderNotContract = 'PROVIDER_NOT_CONTRACT';

    /** TgModuleContract::descriptor() threw */
    case DescriptorFailed = 'DESCRIPTOR_FAILED';

    /** config key does not equal the descriptor id */
    case IdMismatch = 'ID_MISMATCH';

    /** declared command class-string does not exist */
    case CommandMissing = 'COMMAND_MISSING';

    /** declared command class exists but is not an Illuminate\Console\Command */
    case CommandNotCommand = 'COMMAND_NOT_COMMAND';

    /** two enabled modules declare Artisan commands with the same signature */
    case CommandSignatureCollision = 'COMMAND_SIGNATURE_COLLISION';
}
