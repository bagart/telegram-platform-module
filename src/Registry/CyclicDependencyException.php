<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Registry;

/**
 * Thrown when a dependency cycle is detected in ProviderSequence.
 * The message includes the cycle head for diagnostics.
 */
final class CyclicDependencyException extends \RuntimeException
{
    private function __construct(
        public readonly string $cycleHead,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function detected(string $cycleHead): self
    {
        return new self(
            $cycleHead,
            sprintf('Cyclic module dependency detected at module "%s"', $cycleHead),
        );
    }
}
