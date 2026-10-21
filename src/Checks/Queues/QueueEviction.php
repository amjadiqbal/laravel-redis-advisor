<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Queues;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\Role;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Redis\Usage;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Support\Bytes;
use AmjadIqbal\RedisAdvisor\Support\Memory;

/**
 * Queued jobs on a server whose eviction policy may delete any key.
 *
 * Under allkeys-lru (or -lfu, -random) Redis frees memory by deleting whatever
 * it picks, the lists that hold queued jobs included. Nothing reports it: the
 * job simply never runs. A cache on the same server is what fills it up.
 */
final class QueueEviction implements Check
{
    public function id(): string
    {
        return 'queue-eviction';
    }

    public function title(): string
    {
        return 'Queues safe from eviction';
    }

    public function category(): Category
    {
        return Category::Queues;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $findings = [];

        foreach ($redis->servers() as $server) {
            $usages = $redis->usagesOn($server);
            $jobs = array_values(array_filter($usages, static fn (Usage $u): bool => in_array($u->role, [Role::Queue, Role::Horizon], true)));

            if ($jobs === []) {
                continue;
            }

            $memory = Memory::of($server);

            if ($memory->policy === null) {
                throw new CheckUnavailable('The server reports no maxmemory-policy.');
            }

            if (! $memory->evictsAnyKey()) {
                continue;
            }

            $caches = array_values(array_filter($usages, static fn (Usage $u): bool => $u->role === Role::Cache));
            $address = $server->server()->address;
            $what = implode(' and ', array_map(static fn (Usage $u): string => $u->label(), $jobs));
            $ratio = $memory->ratio();

            if ($memory->max === 0) {
                $findings[] = new Finding(
                    check: $this->id(),
                    category: $this->category(),
                    severity: Severity::Notice,
                    subject: $address,
                    title: "maxmemory-policy is {$memory->policy} on the server holding {$what}",
                    detail: 'Nothing is evicted today because maxmemory is 0 (no limit). The day someone sets a limit, '
                        ."{$memory->policy} lets Redis delete any key to make room, queued jobs included, without an error. "
                        .'Set the policy to noeviction now, while it changes nothing.',
                    fix: $this->redisFix($address),
                    evidence: ['policy' => $memory->policy, 'maxmemory' => 0],
                );

                continue;
            }

            $pressure = $memory->evicted > 0 || ($ratio !== null && $ratio >= $thresholds->float('memory_warning', 0.75));

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $caches !== [] || $pressure ? Severity::Critical : Severity::Warning,
                subject: $address,
                title: "Queued jobs on {$address} can be evicted (maxmemory-policy {$memory->policy})",
                detail: "This server holds {$what}. Under {$memory->policy}, when memory reaches maxmemory "
                    .'Redis deletes any key it picks to make room, including the lists that hold queued jobs. '
                    .'No exception is thrown and no job is marked failed: the job just never runs. '
                    .($caches !== []
                        ? 'The '.implode(' and ', array_map(static fn (Usage $u): string => $u->label(), $caches)).' fills the same memory, so cache growth is what triggers it. '
                        : '')
                    .($memory->evicted > 0 ? number_format($memory->evicted).' keys have already been evicted since the server started. ' : '')
                    .'Memory is at '.Bytes::human($memory->used).' of '.Bytes::human($memory->max).' ('.Bytes::percent((float) $ratio).'). '
                    .'Queues need noeviction; a cache-only Redis is where allkeys-lru belongs. '
                    ."On ElastiCache or MemoryDB, change maxmemory-policy in the cluster's parameter group.",
                fix: $this->redisFix($address),
                laravel: $caches !== [] ? $this->separateCache() : null,
                evidence: [
                    'policy' => $memory->policy,
                    'used_memory' => Bytes::human($memory->used),
                    'maxmemory' => Bytes::human($memory->max),
                    'evicted_keys' => $memory->evicted,
                    'connections' => implode(', ', $redis->namesOn($server)),
                ],
            );
        }

        return $findings;
    }

    private function redisFix(string $address): string
    {
        return "# redis.conf on {$address}\n"
            ."maxmemory-policy noeviction\n\n"
            ."# or at runtime (this package does not run it):\n"
            .'CONFIG SET maxmemory-policy noeviction';
    }

    private function separateCache(): string
    {
        return "# Give the cache its own Redis (with maxmemory-policy allkeys-lru), in .env:\n"
            ."REDIS_CACHE_HOST=10.0.0.12\n\n"
            ."// config/database.php, in 'redis' => [...]\n"
            ."'cache' => [\n"
            ."    'host' => env('REDIS_CACHE_HOST', env('REDIS_HOST', '127.0.0.1')),\n"
            ."    'port' => env('REDIS_CACHE_PORT', env('REDIS_PORT', '6379')),\n"
            ."    'password' => env('REDIS_CACHE_PASSWORD', env('REDIS_PASSWORD')),\n"
            ."    'database' => env('REDIS_CACHE_DB', '1'),\n"
            .'],';
    }
}
