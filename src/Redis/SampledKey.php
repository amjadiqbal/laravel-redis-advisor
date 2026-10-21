<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

final readonly class SampledKey
{
    /**
     * @param  int  $ttlMs  PTTL: milliseconds left, -1 for no expiry.
     * @param  int|null  $bytes  MEMORY USAGE, or null when the server does not allow it.
     */
    public function __construct(public string $key, public int $ttlMs, public ?int $bytes) {}

    public function expires(): bool
    {
        return $this->ttlMs >= 0;
    }
}
