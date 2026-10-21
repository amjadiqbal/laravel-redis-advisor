<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

/**
 * One thing the app stores in Redis, where, and under which raw key prefix.
 */
final readonly class Usage
{
    /**
     * @param  string  $name  The store, queue connection or "session" / "horizon".
     * @param  string  $connection  The Redis connection name it uses.
     * @param  string  $keyPrefix  The raw prefix of its keys, as they appear in SCAN.
     * @param  list<string>  $queues  For queues: the queue names (including Horizon supervisors').
     */
    public function __construct(
        public Role $role,
        public string $name,
        public string $connection,
        public string $keyPrefix,
        public array $queues = [],
    ) {}

    /** "cache store 'redis'", "queue connection 'redis'", "sessions", "Horizon". */
    public function label(): string
    {
        return match ($this->role) {
            Role::Cache => "cache store '{$this->name}'",
            Role::Queue => "queue connection '{$this->name}'",
            Role::Session => 'sessions',
            Role::Horizon => 'Horizon',
        };
    }
}
