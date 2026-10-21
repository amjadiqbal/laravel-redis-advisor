<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

/**
 * What a Laravel app keeps in Redis.
 */
enum Role: string
{
    case Cache = 'cache';
    case Queue = 'queue';
    case Session = 'session';
    case Horizon = 'horizon';

    /** Whether losing this data loses work or logs people out, not just a recomputation. */
    public function mustSurvive(): bool
    {
        return $this !== self::Cache;
    }
}
