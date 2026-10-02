<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

/**
 * One registry validation failure. Contains no secrets and no user input —
 * safe for CLI output and logs.
 */
final readonly class RegistryError
{
    public function __construct(
        public RegistryErrorCode $code,
        public string $moduleKey,
        public string $message,
    ) {
    }

    /** @return array{code: string, module: string, message: string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'module' => $this->moduleKey,
            'message' => $this->message,
        ];
    }
}
