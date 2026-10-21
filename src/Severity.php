<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor;

enum Severity: string
{
    /** Hurting now, or about to: fix it first. */
    case Critical = 'critical';

    /** Costing something measurable; plan the fix. */
    case Warning = 'warning';

    /** Worth knowing; no action needed today. */
    case Notice = 'notice';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Warning => 2,
            self::Notice => 1,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
