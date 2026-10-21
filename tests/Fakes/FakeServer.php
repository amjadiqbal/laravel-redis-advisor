<?php

namespace AmjadIqbal\RedisAdvisor\Tests\Fakes;

/**
 * An in-memory Redis for unit tests: INFO fields, CONFIG values and keys per database.
 */
final class FakeServer
{
    /** @var array<int, array<string, array{ttl: int, bytes: int, type: string, list: list<string>, zset: array<string, float>}>> */
    public array $databases = [];

    /**
     * @param  array<string, string>  $info
     * @param  array<string, string>|null  $config  null: CONFIG is disabled, as on ElastiCache.
     */
    public function __construct(
        public array $info = [],
        public ?array $config = [],
        public bool $memoryDisabled = false,
        public string $runId = 'run-1',
    ) {
        $this->info += [
            'redis_version' => '7.4.2',
            'run_id' => $runId,
            'uptime_in_seconds' => '864000',
            'used_memory' => '1048576',
            'used_memory_rss' => '2097152',
            'maxmemory' => '0',
            'maxmemory_policy' => 'noeviction',
            'evicted_keys' => '0',
            'aof_enabled' => '0',
            'rdb_last_bgsave_status' => 'ok',
            'aof_last_write_status' => 'ok',
            'mem_allocator' => 'jemalloc-5.3.0',
        ];
    }

    /**
     * @param  list<string>  $list
     * @param  array<string, float>  $zset
     */
    public function key(int $db, string $key, int $ttl = -1, int $bytes = 100, string $type = 'string', array $list = [], array $zset = []): self
    {
        $this->databases[$db][$key] = compact('ttl', 'bytes', 'type', 'list', 'zset');

        return $this;
    }

    public function keys(int $db, string $prefix, int $count, int $ttl = -1, int $bytes = 100): self
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->key($db, $prefix.$i, $ttl, $bytes);
        }

        return $this;
    }

    public function connect(string $name = 'default', int $db = 0, string $prefix = '', string $address = '127.0.0.1:6379'): FakeConnection
    {
        return new FakeConnection($this, $name, $db, $prefix, $address);
    }
}
