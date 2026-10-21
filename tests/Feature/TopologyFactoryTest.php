<?php

use AmjadIqbal\RedisAdvisor\Redis\TopologyFactory;
use AmjadIqbal\RedisAdvisor\Redis\Usage;

it('reads what the app keeps in Redis from its config', function () {
    config([
        'database.redis.options.prefix' => 'shop-database-',
        'database.redis.sessions' => ['host' => '127.0.0.1', 'database' => 4, 'options' => ['prefix' => 'sess-']],
        'cache.prefix' => 'shop-cache-',
        'cache.stores' => [
            'redis' => ['driver' => 'redis', 'connection' => 'cache'],
            'reports' => ['driver' => 'redis', 'connection' => 'cache', 'prefix' => 'reports-'],
            'file' => ['driver' => 'file', 'path' => '/tmp'],
        ],
        'queue.connections' => [
            'redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'emails'],
            'sync' => ['driver' => 'sync'],
        ],
        'session.driver' => 'redis',
        'session.connection' => 'sessions',
    ]);

    $usages = app(TopologyFactory::class, ['environment' => 'production'])->usages();

    expect(array_map(fn (Usage $u) => [$u->label(), $u->connection, $u->keyPrefix, $u->queues], $usages))->toBe([
        ["cache store 'redis'", 'cache', 'shop-database-shop-cache-', []],
        ["cache store 'reports'", 'cache', 'shop-database-reports-', []],
        ["queue connection 'redis'", 'default', 'shop-database-queues:', ['emails']],
        ['sessions', 'sessions', 'sess-shop-cache-', []],
    ]);
});
