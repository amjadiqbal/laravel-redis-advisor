<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Server;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\Connection;
use AmjadIqbal\RedisAdvisor\Redis\Role;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Redis\Usage;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Support\Keys;

/**
 * Whether queued jobs and sessions survive a restart, and whether saving still works.
 */
final class Persistence implements Check
{
    public function id(): string
    {
        return 'persistence';
    }

    public function title(): string
    {
        return 'Persistence for queues and sessions';
    }

    public function category(): Category
    {
        return Category::Server;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $findings = [];
        $unavailable = null;

        foreach ($redis->servers() as $server) {
            $durable = array_values(array_filter($redis->usagesOn($server), static fn (Usage $u): bool => $u->role->mustSurvive()));
            $info = $server->info();
            $address = $server->server()->address;

            // Readable on every host, and the most urgent: a failed save stops writes by default.
            foreach (['rdb_last_bgsave_status' => 'RDB snapshot', 'aof_last_write_status' => 'AOF write'] as $field => $what) {
                if (isset($info[$field]) && strtolower($info[$field]) !== 'ok') {
                    $findings[] = new Finding(
                        check: $this->id(),
                        category: $this->category(),
                        severity: Severity::Critical,
                        subject: $address,
                        title: "The last {$what} on {$address} failed",
                        detail: 'With stop-writes-on-bgsave-error yes (the default), Redis refuses every write until a save succeeds, so queue pushes, cache writes and logins fail. '
                            .'The usual causes are a full disk, a directory Redis cannot write to, or too little memory to fork. Check the Redis log.',
                        fix: "{$this->cli($address)} INFO persistence\n{$this->cli($address)} CONFIG GET dir\ndf -h   # on the Redis host",
                        evidence: [$field => $info[$field]],
                    );
                }
            }

            if ($durable === []) {
                continue;
            }

            try {
                $off = $this->persistenceOff($server);
            } catch (CheckUnavailable $e) {
                $unavailable ??= $e;

                continue;
            }

            if ($off === null) {
                continue;
            }

            $jobs = array_filter($durable, static fn (Usage $u): bool => in_array($u->role, [Role::Queue, Role::Horizon], true)) !== [];
            $what = implode(' and ', array_map(static fn (Usage $u): string => $u->label(), $durable));

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $jobs ? Severity::Critical : Severity::Warning,
                subject: $address,
                title: "{$address} holds the {$what} but {$off}",
                detail: 'A restart, a crash or a host replacement starts Redis empty: '
                    .($jobs ? 'every queued and delayed job is lost without a trace' : 'everyone is logged out')
                    .'. The append-only file with fsync every second loses at most a second of writes. '
                    .'On ElastiCache or MemoryDB, use Multi-AZ with automatic failover and backups instead (AOF is not offered there).',
                fix: "# redis.conf on {$address}\nappendonly yes\nappendfsync everysec\n\n# or at runtime, then save it to redis.conf (this package does not run these):\nCONFIG SET appendonly yes\nCONFIG REWRITE",
                evidence: ['holds' => $what],
            );
        }

        if ($findings === [] && $unavailable !== null) {
            throw $unavailable;
        }

        return $findings;
    }

    /**
     * Why nothing is saved, or null when something is.
     *
     * @throws CheckUnavailable when neither CONFIG nor INFO can tell.
     */
    private function persistenceOff(Connection $server): ?string
    {
        $info = $server->info();

        if (($info['aof_enabled'] ?? '0') === '1') {
            return null;
        }

        try {
            $save = $server->setting('save');
        } catch (CheckUnavailable $e) {
            // Without CONFIG: no snapshot since start, on a server that has been up a while and has changes, says enough.
            $uptime = $server->server()->uptimeSeconds;

            if (isset($info['rdb_saves']) && (int) $info['rdb_saves'] === 0 && $uptime >= 3600 && (int) ($info['rdb_changes_since_last_save'] ?? 0) > 0) {
                return 'has taken no snapshot since it started '.$server->server()->uptimeForHumans().' ago, and AOF is off';
            }

            throw new CheckUnavailable(
                $e->getMessage().' INFO alone cannot tell whether snapshots are configured.',
                $e->howToEnable ?? "Check snapshot and backup settings in your provider's console.",
            );
        }

        return trim((string) $save) === '' ? 'persistence is off (no RDB snapshots, no AOF)' : null;
    }

    private function cli(string $address): string
    {
        return Keys::cli($address, 0);
    }
}
