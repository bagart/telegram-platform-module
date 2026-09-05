<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

/**
 * Thrown by the registry builder in strict mode when the platform module
 * config is invalid (fail-fast / REQUIRED_PLATFORM semantics). Never leaks
 * provider internals: carries only structured registry errors.
 */
final class RegistryValidationException extends \RuntimeException
{
    /** @param  list<RegistryError>  $errors */
    public function __construct(
        public readonly array $errors,
    ) {
        $first = $errors[0];
        parent::__construct(sprintf(
            'module registry validation failed (%d error(s)); first: [%s] %s: %s',
            count($errors),
            $first->code->value,
            $first->moduleKey,
            $first->message,
        ));
    }
}
