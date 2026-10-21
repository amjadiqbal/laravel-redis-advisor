<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

/**
 * A sample of one database's keys, taken with SCAN (never KEYS), with each
 * key's TTL and size. TYPE, PTTL and MEMORY USAGE do not count as an access,
 * so sampling does not change which keys an LRU or LFU policy evicts.
 */
final readonly class KeySample
{
    /**
     * @param  list<SampledKey>  $keys
     * @param  int  $total  DBSIZE when the sample was taken.
     * @param  bool  $complete  Whether SCAN went through the whole database.
     * @param  string|null  $memoryUnavailable  Why MEMORY USAGE could not be read, if it could not.
     */
    public function __construct(
        public array $keys,
        public int $total,
        public bool $complete,
        public ?string $memoryUnavailable = null,
    ) {}

    public static function take(Connection $connection, int $limit, int $count = 1000): self
    {
        $total = (int) $connection->command('DBSIZE');
        $cursor = '0';
        $seen = [];
        $keys = [];
        $memory = null;
        $truncated = false;

        do {
            $reply = $connection->command('SCAN', $cursor, 'COUNT', $count);
            $cursor = (string) ($reply[0] ?? '0');

            foreach ((array) ($reply[1] ?? []) as $key) {
                $key = (string) $key;

                if (isset($seen[$key])) {
                    continue;
                }

                if (count($keys) >= $limit) {
                    $truncated = true;

                    continue;
                }

                $seen[$key] = true;
                $ttl = (int) $connection->command('PTTL', $key);

                if ($ttl === -2) {
                    continue; // expired between SCAN and PTTL
                }

                $bytes = null;

                if ($memory === null) {
                    try {
                        $bytes = (int) $connection->command('MEMORY', 'USAGE', $key, 'SAMPLES', 5);
                    } catch (CheckUnavailable $e) {
                        $memory = $e->getMessage();
                    }
                }

                $keys[] = new SampledKey($key, $ttl, $bytes);
            }
        } while ($cursor !== '0' && count($keys) < $limit);

        return new self($keys, max($total, count($keys)), $cursor === '0' && ! $truncated, $memory);
    }

    public function size(): int
    {
        return count($this->keys);
    }

    /** Scale a count in the sample up to the whole database. */
    public function estimate(int $inSample): int
    {
        if ($this->complete || $this->keys === []) {
            return $inSample;
        }

        return (int) round($inSample / count($this->keys) * $this->total);
    }

    /** "all 120 keys" or "1,000 of 52,310 keys". */
    public function describe(): string
    {
        return $this->complete
            ? 'all '.number_format(count($this->keys)).' keys'
            : 'a sample of '.number_format(count($this->keys)).' of '.number_format($this->total).' keys';
    }
}
