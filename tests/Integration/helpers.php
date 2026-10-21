<?php

use AmjadIqbal\RedisAdvisor\CheckResult;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\RedisAdvisor;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Tests\Integration\IntegrationTestCase;
use Illuminate\Support\Facades\Redis;

/**
 * A raw command as the test's own admin user, never through the package.
 */
function admin(string ...$args): mixed
{
    $client = Redis::connection('admin')->client();

    return $client instanceof \Redis ? $client->rawCommand(...$args) : $client->executeRaw($args);
}

function adminInfo(string $field): string
{
    preg_match('~^'.preg_quote($field, '~').':(.*)$~m', (string) admin('INFO'), $m);

    return trim($m[1] ?? '');
}

function runCheck(string $id): CheckResult
{
    app('redis')->purge('default');
    app('redis')->purge('cache');
    $report = app(RedisAdvisor::class)->report([], [$id]);

    return $report->results[0];
}

/**
 * @return list<string> "severity: title"
 */
function found(string $id): array
{
    $result = runCheck($id);

    expect($result->wasSkipped())->toBeFalse($result->skippedBecause ?? '');

    return array_map(fn (Finding $f): string => $f->severity->value.': '.$f->title, $result->findings);
}

function severityOf(string $id): ?Severity
{
    return runCheck($id)->findings[0]->severity ?? null;
}

function jobPayload(int $age = 0): string
{
    return json_encode(['uuid' => uniqid(), 'displayName' => 'App\\Jobs\\ShipOrder', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => [], 'createdAt' => time() - $age]);
}

function adminConnection(): array
{
    return IntegrationTestCase::connection(0, ['options' => ['prefix' => '']]);
}
