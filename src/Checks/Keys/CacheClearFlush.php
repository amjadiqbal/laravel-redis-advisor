<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Keys;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\Role;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Redis\Usage;
use AmjadIqbal\RedisAdvisor\Severity;

/**
 * Queues, sessions or Horizon in the same Redis database as a cache store.
 *
 * RedisStore::flush(), which `php artisan cache:clear` calls, runs FLUSHDB: it
 * empties the whole database, not just the cache's keys. Deploy scripts run it.
 */
final class CacheClearFlush implements Check
{
    public function id(): string
    {
        return 'cache-clear-flush';
    }

    public function title(): string
    {
        return 'cache:clear spares queues and sessions';
    }

    public function category(): Category
    {
        return Category::Keys;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $findings = [];

        foreach ($redis->usages(Role::Cache) as $cache) {
            $connection = $redis->connection($cache->connection);

            if ($connection === null) {
                continue;
            }

            $victims = array_values(array_filter(
                $redis->usagesOn($connection, true),
                static fn (Usage $u): bool => $u->role->mustSurvive(),
            ));

            if ($victims === []) {
                continue;
            }

            $jobs = array_filter($victims, static fn (Usage $u): bool => in_array($u->role, [Role::Queue, Role::Horizon], true)) !== [];
            $lost = implode(' and ', array_map(static fn (Usage $u): string => $u->label(), $victims));
            $db = $connection->database();

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $jobs ? Severity::Critical : Severity::Warning,
                subject: "{$cache->connection} db{$db}",
                title: "php artisan cache:clear also deletes the {$lost}",
                detail: "The {$cache->label()} shares database {$db} on {$connection->server()->address} with the {$lost}. "
                    .'Clearing a Redis cache runs FLUSHDB, which empties the whole database rather than the cache\'s keys: '
                    .($jobs ? 'every queued and delayed job is gone, ' : '')
                    .(array_filter($victims, static fn (Usage $u): bool => $u->role === Role::Session) !== [] ? 'everyone is logged out, ' : '')
                    .'and nothing reports it. Deploy scripts often run cache:clear. '
                    .'Laravel\'s own config keeps the cache on a database of its own (the "cache" connection, REDIS_CACHE_DB=1).',
                laravel: "// config/cache.php: the redis store on its own connection\n'redis' => [\n    'driver' => 'redis',\n    'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),\n    'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),\n],\n\n"
                    ."// config/database.php, in 'redis' => [...]: a database nothing else uses\n'cache' => [\n    // host, port and password as in 'default'\n    'database' => env('REDIS_CACHE_DB', '1'),\n],\n\n"
                    ."# .env\nREDIS_CACHE_CONNECTION=cache\nREDIS_CACHE_DB=1",
                evidence: ['cache_connection' => $cache->connection, 'database' => $db, 'shared_with' => $lost],
            );
        }

        return $findings;
    }
}
