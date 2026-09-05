<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Capabilities;

/**
 * Structured "blocked because" reason. Contains no secrets and no user input —
 * safe for CLI output, logs and the Admin diagnostics panel (16 §102).
 */
final readonly class BlockReason
{
    public function __construct(
        public BlockReasonCode $code,
        /** The affected (consumer) module id. */
        public string $moduleId,
        /** The module causing the block, or '' when no module is responsible (e.g. cycle). */
        public string $blockingModuleId = '',
        /** Short human-readable explanation. */
        public string $detail = '',
    ) {}

    public function message(): string
    {
        $reason = $this->detail !== ''
            ? $this->detail
            : ($this->blockingModuleId !== ''
                ? sprintf("module '%s' is not available", $this->blockingModuleId)
                : 'no provider is available');

        if ($this->detail !== '' && $this->blockingModuleId !== '') {
            $reason .= sprintf(" (module '%s')", $this->blockingModuleId);
        }

        return sprintf("Module '%s' is blocked because %s [%s]", $this->moduleId, $reason, $this->code->value);
    }

    /** @return array{code: string, module: string, blocking_module: string, detail: string, message: string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'module' => $this->moduleId,
            'blocking_module' => $this->blockingModuleId,
            'detail' => $this->detail,
            'message' => $this->message(),
        ];
    }
}
