<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Tests\Fixtures;

/**
 * Deliberately NOT a TgModuleContract implementation, exercising
 * PROVIDER_NOT_CONTRACT validation.
 */
final class NotAModule {}
