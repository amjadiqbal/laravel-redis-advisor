<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Support;

use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\Connection;

/**
 * A server's memory limit and eviction policy, and what the policy means for the app.
 */
final readonly class Memory
{
    public function __construct(
        public int $used,
        public int $max,
        public ?string $policy,
        public int $evicted,
    ) {}

    /**
     * @throws CheckUnavailable when neither INFO nor CONFIG GET reveal the limit and policy.
     */
    public static function of(Connection $server): self
    {
        $info = $server->info();
        $policy = $server->setting('maxmemory-policy', 'maxmemory_policy');

        return new self(
            used: (int) ($info['used_memory'] ?? 0),
            max: (int) $server->setting('maxmemory', 'maxmemory'),
            policy: $policy !== null && $policy !== '' ? strtolower($policy) : null,
            evicted: (int) ($info['evicted_keys'] ?? 0),
        );
    }

    public function evictsAnyKey(): bool
    {
        return $this->policy !== null && str_starts_with($this->policy, 'allkeys-');
    }

    public function evictsKeysWithTtl(): bool
    {
        return $this->policy !== null && str_starts_with($this->policy, 'volatile-');
    }

    public function ratio(): ?float
    {
        return $this->max > 0 ? $this->used / $this->max : null;
    }
}
