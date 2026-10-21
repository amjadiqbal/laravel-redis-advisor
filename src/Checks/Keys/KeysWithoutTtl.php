<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Keys;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\Connection;
use AmjadIqbal\RedisAdvisor\Redis\Role;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Support\Keys;
use AmjadIqbal\RedisAdvisor\Support\Memory;

/**
 * Keys that never expire: Cache::forever(), rememberForever() and raw SETs.
 *
 * Queue and Horizon keys are left out: they are meant to persist.
 */
final class KeysWithoutTtl implements Check
{
    public function id(): string
    {
        return 'keys-without-ttl';
    }

    public function title(): string
    {
        return 'Keys without a TTL';
    }

    public function category(): Category
    {
        return Category::Keys;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $findings = [];

        foreach ($redis->databases() as $db) {
            $sample = $redis->sample($db);
            $considered = 0;
            $groups = [];

            foreach ($sample->keys as $key) {
                $owner = $redis->owner($db, $key->key);

                if ($thresholds->ignores($key->key) || in_array($owner?->role, [Role::Queue, Role::Horizon], true) || str_ends_with($key->key, 'illuminate:queue:restart')) {
                    continue;
                }

                $considered++;

                if (! $key->expires()) {
                    $shape = Keys::shape($key->key);
                    $groups[$shape] ??= ['count' => 0, 'owner' => $owner?->label() ?? 'no Laravel store', 'cache' => $owner?->role === Role::Cache];
                    $groups[$shape]['count']++;
                }
            }

            $inSample = array_sum(array_column($groups, 'count'));
            $estimated = $sample->estimate($inSample);
            $share = $considered > 0 ? $inSample / $considered : 0.0;

            if ($estimated < $thresholds->int('no_ttl_min_keys', 1000) || $share < $thresholds->float('no_ttl_min_share', 0.1)) {
                continue;
            }

            uasort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $top = array_slice($groups, 0, 5, true);
            [$severity, $why] = $this->consequence($db);
            $first = (string) array_key_first($top);
            $pattern = escapeshellarg($first);
            $cli = Keys::cli($db->server()->address, $db->database());

            $list = [];

            foreach ($top as $shape => $group) {
                $list[] = "{$shape} (~".number_format($sample->estimate($group['count'])).", {$group['owner']})";
            }

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $severity,
                subject: $db->name().' db'.$db->database(),
                title: '~'.number_format($estimated).' keys with no TTL in db '.$db->database().' on '.$db->server()->address.' ('.round($share * 100).'% of keys)',
                detail: "{$why} The largest groups: ".implode('; ', $list).". Counted in {$sample->describe()}; queue and Horizon keys are left out.",
                fix: "# list them (SCAN-based, safe on a live server)\n{$cli} --scan --pattern {$pattern} | head -50\n\n"
                    ."# give the existing ones a day to live (this package does not run it)\n{$cli} --scan --pattern {$pattern} | while read -r key; do {$cli} EXPIRE \"\$key\" 86400 > /dev/null; done",
                laravel: $top[$first]['cache']
                    ? "// Give cached values a lifetime instead of forever:\nCache::put(\$key, \$value, now()->addDay());              // not Cache::forever()\nCache::remember(\$key, now()->addHour(), fn () => ...);    // not Cache::rememberForever()"
                    : "// Find the code that writes '{$first}' and set an expiry with the value:\nRedis::set(\$key, \$value, 'EX', 86400);",
                evidence: [
                    'sampled' => $sample->size(),
                    'without_ttl_in_sample' => $inSample,
                    'estimated_without_ttl' => $estimated,
                    'keys_in_db' => $sample->total,
                ],
            );
        }

        return $findings;
    }

    /**
     * @return array{0: Severity, 1: string}
     */
    private function consequence(Connection $db): array
    {
        try {
            $memory = Memory::of($db);
        } catch (CheckUnavailable) {
            return [Severity::Notice, 'Keys without a TTL stay until something deletes them.'];
        }

        return match (true) {
            $memory->evictsKeysWithTtl() => [Severity::Warning, "Under maxmemory-policy {$memory->policy} Redis only evicts keys that have a TTL, so these can never be evicted: when memory fills, writes start failing."],
            $memory->max > 0 && $memory->policy === 'noeviction' => [Severity::Warning, 'With noeviction nothing is ever freed for them: they stay until memory is full and writes start failing.'],
            $memory->max === 0 => [Severity::Notice, 'With no maxmemory they stay forever and memory only grows.'],
            default => [Severity::Notice, "Under {$memory->policy} they are evicted when memory runs short, but they hold memory that values with a lifetime would free."],
        };
    }
}
