<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Sessions;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\Role;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Support\Bytes;
use AmjadIqbal\RedisAdvisor\Support\Keys;
use AmjadIqbal\RedisAdvisor\Support\Memory;
use Illuminate\Contracts\Config\Repository;

/**
 * Session keys' TTLs against SESSION_LIFETIME, and whether memory pressure can log people out.
 *
 * Laravel writes each session with a TTL of session.lifetime minutes, so a
 * longer TTL means some server or worker runs with a different lifetime.
 */
final class SessionTtl implements Check
{
    public function __construct(private readonly Repository $config) {}

    public function id(): string
    {
        return 'session-ttl';
    }

    public function title(): string
    {
        return 'Session TTL matches SESSION_LIFETIME';
    }

    public function category(): Category
    {
        return Category::Sessions;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $usage = $redis->usages(Role::Session)[0] ?? null;

        if ($usage === null) {
            throw new CheckUnavailable("Sessions are not stored in Redis (SESSION_DRIVER is '".(string) $this->config->get('session.driver', 'file')."').");
        }

        $connection = $redis->connection($usage->connection);

        if ($connection === null) {
            throw new CheckUnavailable("The session connection '{$usage->connection}' did not answer.");
        }

        $lifetime = max(1, (int) $this->config->get('session.lifetime', 120)) * 60;
        $slack = $thresholds->int('session_ttl_slack_seconds', 60);
        $pattern = '~^'.preg_quote($usage->keyPrefix, '~').'[A-Za-z0-9]{40}$~';
        $limit = $thresholds->int('sample_keys', 1000);
        $cursor = '0';
        $sessions = $forever = $longer = 0;
        $longest = 0;

        do {
            $reply = $connection->command('SCAN', $cursor, 'MATCH', Keys::glob($usage->keyPrefix).'*', 'COUNT', $thresholds->int('scan_count', 1000));
            $cursor = (string) ($reply[0] ?? '0');

            foreach ((array) ($reply[1] ?? []) as $key) {
                if ($sessions >= $limit || ! preg_match($pattern, (string) $key)) {
                    continue;
                }

                $ttl = (int) $connection->command('PTTL', (string) $key);

                if ($ttl === -2) {
                    continue;
                }

                $sessions++;
                $forever += $ttl === -1 ? 1 : 0;

                if ($ttl > ($lifetime + $slack) * 1000) {
                    $longer++;
                    $longest = max($longest, intdiv($ttl, 1000));
                }
            }
        } while ($cursor !== '0' && $sessions < $limit);

        $findings = [];
        $minutes = intdiv($lifetime, 60);

        if ($forever > 0) {
            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: Severity::Warning,
                subject: 'sessions',
                title: number_format($forever).' sessions never expire',
                detail: 'Laravel always stores a session with a TTL, so something else wrote or PERSISTed these. They stay logged in for good and are never cleaned up.',
                fix: "# give them the normal lifetime (this package does not run it)\n".Keys::cli($connection->server()->address, $connection->database())
                    .' --scan --pattern '.escapeshellarg(Keys::glob($usage->keyPrefix).'*').' | while read -r key; do '
                    .Keys::cli($connection->server()->address, $connection->database())." EXPIRE \"\$key\" {$lifetime} > /dev/null; done",
                evidence: ['sessions_sampled' => $sessions, 'without_ttl' => $forever],
            );
        }

        if ($longer > 0) {
            $share = $longer / max(1, $sessions);

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $share >= 0.1 ? Severity::Warning : Severity::Notice,
                subject: 'session.lifetime',
                title: number_format($longer)." sessions outlive SESSION_LIFETIME ({$minutes} minutes): up to ".intdiv($longest, 60).' minutes left',
                detail: 'Each request saves the session with a TTL of session.lifetime. A longer TTL means a server, worker or old deploy writes sessions with a longer lifetime than this one: '
                    .'people stay logged in longer than you set, or get logged out at different times depending on the server that answered. '
                    .'If you lowered SESSION_LIFETIME recently, idle sessions keep their old TTL until they expire, and this clears up by itself.',
                laravel: "# the same value on every web server and worker, then rebuild the cached config\nSESSION_LIFETIME={$minutes}\n\nphp artisan config:cache",
                evidence: ['sessions_sampled' => $sessions, 'longer_than_lifetime' => $longer, 'lifetime_seconds' => $lifetime, 'longest_ttl_seconds' => $longest],
            );
        }

        try {
            $memory = Memory::of($connection);
        } catch (CheckUnavailable) {
            return $findings;
        }

        if ($memory->max > 0 && $memory->policy !== null && $memory->policy !== 'noeviction') {
            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: Severity::Notice,
                subject: 'sessions',
                title: "Sessions can be evicted under memory pressure (maxmemory-policy {$memory->policy})",
                detail: 'When this server reaches maxmemory, '.$memory->policy.' deletes keys'.($memory->evictsKeysWithTtl() ? ' that have a TTL, which every session has' : '')
                    .', and the user behind each deleted session is logged out without warning. Memory is at '.Bytes::percent((float) $memory->ratio())
                    .($memory->evicted > 0 ? ', and '.number_format($memory->evicted).' keys have been evicted since start' : '')
                    .'. Keep sessions on a server with noeviction, or one with plenty of room.',
                laravel: "# .env: sessions on a connection to a Redis with maxmemory-policy noeviction\nSESSION_CONNECTION=sessions",
                evidence: ['policy' => $memory->policy, 'evicted_keys' => $memory->evicted],
            );
        }

        return $findings;
    }
}
