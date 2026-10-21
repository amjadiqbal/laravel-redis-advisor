<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Keys;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\Role;
use AmjadIqbal\RedisAdvisor\Redis\SampledKey;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Support\Bytes;
use AmjadIqbal\RedisAdvisor\Support\Keys;

/**
 * Single keys big enough to stall Redis: every read ships the whole value, and DEL blocks while it frees it.
 *
 * Measured with MEMORY USAGE on the SCAN sample; queue lists are left to queue-backlog.
 */
final class BigKeys implements Check
{
    public function id(): string
    {
        return 'big-keys';
    }

    public function title(): string
    {
        return 'Big keys';
    }

    public function category(): Category
    {
        return Category::Keys;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $findings = [];
        $notice = $thresholds->int('big_key_notice_bytes', 1024 ** 2);
        $warning = $thresholds->int('big_key_warning_bytes', 10 * 1024 ** 2);

        foreach ($redis->databases() as $db) {
            $sample = $redis->sample($db);

            if ($sample->memoryUnavailable !== null) {
                throw new CheckUnavailable($sample->memoryUnavailable, 'redis-cli --bigkeys and redis-cli --memkeys scan the whole database from your machine (SCAN-based; run them off-peak).');
            }

            $big = array_filter($sample->keys, static fn (SampledKey $k): bool => ($k->bytes ?? 0) >= $notice);
            usort($big, static fn (SampledKey $a, SampledKey $b): int => $b->bytes <=> $a->bytes);
            $cli = Keys::cli($db->server()->address, $db->database());
            $shown = 0;

            foreach ($big as $key) {
                $owner = $redis->owner($db, $key->key);

                if ($thresholds->ignores($key->key) || in_array($owner?->role, [Role::Queue, Role::Horizon], true)) {
                    continue;
                }

                if (++$shown > $thresholds->int('big_keys_max', 10)) {
                    break;
                }

                $type = strtolower((string) $db->command('TYPE', $key->key));
                $name = Keys::display($key->key);
                $arg = escapeshellarg($name);
                $cache = $owner?->role === Role::Cache;
                $tag = $cache && preg_match('~(^|:)tag:.+:entries$~', $key->key);

                $findings[] = new Finding(
                    check: $this->id(),
                    category: $this->category(),
                    severity: $key->bytes >= $warning ? Severity::Warning : Severity::Notice,
                    subject: $name,
                    title: "{$name} uses ".Bytes::human((int) $key->bytes)." ({$type})",
                    detail: 'Every read of this key sends the whole value over the network, and Redis serves nobody else while it does. '
                        .'Deleting it with DEL blocks the same way; UNLINK frees it in the background. '
                        .match (true) {
                            $tag => 'It is a cache tag\'s index of entries, which grows when tagged values expire but their tag entries stay.',
                            $cache => 'It is one cache entry ('.$owner->label().'): cache a smaller shape, such as ids or one page, instead of the whole collection.',
                            in_array($type, ['hash', 'set', 'zset', 'list', 'stream'], true) => "Split a {$type} this size into buckets (for example key:{id % 100}), or trim it as you write.",
                            default => 'Find the code that writes it and store less in one key.',
                        }
                    .($sample->complete ? '' : " Found in {$sample->describe()}; run redis-cli --memkeys for the whole database."),
                    fix: "# look before acting\n{$cli} MEMORY USAGE {$arg}\n\n"
                        .($cache ? "# drop it; the app rebuilds it on the next miss (this package does not run it)\n{$cli} UNLINK {$arg}" : "# remove it without blocking, only if the data can be rebuilt (not run)\n{$cli} UNLINK {$arg}"),
                    laravel: $tag ? 'php artisan cache:prune-stale-tags' : null,
                    evidence: ['bytes' => (int) $key->bytes, 'type' => $type, 'owner' => $owner?->label() ?? 'no Laravel store', 'ttl_seconds' => $key->expires() ? intdiv($key->ttlMs, 1000) : -1],
                );
            }
        }

        return $findings;
    }
}
