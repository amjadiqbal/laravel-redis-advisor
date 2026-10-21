<?php

use AmjadIqbal\RedisAdvisor\Checks\Keys\BigKeys;
use AmjadIqbal\RedisAdvisor\Checks\Keys\CacheClearFlush;
use AmjadIqbal\RedisAdvisor\Checks\Keys\CachePrefix;
use AmjadIqbal\RedisAdvisor\Checks\Keys\KeysWithoutTtl;
use AmjadIqbal\RedisAdvisor\Checks\Queues\HorizonTrimming;
use AmjadIqbal\RedisAdvisor\Checks\Queues\QueueBacklog;
use AmjadIqbal\RedisAdvisor\Checks\Queues\QueueEviction;
use AmjadIqbal\RedisAdvisor\Checks\Server\Connections;
use AmjadIqbal\RedisAdvisor\Checks\Server\Fragmentation;
use AmjadIqbal\RedisAdvisor\Checks\Server\MemoryUsage;
use AmjadIqbal\RedisAdvisor\Checks\Server\Persistence;
use AmjadIqbal\RedisAdvisor\Checks\Sessions\SessionTtl;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\KeySample;
use AmjadIqbal\RedisAdvisor\Redis\Role;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Redis\Usage;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Tests\Fakes\FakeServer;

const MB = 1024 * 1024;

/**
 * A typical app: queue and sessions on "default" (db 0), cache on "cache" (db 1 unless said otherwise).
 */
function shopApp(FakeServer $server, int $cacheDb = 1, ?FakeServer $cacheServer = null, string $prefix = 'shop-database-'): Topology
{
    return new Topology(
        [$server->connect('default', 0, $prefix), ($cacheServer ?? $server)->connect('cache', $cacheDb, $prefix, $cacheServer ? '10.0.0.9:6379' : '127.0.0.1:6379')],
        [
            new Usage(Role::Cache, 'redis', 'cache', $prefix.'shop-cache-'),
            new Usage(Role::Queue, 'redis', 'default', $prefix.'queues:', ['default']),
            new Usage(Role::Session, 'session', 'default', $prefix.'shop-cache-'),
        ],
    );
}

/** @return list<Severity> */
function severities(array $findings): array
{
    return array_map(fn (Finding $f) => $f->severity, $findings);
}

describe('queue-eviction', function () {
    it('is critical when a cache fills the server that holds queues under allkeys-lru', function () {
        $server = new FakeServer(['maxmemory' => (string) (100 * MB), 'maxmemory_policy' => 'allkeys-lru', 'used_memory' => (string) (20 * MB)]);

        $findings = (new QueueEviction)->run(shopApp($server), new Thresholds);

        expect(severities($findings))->toBe([Severity::Critical])
            ->and($findings[0]->fix)->toContain('maxmemory-policy noeviction')
            ->and($findings[0]->laravel)->toContain('REDIS_CACHE_HOST');
    });

    it('is a warning when only the queue is there and memory is low', function () {
        $queue = new FakeServer(['maxmemory' => (string) (100 * MB), 'maxmemory_policy' => 'allkeys-lfu', 'used_memory' => (string) (10 * MB)]);
        $cache = new FakeServer(['maxmemory_policy' => 'allkeys-lru'], runId: 'run-2');

        expect(severities((new QueueEviction)->run(shopApp($queue, cacheServer: $cache), new Thresholds)))->toBe([Severity::Warning]);
    });

    it('passes under noeviction and volatile policies, and is a notice without a limit', function (string $policy, string $max, array $expected) {
        $server = new FakeServer(['maxmemory' => $max, 'maxmemory_policy' => $policy]);

        expect(severities((new QueueEviction)->run(shopApp($server), new Thresholds)))->toBe($expected);
    })->with([
        ['noeviction', (string) (100 * MB), []],
        ['volatile-lru', (string) (100 * MB), []],
        ['allkeys-lru', '0', [Severity::Notice]],
    ]);
});

describe('cache-clear-flush', function () {
    it('warns that cache:clear empties queues and sessions in a shared database', function () {
        $findings = (new CacheClearFlush)->run(shopApp(new FakeServer, cacheDb: 0), new Thresholds);

        expect(severities($findings))->toBe([Severity::Critical])
            ->and($findings[0]->title)->toContain("queue connection 'redis' and sessions");
    });

    it('passes when the cache has its own database', function () {
        expect((new CacheClearFlush)->run(shopApp(new FakeServer), new Thresholds))->toBe([]);
    });
});

describe('keys-without-ttl', function () {
    it('groups keys that never expire and leaves queue keys out', function () {
        $server = (new FakeServer(['maxmemory' => (string) (100 * MB), 'maxmemory_policy' => 'volatile-lru']))
            ->keys(1, 'shop-database-shop-cache-report:', 600)
            ->keys(1, 'shop-database-shop-cache-fresh:', 200, ttl: 60000)
            ->keys(0, 'shop-database-queues:default', 3000);

        $findings = (new KeysWithoutTtl)->run(shopApp($server), new Thresholds(['no_ttl_min_keys' => 100]));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->severity)->toBe(Severity::Warning)
            ->and($findings[0]->title)->toBe('~600 keys with no TTL in db 1 on 127.0.0.1:6379 (75% of keys)')
            ->and($findings[0]->detail)->toContain("shop-database-shop-cache-report:* (~600, cache store 'redis')")
            ->and($findings[0]->laravel)->toContain('not Cache::forever()');
    });

    it('stays quiet below the thresholds', function () {
        $server = (new FakeServer)->keys(1, 'shop-database-shop-cache-a:', 50);

        expect((new KeysWithoutTtl)->run(shopApp($server), new Thresholds))->toBe([]);
    });
});

describe('big-keys', function () {
    it('reports keys over the size thresholds with a non-blocking fix', function () {
        $server = (new FakeServer)
            ->key(1, 'shop-database-shop-cache-catalog', -1, 25 * MB)
            ->key(1, 'shop-database-shop-cache-menu', -1, 2 * MB, 'hash')
            ->key(1, 'shop-database-shop-cache-small', -1, 1000);

        $findings = (new BigKeys)->run(shopApp($server), new Thresholds);

        expect(severities($findings))->toBe([Severity::Warning, Severity::Notice])
            ->and($findings[0]->title)->toBe('shop-database-shop-cache-catalog uses 25 MB (string)')
            ->and($findings[0]->fix)->toContain("UNLINK 'shop-database-shop-cache-catalog'");
    });

    it('is skipped with the reason when MEMORY USAGE is not allowed', function () {
        $server = (new FakeServer(memoryDisabled: true))->key(1, 'k', -1, 25 * MB);

        expect(fn () => (new BigKeys)->run(shopApp($server), new Thresholds))->toThrow(CheckUnavailable::class, 'MEMORY USAGE');
    });
});

describe('persistence', function () {
    it('is critical when queues live on a server that saves nothing', function () {
        $findings = (new Persistence)->run(shopApp(new FakeServer(config: ['save' => '', 'appendonly' => 'no'])), new Thresholds);

        expect(severities($findings))->toBe([Severity::Critical])
            ->and($findings[0]->fix)->toContain('appendonly yes');
    });

    it('passes with snapshots or AOF', function (array $info, array $config) {
        expect((new Persistence)->run(shopApp(new FakeServer($info, $config)), new Thresholds))->toBe([]);
    })->with([
        [[], ['save' => '3600 1 300 100 60 10000']],
        [['aof_enabled' => '1'], ['save' => '']],
    ]);

    it('falls back to INFO when CONFIG is disabled', function () {
        $server = new FakeServer(['rdb_saves' => '0', 'rdb_changes_since_last_save' => '420', 'uptime_in_seconds' => '7200'], config: null);

        expect((new Persistence)->run(shopApp($server), new Thresholds)[0]->title)->toContain('has taken no snapshot since it started 2 hours ago');
    });

    it('is skipped, not passed, when neither CONFIG nor INFO can tell', function () {
        expect(fn () => (new Persistence)->run(shopApp(new FakeServer(config: null)), new Thresholds))->toThrow(CheckUnavailable::class, 'INFO alone cannot tell');
    });

    it('reports a failed snapshot on any host', function () {
        $findings = (new Persistence)->run(shopApp(new FakeServer(['rdb_last_bgsave_status' => 'err'], config: null)), new Thresholds);

        expect($findings[0]->severity)->toBe(Severity::Critical)->and($findings[0]->title)->toContain('RDB snapshot');
    });
});

describe('memory-usage', function () {
    it('flags a missing limit, as a warning once memory is large', function (int $used, Severity $severity) {
        $findings = (new MemoryUsage)->run(shopApp(new FakeServer(['used_memory' => (string) $used])), new Thresholds);

        expect(severities($findings))->toBe([$severity])->and($findings[0]->fix)->toContain('maxmemory-policy noeviction');
    })->with([[10 * MB, Severity::Notice], [600 * MB, Severity::Warning]]);

    it('is critical near maxmemory', function () {
        $server = new FakeServer(['used_memory' => (string) (95 * MB), 'maxmemory' => (string) (100 * MB), 'maxmemory_policy' => 'noeviction']);
        $findings = (new MemoryUsage)->run(shopApp($server), new Thresholds);

        expect(severities($findings))->toBe([Severity::Critical])->and($findings[0]->detail)->toContain('refuses writes');
    });
});

describe('fragmentation', function () {
    it('flags large fragmentation and swap, not small servers', function (array $info, array $expected) {
        expect(severities((new Fragmentation)->run(shopApp(new FakeServer($info)), new Thresholds)))->toBe($expected);
    })->with([
        'fragmented' => [['used_memory' => (string) (400 * MB), 'used_memory_rss' => (string) (900 * MB), 'mem_fragmentation_ratio' => '2.25'], [Severity::Warning]],
        'swapping' => [['used_memory' => (string) (400 * MB), 'used_memory_rss' => (string) (200 * MB), 'mem_fragmentation_ratio' => '0.5'], [Severity::Critical]],
        'small' => [['used_memory' => (string) MB, 'used_memory_rss' => (string) (8 * MB), 'mem_fragmentation_ratio' => '8'], []],
    ]);
});

describe('queue-backlog', function () {
    it('reports waiting jobs and the age of the oldest', function () {
        $old = json_encode(['displayName' => 'App\\Jobs\\Ship', 'createdAt' => time() - 7200]);
        $server = (new FakeServer)
            ->key(0, 'shop-database-queues:default', type: 'list', list: [$old, ...array_fill(0, 1499, '{}')])
            ->key(0, 'shop-database-queues:default:reserved', type: 'zset', zset: ['a' => time() - 600, 'b' => time() + 60]);

        $findings = (new QueueBacklog)->run(shopApp($server), new Thresholds);

        expect(severities($findings))->toBe([Severity::Critical])
            ->and($findings[0]->title)->toBe("1,500 jobs waiting on queue 'default' (oldest waiting 2 hours)")
            ->and($findings[0]->evidence['reservations_expired'])->toBe(1)
            ->and($findings[0]->laravel)->toContain('queue:monitor redis:default');
    });

    it('passes when the queue is short', function () {
        $server = (new FakeServer)->key(0, 'shop-database-queues:default', type: 'list', list: ['{}']);

        expect((new QueueBacklog)->run(shopApp($server), new Thresholds))->toBe([]);
    });
});

describe('session-ttl', function () {
    it('flags sessions that outlive SESSION_LIFETIME and ones that never expire', function () {
        config(['session.lifetime' => 120]);
        $id = fn (int $i) => 'shop-database-shop-cache-'.str_pad((string) $i, 40, 'a');
        $server = new FakeServer;

        for ($i = 1; $i <= 10; $i++) {
            $server->key(0, $id($i), $i <= 3 ? 30 * 86400 * 1000 : 3600 * 1000);
        }

        $server->key(0, $id(11), -1);
        $server->key(0, 'shop-database-shop-cache-not-a-session', -1);

        $findings = (new SessionTtl(config()))->run(shopApp($server), new Thresholds);

        expect(severities($findings))->toBe([Severity::Warning, Severity::Warning])
            ->and($findings[0]->title)->toBe('1 session never expires')
            ->and($findings[1]->title)->toContain('3 sessions outlive SESSION_LIFETIME (120 minutes): up to 30 days left');
    });

    it('is skipped when sessions are not in Redis', function () {
        config(['session.driver' => 'file']);
        $topology = new Topology([(new FakeServer)->connect()], []);

        expect(fn () => (new SessionTtl(config()))->run($topology, new Thresholds))->toThrow(CheckUnavailable::class, "SESSION_DRIVER is 'file'");
    });
});

describe('cache-prefix', function () {
    it('notices the default prefix and warns about another app in the same database', function () {
        $server = (new FakeServer)->keys(1, 'other-app-database-other-app-cache-x:', 30);

        $findings = (new CachePrefix)->run(shopApp($server, prefix: 'laravel-database-'), new Thresholds);

        expect(array_map(fn ($f) => [$f->severity, $f->title], $findings))->toBe([
            [Severity::Notice, "Keys on the 'default' connection use Laravel's default prefix 'laravel-database-'"],
            [Severity::Warning, "Another app writes to db 1 on 127.0.0.1:6379: keys starting 'other-app-database-'"],
            [Severity::Warning, "Keys on the 'cache' connection use Laravel's default prefix 'laravel-database-'"],
        ]);
    });

    it('passes with app-specific prefixes', function () {
        $server = (new FakeServer)->keys(1, 'shop-database-shop-cache-x:', 30);

        expect((new CachePrefix)->run(shopApp($server), new Thresholds))->toBe([]);
    });
});

it('reports connections that did not answer, critically when they hold queues', function () {
    $topology = new Topology([(new FakeServer)->connect('cache', 1)], [new Usage(Role::Queue, 'redis', 'default', 'queues:', ['default'])], ['default' => 'Connection refused']);

    expect(severities((new Connections)->run($topology, new Thresholds)))->toBe([Severity::Critical]);
});

it('skips Horizon checks when Horizon is not installed', function () {
    expect(fn () => (new HorizonTrimming(config()))->run(shopApp(new FakeServer), new Thresholds))->toThrow(CheckUnavailable::class, 'Horizon is not installed');
});

it('flags long Horizon trims and the default prefix', function () {
    config(['horizon.trim' => ['completed' => 43200]]);
    $server = (new FakeServer)->key(0, 'laravel_horizon:completed_jobs', type: 'zset', zset: array_fill_keys(array_map('strval', range(1, 50)), 1.0));
    $topology = new Topology([$server->connect('horizon', 0, 'laravel_horizon:')], [new Usage(Role::Horizon, 'horizon', 'horizon', 'laravel_horizon:')]);

    $findings = (new HorizonTrimming(config()))->run($topology, new Thresholds);

    expect(array_map(fn ($f) => $f->title, $findings))->toBe([
        'Horizon keeps completed jobs for 30 days (50 held now)',
        "Horizon uses the default prefix 'laravel_horizon:'",
    ]);
});

it('never sends a command outside the read-only list while sampling', function () {
    $server = (new FakeServer)->keys(1, 'shop-database-shop-cache-k:', 20, bytes: 2 * MB);
    $topology = shopApp($server);
    (new BigKeys)->run($topology, new Thresholds);

    expect(array_values(array_unique($topology->connection('cache')->sent)))->toEqualCanonicalizing(['DBSIZE', 'SCAN', 'PTTL', 'MEMORY', 'TYPE']);
});

it('estimates from a partial sample instead of calling it complete', function () {
    $server = (new FakeServer)->keys(1, 'k:', 50);
    $sample = KeySample::take($server->connect('cache', 1), 20);

    expect($sample->complete)->toBeFalse()
        ->and($sample->size())->toBe(20)
        ->and($sample->estimate(10))->toBe(25)
        ->and($sample->describe())->toBe('a sample of 20 of 50 keys');
});
