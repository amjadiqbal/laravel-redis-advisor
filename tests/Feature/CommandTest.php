<?php

use AmjadIqbal\RedisAdvisor\Console\ReportCommand;

beforeEach(function () {
    // Nothing listens on port 1: every connection is refused.
    config([
        'database.redis.client' => 'predis',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 1],
    ]);
});

it('exits 2 with the reason when no Redis connection answers', function () {
    $this->artisan('redis-advisor:report')
        ->expectsOutputToContain('Could not inspect Redis: No Redis connection answered.')
        ->assertExitCode(ReportCommand::UNAVAILABLE);
});

it('exits 2 when the app does not use Redis at all', function () {
    config(['cache.stores' => ['array' => ['driver' => 'array']], 'queue.connections' => ['sync' => ['driver' => 'sync']], 'session.driver' => 'file']);

    $this->artisan('redis-advisor:report')
        ->expectsOutputToContain('No Redis connection to inspect')
        ->assertExitCode(ReportCommand::UNAVAILABLE);
});

it('rejects an unknown --fail-on value', function () {
    $this->artisan('redis-advisor:report', ['--fail-on' => 'sometimes'])->assertExitCode(2);
});

it('adds itself to artisan about', function () {
    $this->artisan('about', ['--only' => 'redis_advisor'])
        ->expectsOutputToContain('Redis Advisor')
        ->assertSuccessful();
});
