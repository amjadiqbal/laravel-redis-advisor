<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

/**
 * The app's Redis as Laravel uses it: which connections reach which server
 * and database, and what each one holds (cache, queues, sessions, Horizon).
 *
 * Two connections are the same server when INFO reports the same run_id,
 * however differently their hosts are spelled.
 */
final class Topology
{
    /** @var array<string, KeySample> */
    private array $samples = [];

    /**
     * @param  list<Connection>  $connections  The ones that answered.
     * @param  list<Usage>  $usages
     * @param  array<string, string>  $failures  Connection name => why it could not be reached.
     */
    public function __construct(
        private readonly array $connections,
        private readonly array $usages,
        private readonly array $failures = [],
        private readonly int $sampleLimit = 1000,
        private readonly int $scanCount = 1000,
    ) {}

    /**
     * @return list<Connection>
     */
    public function connections(): array
    {
        return $this->connections;
    }

    public function connection(string $name): ?Connection
    {
        foreach ($this->connections as $connection) {
            if ($connection->name() === $name) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * @return list<Usage>
     */
    public function usages(?Role $role = null): array
    {
        return array_values(array_filter($this->usages, static fn (Usage $u): bool => $role === null || $u->role === $role));
    }

    /**
     * @return array<string, string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * One connection per server.
     *
     * @return list<Connection>
     */
    public function servers(): array
    {
        return $this->unique(static fn (Connection $c): string => $c->server()->id);
    }

    /**
     * One connection per server and database.
     *
     * @return list<Connection>
     */
    public function databases(): array
    {
        return $this->unique(static fn (Connection $c): string => $c->server()->id.'/'.$c->database());
    }

    /**
     * The usages that live on the same server as $connection, or on the same database when $sameDatabase.
     *
     * @return list<Usage>
     */
    public function usagesOn(Connection $connection, bool $sameDatabase = false): array
    {
        return array_values(array_filter($this->usages, function (Usage $u) use ($connection, $sameDatabase): bool {
            $other = $this->connection($u->connection);

            return $other !== null
                && $other->server()->id === $connection->server()->id
                && (! $sameDatabase || $other->database() === $connection->database());
        }));
    }

    /**
     * The names of every connection on the same server, for messages.
     *
     * @return list<string>
     */
    public function namesOn(Connection $server): array
    {
        return array_values(array_map(
            static fn (Connection $c): string => $c->name(),
            array_filter($this->connections, static fn (Connection $c): bool => $c->server()->id === $server->server()->id),
        ));
    }

    public function sample(Connection $connection): KeySample
    {
        return $this->samples[$connection->server()->id.'/'.$connection->database()] ??= KeySample::take($connection, $this->sampleLimit, $this->scanCount);
    }

    /**
     * Which usage a raw key belongs to, judged by its prefix.
     */
    public function owner(Connection $database, string $key): ?Usage
    {
        $usages = $this->usagesOn($database, true);

        foreach ($usages as $usage) {
            // Session ids are 40 letters and digits, stored under the cache store's prefix.
            if ($usage->role === Role::Session && preg_match('~^'.preg_quote($usage->keyPrefix, '~').'[A-Za-z0-9]{40}$~', $key)) {
                return $usage;
            }
        }

        usort($usages, static fn (Usage $a, Usage $b): int => strlen($b->keyPrefix) <=> strlen($a->keyPrefix));

        foreach ($usages as $usage) {
            if ($usage->role !== Role::Session && str_starts_with($key, $usage->keyPrefix)) {
                return $usage;
            }
        }

        return null;
    }

    /**
     * @param  callable(Connection): string  $identity
     * @return list<Connection>
     */
    private function unique(callable $identity): array
    {
        $seen = [];

        foreach ($this->connections as $connection) {
            $seen[$identity($connection)] ??= $connection;
        }

        return array_values($seen);
    }
}
