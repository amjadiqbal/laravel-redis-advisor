<?php

use AmjadIqbal\RedisAdvisor\Checks;

return [

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Redis connection names from config/database.php to inspect. Empty means
    | every connection the app uses: the redis cache stores, redis queue
    | connections, the session connection when SESSION_DRIVER=redis, and
    | Horizon's. Comma-separated in the env variable.
    |
    | Every command this package sends is a read (see Redis\ReadOnlyGuard). To
    | prove it, give it a Redis user that can only read:
    |
    |   ACL SETUSER advisor on >a-long-password ~* resetchannels -@all +@read
    |       +info +config|get +ping +select +hello +auth +client|setname +client|setinfo
    |
    | Commands a host disables (CONFIG on ElastiCache, MEMORY on some others)
    | turn into skipped checks with the reason; nothing fails.
    |
    */

    'connections' => env('REDIS_ADVISOR_CONNECTIONS'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | The Blade dashboard is served at /{path}. Access is decided by the
    | "viewRedisAdvisor" gate, which allows the local environment only until
    | you define it yourself (see the README). The gate check is always added
    | after the middleware below, so emptying this list cannot expose it.
    |
    */

    'dashboard' => [
        'enabled' => env('REDIS_ADVISOR_DASHBOARD', true),
        'path' => env('REDIS_ADVISOR_PATH', 'redis-advisor'),
        'domain' => env('REDIS_ADVISOR_DOMAIN'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Checks
    |--------------------------------------------------------------------------
    |
    | Remove a line to switch a check off, or add your own class that
    | implements AmjadIqbal\RedisAdvisor\Checks\Check.
    |
    */

    'checks' => [
        Checks\Server\Connections::class,
        Checks\Queues\QueueEviction::class,
        Checks\Queues\QueueBacklog::class,
        Checks\Queues\HorizonTrimming::class,
        Checks\Keys\CacheClearFlush::class,
        Checks\Keys\CachePrefix::class,
        Checks\Keys\KeysWithoutTtl::class,
        Checks\Keys\BigKeys::class,
        Checks\Server\Persistence::class,
        Checks\Server\MemoryUsage::class,
        Checks\Server\Fragmentation::class,
        Checks\Sessions\SessionTtl::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored keys
    |--------------------------------------------------------------------------
    |
    | Raw key names or shell patterns (e.g. "*-database-telescope:*") that the
    | key checks (no TTL, big keys, prefixes) never report on.
    |
    */

    'ignore_keys' => [],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    */

    'thresholds' => [
        // Key sampling: SCAN in batches of scan_count, up to sample_keys keys per database
        'sample_keys' => 10000,
        'scan_count' => 1000,

        // Keys
        'no_ttl_min_keys' => 1000,               // estimated keys without a TTL in a database...
        'no_ttl_min_share' => 0.1,               // ...that are at least this share of its (non-queue) keys
        'big_key_notice_bytes' => 1024 ** 2,     // 1 MB
        'big_key_warning_bytes' => 10 * 1024 ** 2,
        'big_keys_max' => 10,                    // findings per database

        // Queues
        'backlog_warning' => 1000,
        'backlog_critical' => 10000,
        'oldest_job_warning_seconds' => 900,
        'oldest_job_critical_seconds' => 3600,
        'horizon_trim_max_minutes' => 1440,      // recent, pending and completed jobs
        'horizon_failed_trim_max_minutes' => 43200,
        'horizon_jobs_warning' => 100000,

        // Server
        'memory_warning' => 0.75,                // share of maxmemory
        'memory_critical' => 0.9,
        'no_limit_warning_bytes' => 100 * 1024 ** 2, // no maxmemory: a notice below this, a warning above
        'fragmentation_ratio' => 1.5,
        'fragmentation_min_bytes' => 100 * 1024 ** 2,
        'swap_ratio' => 0.9,
        'swap_min_used_bytes' => 100 * 1024 ** 2,

        // Sessions
        'session_ttl_slack_seconds' => 60,
    ],

];
