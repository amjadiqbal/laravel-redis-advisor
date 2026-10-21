<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

use RuntimeException;

/**
 * Thrown when a check cannot see what it needs. The runner turns it into a
 * "skipped" result with the reason and the way to enable it, never a crash.
 */
final class CheckUnavailable extends RuntimeException
{
    public function __construct(string $reason, public readonly ?string $howToEnable = null)
    {
        parent::__construct($reason);
    }
}
