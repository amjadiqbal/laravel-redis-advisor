<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Server;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Redis\Usage;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Support\Bytes;
use AmjadIqbal\RedisAdvisor\Support\Memory;

/**
 * How full each server is, and whether it has a limit at all.
 */
final class MemoryUsage implements Check
{
    public function id(): string
    {
        return 'memory-usage';
    }

    public function title(): string
    {
        return 'Memory limit and usage';
    }

    public function category(): Category
    {
        return Category::Server;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $findings = [];

        foreach ($redis->servers() as $server) {
            $memory = Memory::of($server);
            $address = $server->server()->address;
            $durable = array_filter($redis->usagesOn($server), static fn (Usage $u): bool => $u->role->mustSurvive()) !== [];
            $policy = $durable ? 'noeviction' : 'allkeys-lru';

            if ($memory->max === 0) {
                $limit = Bytes::config(max($memory->used * 2, 256 * 1024 ** 2));

                $findings[] = new Finding(
                    check: $this->id(),
                    category: $this->category(),
                    severity: $memory->used >= $thresholds->int('no_limit_warning_bytes', 100 * 1024 ** 2) ? Severity::Warning : Severity::Notice,
                    subject: $address,
                    title: "No memory limit on {$address} (".Bytes::human($memory->used).' used)',
                    detail: 'maxmemory is 0, so Redis grows until the machine runs out of memory and the OS kills it or swaps it. '
                        .'Set a limit below the RAM you can give it, leaving room for the OS and for the copy Redis forks while it saves. '
                        .($durable
                            ? 'This server holds queues or sessions, so pair the limit with noeviction: writes fail loudly instead of jobs disappearing.'
                            : 'It only holds cache, so allkeys-lru lets it drop the least-used values when full.'),
                    fix: "# redis.conf on {$address}\nmaxmemory {$limit}\nmaxmemory-policy {$policy}\n\n# or at runtime (this package does not run it):\nCONFIG SET maxmemory {$limit}\nCONFIG SET maxmemory-policy {$policy}",
                    evidence: ['used_memory' => Bytes::human($memory->used), 'maxmemory' => 0, 'policy' => (string) $memory->policy],
                );

                continue;
            }

            $ratio = (float) $memory->ratio();

            if ($ratio < $thresholds->float('memory_warning', 0.75)) {
                continue;
            }

            $limit = Bytes::config($memory->used * 1.5);

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $ratio >= $thresholds->float('memory_critical', 0.9) ? Severity::Critical : Severity::Warning,
                subject: $address,
                title: "{$address} is at ".Bytes::percent($ratio).' of maxmemory ('.Bytes::human($memory->used).' of '.Bytes::human($memory->max).')',
                detail: $this->whatHappensWhenFull($memory)
                    .($memory->evicted > 0 ? ' '.number_format($memory->evicted).' keys have been evicted since the server started.' : '')
                    .' Raise the limit if the machine has room, or find what grew (see big-keys and keys-without-ttl).',
                fix: "# redis.conf on {$address}\nmaxmemory {$limit}\n\n# or at runtime (this package does not run it):\nCONFIG SET maxmemory {$limit}",
                evidence: ['used_memory' => Bytes::human($memory->used), 'maxmemory' => Bytes::human($memory->max), 'policy' => (string) $memory->policy, 'evicted_keys' => $memory->evicted],
            );
        }

        return $findings;
    }

    private function whatHappensWhenFull(Memory $memory): string
    {
        return match (true) {
            $memory->policy === 'noeviction' => 'With noeviction, a full server refuses writes with an OOM error: queue pushes, cache writes and session saves start throwing.',
            $memory->evictsAnyKey() => "With {$memory->policy}, a full server deletes keys to make room, whichever it picks.",
            $memory->evictsKeysWithTtl() => "With {$memory->policy}, a full server deletes keys that have a TTL (cache values, sessions) and refuses writes once none are left.",
            default => 'When it is full, the eviction policy decides what is lost.',
        };
    }
}
