<?php

declare(strict_types=1);

namespace BAGArt\TelegramModuleEngine\Settings;

use DateTimeImmutable;

/**
 * Resolved setting value scoped to a bot (and optionally a chat).
 *
 * Engine-owned state, stored alongside activations. Resolved from:
 * platform defaults → bot override → chat override.
 */
final readonly class ResolvedSetting
{
    /**
     * @param  string  $botId  Bot tenant ID.
     * @param  string  $screenId  Settings screen identifier.
     * @param  string  $fieldId  Field identifier within the screen.
     * @param  mixed  $value  The resolved value (scalar, bool, or string).
     * @param  int|null  $chatId  Chat scope (null = bot-wide).
     * @param  string  $resolvedAt  ISO 8601 timestamp of last resolution.
     */
    public function __construct(
        public string $botId,
        public string $screenId,
        public string $fieldId,
        public mixed $value,
        public ?int $chatId = null,
        public string $resolvedAt = '',
    ) {
        if ($this->resolvedAt === '') {
            $this->resolvedAt = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
        }
    }
}
