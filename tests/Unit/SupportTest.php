<?php

use AmjadIqbal\RedisAdvisor\Redis\Info;
use AmjadIqbal\RedisAdvisor\Redis\ReadOnlyGuard;
use AmjadIqbal\RedisAdvisor\Redis\ServerInfo;
use AmjadIqbal\RedisAdvisor\Support\Bytes;
use AmjadIqbal\RedisAdvisor\Support\Keys;

it('allows only read commands', function (string $command, array $args) {
    ReadOnlyGuard::assertReadOnly($command, $args);
    expect(true)->toBeTrue();
})->with([
    ['INFO', []], ['info', ['memory']], ['SCAN', ['0', 'COUNT', 100]], ['PTTL', ['k']],
    ['CONFIG', ['GET', 'maxmemory-policy']], ['MEMORY', ['USAGE', 'k', 'SAMPLES', 5]], ['config', ['get', 'save']],
]);

it('refuses writes, KEYS and admin commands', function (string $command, array $args) {
    expect(fn () => ReadOnlyGuard::assertReadOnly($command, $args))->toThrow(LogicException::class);
})->with([
    ['SET', ['k', 'v']], ['DEL', ['k']], ['UNLINK', ['k']], ['FLUSHDB', []], ['FLUSHALL', []], ['KEYS', ['*']],
    ['CONFIG', ['SET', 'maxmemory', '1']], ['CONFIG', ['REWRITE']], ['EVAL', ['return 1', 0]], ['MEMORY', ['PURGE']],
    ['EXPIRE', ['k', 10]], ['SHUTDOWN', []], ['DEBUG', ['SLEEP', 1]], ['OBJECT', ['HELP']],
]);

it('parses INFO and keyspace lines', function () {
    $info = Info::parse("# Server\r\nredis_version:7.4.2\r\nrun_id:abc\r\n\r\n# Keyspace\r\ndb1:keys=3,expires=1,avg_ttl=0\r\n");

    expect($info)->toMatchArray(['redis_version' => '7.4.2', 'run_id' => 'abc', 'db1' => 'keys=3,expires=1,avg_ttl=0'])
        ->and(Info::keyspace($info['db1']))->toBe(['keys' => 3, 'expires' => 1, 'avg_ttl' => 0]);
});

it('tells Valkey from Redis', function () {
    expect(ServerInfo::fromInfo(['redis_version' => '7.2.4', 'valkey_version' => '8.1.1', 'server_name' => 'valkey', 'run_id' => 'x'], 'h:1')->name())->toBe('Valkey 8.1.1')
        ->and(ServerInfo::fromInfo(['redis_version' => '8.2.0', 'run_id' => 'x'], 'h:1')->name())->toBe('Redis 8.2.0')
        ->and(ServerInfo::fromInfo(['redis_version' => '7.0'], 'cache.example:6379')->id)->toBe('cache.example:6379');
});

it('turns keys into glob shapes', function () {
    expect(Keys::shape('app-cache-report:2024:user:81723'))->toBe('app-cache-report:*:user:*')
        ->and(Keys::shape('x:9b2f3c1e-1d2c-4a5b-8c7d-0123456789ab'))->toBe('x:*')
        ->and(Keys::shape('laravel-cache-'.str_repeat('aB3', 13).'x'))->toBe('laravel-cache-*')
        ->and(Keys::shape('weird[1]*'))->toBe('weird\[*\]\*')
        ->and(Keys::display("bin\x00\xFFkey"))->toBe('bin\x00\xFFkey')
        ->and(Keys::cli('10.0.0.5:6380', 2))->toBe('redis-cli -h 10.0.0.5 -p 6380 -n 2')
        ->and(Keys::cli('127.0.0.1:6379', 0))->toBe('redis-cli -h 127.0.0.1 -p 6379');
});

it('formats sizes for people and for redis.conf', function () {
    expect(Bytes::human(1536))->toBe('1.5 KB')
        ->and(Bytes::human(10 * 1024 ** 2))->toBe('10 MB')
        ->and(Bytes::config(100 * 1024 ** 2))->toBe('256mb')
        ->and(Bytes::config(900 * 1024 ** 2))->toBe('1gb')
        ->and(Bytes::config(1.3 * 1024 ** 3))->toBe('1536mb');
});
