<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Exceptions;

use InvalidArgumentException;

final class UnsupportedConnection extends InvalidArgumentException
{
    public static function cluster(string $connection): self
    {
        return new self("The Redis connection '{$connection}' is a cluster. laravel-redis-advisor inspects one server at a time: add a connection to one node (or the primary) and list it in redis-advisor.connections.");
    }

    public static function client(string $connection, string $class): self
    {
        return new self("The Redis connection '{$connection}' uses a client this package does not know ({$class}). Use phpredis or predis.");
    }

    /**
     * @param  list<string>  $connections
     */
    public static function onlyClusters(array $connections): self
    {
        return new self('Nothing to inspect: '.implode(', ', array_map(static fn (string $c): string => "'{$c}'", $connections)).' '.(count($connections) === 1 ? 'is a Redis Cluster connection' : 'are Redis Cluster connections')
            .', which laravel-redis-advisor does not inspect. Add a connection to one node in config/database.php and set REDIS_ADVISOR_CONNECTIONS to it.');
    }

    public static function nothingToInspect(): self
    {
        return new self('No Redis connection to inspect: none of the cache stores, queue connections or the session driver use Redis, and redis-advisor.connections is empty. Set REDIS_ADVISOR_CONNECTIONS (comma-separated connection names from config/database.php).');
    }
}
