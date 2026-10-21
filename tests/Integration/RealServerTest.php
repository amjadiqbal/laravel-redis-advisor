<?php

use AmjadIqbal\RedisAdvisor\RedisAdvisor;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Tests\Integration\IntegrationTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

/*
 * Each test creates a real problem on a real server through Laravel itself,
 * checks the advisor finds it, applies the fix (as the test, never through the
 * package) and checks the finding clears. Restricted servers are covered in
 * RestrictedServerTest.
 */

beforeEach(function () {
    if (getenv('REDIS_ADVISOR_TEST_RESTRICTED')) {
        $this->markTestSkipped('This server is restricted; see RestrictedServerTest.');
    }

    config(['database.redis.admin' => adminConnection()]);
    admin('FLUSHALL');
    admin('CONFIG', 'SET', 'maxmemory', '0');
    admin('CONFIG', 'SET', 'maxmemory-policy', 'noeviction');
    admin('CONFIG', 'SET', 'appendonly', 'yes');
});

it('reads both connections on one server and knows what each holds', function () {
    $report = app(RedisAdvisor::class)->report([], ['connections']);

    expect($report->redis['servers'])->toHaveCount(1)
        ->and(array_column($report->redis['servers'][0]['connections'], 'holds', 'name'))->toBe([
            'cache' => ["cache store 'redis'"],
            'default' => ["queue connection 'redis'", 'sessions'],
        ])
        ->and($report->redis['servers'][0]['connections'][0]['client'])->toBe(IntegrationTestCase::client());
});

it('catches a queued job that allkeys-lru will evict, and the job really is lost', function () {
    Queue::connection('redis')->pushRaw(jobPayload(), 'default');
    $used = (int) adminInfo('used_memory');
    admin('CONFIG', 'SET', 'maxmemory', (string) ($used + 3 * 1024 * 1024));
    admin('CONFIG', 'SET', 'maxmemory-policy', 'allkeys-lru');

    expect(found('queue-eviction'))->toHaveCount(1)
        ->and(severityOf('queue-eviction'))->toBe(Severity::Critical);

    // What the finding warns about: the cache fills memory and Redis drops the queue.
    for ($i = 0; $i < 400 && (int) admin('LLEN', 'shop-database-queues:default') > 0; $i++) {
        Cache::store('redis')->put("blob:{$i}", str_repeat('x', 200_000), 3600);
    }

    expect((int) admin('LLEN', 'shop-database-queues:default'))->toBe(0)
        ->and((int) adminInfo('evicted_keys'))->toBeGreaterThan(0);

    admin('CONFIG', 'SET', 'maxmemory-policy', 'noeviction');
    expect(found('queue-eviction'))->toBe([]);
});

it('catches cache:clear wiping queued jobs when they share a database, as it really does', function () {
    config(['cache.stores.redis.connection' => 'default']);
    app('cache')->forgetDriver('redis');
    Queue::connection('redis')->pushRaw(jobPayload(), 'default');

    expect(found('cache-clear-flush'))->toBe(["critical: php artisan cache:clear also deletes the queue connection 'redis' and sessions"]);

    Artisan::call('cache:clear', ['store' => 'redis']);
    expect((int) admin('LLEN', 'shop-database-queues:default'))->toBe(0);

    config(['cache.stores.redis.connection' => 'cache']);
    expect(found('cache-clear-flush'))->toBe([]);
});

it('finds keys that never expire under volatile-lru, and clears once they get a TTL', function () {
    admin('CONFIG', 'SET', 'maxmemory', '512mb');
    admin('CONFIG', 'SET', 'maxmemory-policy', 'volatile-lru');
    config(['redis-advisor.thresholds.sample_keys' => 5000]);

    foreach (range(1, 1200) as $i) {
        Cache::store('redis')->forever("report:{$i}", 'x');
    }

    Cache::store('redis')->put('fresh', 'x', 600);

    expect(found('keys-without-ttl'))->toBe(['warning: ~1,200 keys with no TTL in db 1 on 127.0.0.1:'.getenv('REDIS_ADVISOR_TEST_PORT').' (100% of keys)']);

    foreach (range(1, 1200) as $i) {
        Cache::store('redis')->put("report:{$i}", 'x', 600);
    }

    expect(found('keys-without-ttl'))->toBe([]);
});

it('finds a big key and clears once it is gone', function () {
    Cache::store('redis')->put('catalog', str_repeat('x', 12 * 1024 * 1024), 600);
    Cache::store('redis')->put('small', 'x', 600);

    expect(found('big-keys'))->toHaveCount(1)
        // 12 MB serialized by Laravel, plus allocator overhead.
        ->and(found('big-keys')[0])->toMatch('~^warning: shop-database-shop-cache-catalog uses 1[2-6](\.\d)? MB \(string\)$~');

    Cache::store('redis')->forget('catalog');
    expect(found('big-keys'))->toBe([]);
});

it('finds persistence off under queues, and clears with AOF on', function () {
    admin('CONFIG', 'SET', 'appendonly', 'no');
    admin('CONFIG', 'SET', 'save', '');

    expect(found('persistence'))->toHaveCount(1)
        ->and(severityOf('persistence'))->toBe(Severity::Critical);

    admin('CONFIG', 'SET', 'appendonly', 'yes');
    expect(found('persistence'))->toBe([]);
});

it('finds a queue backlog with the age of the oldest job', function () {
    Queue::connection('redis')->pushRaw(jobPayload(age: 7200), 'default');

    foreach (range(1, 1100) as $i) {
        Queue::connection('redis')->pushRaw(jobPayload(), 'default');
    }

    expect(found('queue-backlog'))->toBe(["critical: 1,101 jobs waiting on queue 'default' (oldest waiting 2 hours)"]);

    admin('DEL', 'shop-database-queues:default');
    expect(found('queue-backlog'))->toBe([]);
});

it('finds sessions that outlive SESSION_LIFETIME', function () {
    foreach (range(1, 5) as $i) {
        Redis::connection('default')->setex('shop-cache-'.Str::random(40), 7000, 'payload');
    }

    expect(found('session-ttl'))->toBe([]);

    Redis::connection('default')->setex('shop-cache-'.Str::random(40), 30 * 86400, 'payload');
    expect(found('session-ttl'))->toHaveCount(1)
        ->and(found('session-ttl')[0])->toContain('1 session outlives SESSION_LIFETIME (120 minutes): up to 30 days left');
});

it('finds a server close to maxmemory', function () {
    // Measured on an idle server: a client's query buffer briefly counts as used memory.
    admin('CONFIG', 'SET', 'maxmemory', (string) (int) ((int) adminInfo('used_memory') * 1.05));

    expect(severityOf('memory-usage'))->toBe(Severity::Critical);

    admin('CONFIG', 'SET', 'maxmemory', '1gb');
    expect(found('memory-usage'))->toBe([]);
});

it('finds another app writing to the same database', function () {
    foreach (range(1, 40) as $i) {
        admin('SELECT', '1');
        admin('SET', "other-app-database-other-app-cache-k:{$i}", 'x');
    }

    admin('SELECT', '0');

    expect(found('cache-prefix'))->toBe(['warning: Another app writes to db 1 on 127.0.0.1:'.getenv('REDIS_ADVISOR_TEST_PORT').": keys starting 'other-app-database-'"]);

    admin('SELECT', '1');
    admin('FLUSHDB');
    admin('SELECT', '0');
    expect(found('cache-prefix'))->toBe([]);
});

it('runs every check on a healthy app without failing one', function () {
    admin('CONFIG', 'SET', 'maxmemory', '1gb');
    Cache::store('redis')->put('a', 'b', 60);
    Queue::connection('redis')->pushRaw(jobPayload(), 'default');

    $report = app(RedisAdvisor::class)->report();
    $skipped = array_map(fn ($r) => $r->check, $report->skipped());

    expect($report->findings())->toBe([])
        ->and($skipped)->toBe(['horizon-trimming']);
});
