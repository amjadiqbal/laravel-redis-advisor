<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

/**
 * Read access to one Laravel Redis connection (one server, one database).
 */
interface Connection
{
    /** The connection name from config/database.php, e.g. "cache". */
    public function name(): string;

    /** "phpredis" or "predis". */
    public function client(): string;

    /** The database number this connection selects. */
    public function database(): int;

    /** The key prefix Laravel adds to every key on this connection (options.prefix). */
    public function prefix(): string;

    /** Where it points, for people: host:port. */
    public function address(): string;

    /**
     * Run one read command, raw: no key prefix is added and no value is unserialized.
     *
     * @throws CheckUnavailable when the server refuses or does not have the command.
     */
    public function command(string $command, string|int ...$arguments): mixed;

    /**
     * Several read commands in one round trip. A command the server rejects
     * comes back as null; probe it with command() first when that matters.
     *
     * @param  list<list<string|int>>  $commands  Each: [command, ...arguments].
     * @return list<mixed>
     */
    public function pipeline(array $commands): array;

    /**
     * Every field of INFO, flattened ("used_memory" => "1048576", "db1" => "keys=3,expires=1,avg_ttl=0").
     *
     * @return array<string, string>
     */
    public function info(): array;

    /**
     * A server setting: from INFO when it is there (works on every host), else CONFIG GET.
     *
     * @throws CheckUnavailable when INFO lacks it and CONFIG is disabled or not allowed.
     */
    public function setting(string $parameter, ?string $infoField = null): ?string;

    public function server(): ServerInfo;
}
