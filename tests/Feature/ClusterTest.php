<?php

use AmjadIqbal\RedisAdvisor\Checks\Server\Connections;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Console\ReportCommand;
use AmjadIqbal\RedisAdvisor\Redis\TopologyFactory;

beforeEach(function () {
    // The queue runs on a Redis Cluster; the cache on a single server that does not answer (nothing listens on port 1).
    config([
        'database.redis' => [
            'client' => 'predis',
            'cache' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 1],
            'clusters' => ['jobs' => [['host' => '127.0.0.1', 'port' => 2]]],
        ],
        'cache.stores' => ['redis' => ['driver' => 'redis', 'connection' => 'cache']],
        'queue.connections' => ['redis' => ['driver' => 'redis', 'connection' => 'jobs', 'queue' => 'default']],
        'session.driver' => 'file',
    ]);
});

it('leaves a cluster connection out without connecting, and never reports it as a finding', function () {
    $topology = app(TopologyFactory::class, ['environment' => 'production'])->make();

    expect(array_keys($topology->notInspected()))->toBe(['jobs'])
        ->and($topology->notInspected()['jobs'])->toContain('Redis Cluster connection, not inspected')
        ->and(array_keys($topology->failures()))->toBe(['cache']);

    // Only the unreachable single server is a finding, as a warning: the cluster that holds the queue adds nothing.
    $findings = (new Connections)->run($topology, new Thresholds);

    expect(array_map(fn ($f) => [$f->subject, $f->severity->value], $findings))->toBe([['cache', 'warning']]);
});

it('prefers database.redis.<name> over clusters.<name>, as Laravel does', function () {
    config(['database.redis.jobs' => ['host' => '127.0.0.1', 'port' => 1]]);

    $topology = app(TopologyFactory::class, ['environment' => 'production'])->make();

    expect($topology->notInspected())->toBe([])
        ->and(array_keys($topology->failures()))->toBe(['cache', 'jobs']);
});

it('exits 2 with the reason when the only Redis connection is a cluster', function () {
    config(['cache.stores' => ['array' => ['driver' => 'array']]]);

    $this->artisan('redis-advisor:report', ['--fail-on' => 'notice'])
        ->expectsOutputToContain("'jobs' is a Redis Cluster connection")
        ->assertExitCode(ReportCommand::UNAVAILABLE);
});
