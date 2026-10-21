<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

use LogicException;

/**
 * Refuses any command that is not on a short list of reads.
 *
 * Every command the package sends goes through Connection::command(), and that
 * passes through here first, so "never writes" is a property of one list you
 * can read rather than a promise spread across every check. KEYS is not on it:
 * key checks walk the keyspace with SCAN, in small batches.
 */
final class ReadOnlyGuard
{
    /** Commands allowed with any arguments. */
    private const array READS = [
        'INFO', 'PING', 'DBSIZE', 'SCAN', 'TYPE', 'TTL', 'PTTL', 'EXISTS', 'LASTSAVE', 'TIME',
        'LLEN', 'LINDEX', 'ZCARD', 'ZCOUNT', 'HLEN', 'SCARD', 'XLEN', 'STRLEN',
    ];

    /** Commands allowed only with these subcommands. */
    private const array SUBCOMMANDS = [
        'CONFIG' => ['GET'],
        'MEMORY' => ['USAGE', 'STATS'],
        'OBJECT' => ['ENCODING', 'FREQ', 'IDLETIME'],
    ];

    /**
     * @param  list<string|int>  $arguments
     */
    public static function assertReadOnly(string $command, array $arguments = []): void
    {
        $command = strtoupper(trim($command));

        if (in_array($command, self::READS, true)) {
            return;
        }

        $sub = strtoupper((string) ($arguments[0] ?? ''));

        if (isset(self::SUBCOMMANDS[$command]) && in_array($sub, self::SUBCOMMANDS[$command], true)) {
            return;
        }

        throw new LogicException('laravel-redis-advisor only runs read commands. Refused: '.trim($command.' '.$sub));
    }
}
