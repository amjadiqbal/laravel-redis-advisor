<?php

use AmjadIqbal\RedisAdvisor\RedisAdvisor;
use AmjadIqbal\RedisAdvisor\Tests\Integration\IntegrationTestCase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

/*
 * Two ways a managed host restricts the advisor:
 * - CONFIG renamed away (as on ElastiCache and MemoryDB): scripts/integration.sh starts this
 *   server with --rename-command CONFIG "" and allkeys-lru, persistence off.
 * - an ACL user that may read but not run CONFIG: created here on a normal server.
 */

it('works without CONFIG: reads the policy from INFO and skips only what INFO cannot tell', function () {
    if (! getenv('REDIS_ADVISOR_TEST_RESTRICTED')) {
        $this->markTestSkipped('Needs the CONFIG-less server from scripts/integration.sh.');
    }

    config(['database.redis.admin' => adminConnection()]);
    admin('FLUSHALL');
    Queue::connection('redis')->pushRaw(jobPayload(), 'default');

    $report = app(RedisAdvisor::class)->report();
    $byCheck = [];

    foreach ($report->results as $r) {
        $byCheck[$r->check] = $r;
    }

    expect($byCheck['queue-eviction']->wasSkipped())->toBeFalse()
        ->and($byCheck['queue-eviction']->findings[0]->title)->toContain('(maxmemory-policy allkeys-lru)')
        ->and($byCheck['memory-usage']->wasSkipped())->toBeFalse()
        ->and($byCheck['persistence']->wasSkipped())->toBeTrue()
        ->and($byCheck['persistence']->skippedBecause)->toContain('does not allow CONFIG GET')
        ->and($byCheck['persistence']->skippedBecause)->toContain('INFO alone cannot tell');
});

it('explains the ACL grant a read-only user needs, and that user cannot write', function () {
    if (getenv('REDIS_ADVISOR_TEST_RESTRICTED')) {
        $this->markTestSkipped('Runs on the normal server.');
    }

    config(['database.redis.admin' => adminConnection()]);
    admin('FLUSHALL');
    admin('ACL', 'SETUSER', 'advisor', 'reset', 'on', '>advisor-pass', '~*', 'resetchannels', '-@all', '+@read', '+info', '+ping', '+select', '+hello', '+auth', '+client|setname', '+client|setinfo');
    admin('CONFIG', 'SET', 'save', '');
    admin('CONFIG', 'SET', 'appendonly', 'no');

    config([
        'database.redis.default' => IntegrationTestCase::connection(0, ['username' => 'advisor', 'password' => 'advisor-pass']),
        'database.redis.cache' => IntegrationTestCase::connection(1, ['username' => 'advisor', 'password' => 'advisor-pass']),
    ]);

    // RedisManager copies database.redis when first resolved: rebuild it, and the advisor that holds it.
    app()->forgetInstance('redis');
    app()->forgetInstance(RedisAdvisor::class);
    Illuminate\Support\Facades\Redis::clearResolvedInstances();
    config(['database.redis.admin' => adminConnection()]);
    admin('ACL', 'SETUSER', 'advisor', '+acl|whoami');

    expect(app('redis')->connection('default')->client() instanceof Redis
        ? app('redis')->connection('default')->client()->rawCommand('ACL', 'WHOAMI')
        : app('redis')->connection('default')->client()->executeRaw(['ACL', 'WHOAMI']))->toBe('advisor');

    $persistence = runCheck('persistence');

    expect($persistence->wasSkipped())->toBeTrue()
        ->and($persistence->skippedBecause)->toContain('may not run CONFIG GET')
        ->and($persistence->howToEnable)->toBe('ACL SETUSER advisor +config|get')
        ->and(runCheck('big-keys')->wasSkipped())->toBeFalse();

    try {
        $written = app('redis')->connection('default')->set('x', 'y');
    } catch (Throwable) {
        $written = false;
    }

    expect($written)->toBeFalse();

    admin('ACL', 'DELUSER', 'advisor');
    app()->forgetInstance('redis');
    app()->forgetInstance(RedisAdvisor::class);
});
