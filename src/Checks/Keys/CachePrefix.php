<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Keys;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\Connection;
use AmjadIqbal\RedisAdvisor\Redis\Role;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Redis\Usage;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Support\Keys;
use AmjadIqbal\RedisAdvisor\Support\Text;

/**
 * Two apps, or two stores, writing the same keys.
 *
 * Laravel derives its Redis and cache prefixes from APP_NAME. Apps that never
 * set it all use "laravel_database_" / "laravel-database-", so on a shared
 * Redis they read each other's cache and run each other's jobs.
 */
final class CachePrefix implements Check
{
    private const array DEFAULT_REDIS_PREFIXES = ['laravel_database_', 'laravel-database-'];

    public function id(): string
    {
        return 'cache-prefix';
    }

    public function title(): string
    {
        return 'Key prefixes do not collide';
    }

    public function category(): Category
    {
        return Category::Keys;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $findings = [];

        foreach ($redis->databases() as $db) {
            $foreign = $this->foreignPrefixes($redis, $db, $thresholds);
            $usages = $redis->usagesOn($db, true);

            foreach ($foreign as $prefix => $count) {
                $findings[] = new Finding(
                    check: $this->id(),
                    category: $this->category(),
                    severity: Severity::Warning,
                    subject: "{$db->name()} db{$db->database()}",
                    title: "Another app writes to db {$db->database()} on {$db->server()->address}: keys starting '".Keys::display($prefix)."'",
                    detail: 'About '.number_format($redis->sample($db)->estimate($count))." keys here do not belong to this app's stores. "
                        .'Sharing one database means a cache:clear in either app empties both, and one prefix typo away, they read each other\'s cache. '
                        .'Give each app its own database number (or its own Redis). '
                        .'If you changed APP_NAME or REDIS_PREFIX recently, these may be this app\'s own keys from before the change: '
                        .'delete them once the queued jobs under the old prefix have run.',
                    laravel: $this->ownDatabase(),
                    evidence: ['foreign_prefix' => $prefix, 'keys_in_sample' => $count],
                );
            }

            $byConnection = [];

            foreach ($usages as $usage) {
                $connection = $redis->connection($usage->connection);

                if ($connection !== null && in_array($connection->prefix(), self::DEFAULT_REDIS_PREFIXES, true)) {
                    $byConnection[$connection->name()][] = $usage;
                }
            }

            foreach ($byConnection as $name => $affected) {
                $prefix = (string) $redis->connection($name)?->prefix();
                $labels = Text::list(array_map(static fn (Usage $u): string => $u->label(), $affected));

                $findings[] = new Finding(
                    check: $this->id(),
                    category: $this->category(),
                    severity: $foreign !== [] ? Severity::Warning : Severity::Notice,
                    subject: $name,
                    title: "Keys on the '{$name}' connection use Laravel's default prefix '{$prefix}'",
                    detail: "It holds the {$labels}. APP_NAME was never set, so the prefix is the framework default, which every other app that skipped it shares: "
                        .'on one Redis database they read each other\'s cache and work each other\'s queued jobs. '
                        .'Before changing REDIS_PREFIX, let the queues drain: queued jobs stay under the old prefix, and sessions there are dropped, so everyone logs in again.',
                    laravel: "# .env\nAPP_NAME=\"Your App\"\nREDIS_PREFIX=your-app-database-\nCACHE_PREFIX=your-app-cache-",
                    evidence: ['prefix' => $prefix],
                );
            }

            $seen = [];

            foreach ($usages as $usage) {
                if ($usage->role !== Role::Cache) {
                    continue;
                }

                if (isset($seen[$usage->keyPrefix])) {
                    $findings[] = new Finding(
                        check: $this->id(),
                        category: $this->category(),
                        severity: Severity::Warning,
                        subject: $usage->label(),
                        title: "The {$seen[$usage->keyPrefix]} and the {$usage->label()} write the same keys",
                        detail: "Both use database {$db->database()} with the prefix '".Keys::display($usage->keyPrefix)."', so a value one stores overwrites the other's under the same key, and clearing one clears both.",
                        laravel: "// config/cache.php: give the {$usage->name} store its own prefix\n'{$usage->name}' => [\n    'driver' => 'redis',\n    'connection' => '{$usage->connection}',\n    'prefix' => '{$usage->name}-',\n],",
                        evidence: ['prefix' => $usage->keyPrefix],
                    );
                }

                $seen[$usage->keyPrefix] ??= $usage->label();
            }
        }

        return $findings;
    }

    /**
     * Laravel-style prefixes in the sample that none of this app's connections or stores use.
     *
     * @return array<string, int> prefix => keys in the sample
     */
    private function foreignPrefixes(Topology $redis, Connection $db, Thresholds $thresholds): array
    {
        $own = array_filter([
            ...array_map(static fn (Usage $u): string => $u->keyPrefix, $redis->usagesOn($db, true)),
            ...array_map(static fn (Connection $c): string => $c->prefix(), array_filter(
                $redis->connections(),
                static fn (Connection $c): bool => $c->server()->id === $db->server()->id && $c->database() === $db->database(),
            )),
        ], static fn (string $p): bool => $p !== '');

        $sample = $redis->sample($db);
        $groups = [];

        foreach ($sample->keys as $key) {
            if ($thresholds->ignores($key->key)) {
                continue;
            }

            foreach ($own as $prefix) {
                if (str_starts_with($key->key, $prefix)) {
                    continue 2;
                }
            }

            if (preg_match('~^(.+?[-_](?:database|cache)[-_]|.+?_horizon:)~', $key->key, $m)) {
                $groups[$m[1]] = ($groups[$m[1]] ?? 0) + 1;
            }
        }

        $min = max(3, (int) ceil($sample->size() * 0.01));

        return array_filter($groups, static fn (int $n): bool => $n >= $min);
    }

    private function ownDatabase(): string
    {
        return "# .env of this app: databases nothing else on this Redis uses\nREDIS_DB=2\nREDIS_CACHE_DB=3\n\n"
            .'# Move only after the queues drain: jobs and sessions stay in the old database.';
    }
}
