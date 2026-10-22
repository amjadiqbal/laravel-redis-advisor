<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

use AmjadIqbal\RedisAdvisor\Exceptions\UnsupportedConnection;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection as IlluminateConnection;
use Illuminate\Support\ConfigurationUrlParser;
use Laravel\Horizon\Horizon;
use Throwable;

/**
 * Reads the app's config to learn what it keeps in Redis, then connects to each Redis connection involved.
 */
final readonly class TopologyFactory
{
    public function __construct(private Repository $config, private RedisFactory $redis, private string $environment = 'production') {}

    /**
     * @param  list<string>  $only  Connection names to inspect; empty means every one the app uses.
     */
    public function make(array $only = [], int $sampleLimit = 1000, int $scanCount = 1000): Topology
    {
        $usages = $this->usages();
        $names = $only !== [] ? $only : $this->configuredConnections();

        if ($names === []) {
            $names = array_values(array_unique(array_map(static fn (Usage $u): string => $u->connection, $usages)));
        }

        $connections = [];
        $failures = [];
        $notInspected = [];

        foreach ($names as $name) {
            // Laravel resolves database.redis.<name> first and clusters.<name> second; a cluster is never connected to.
            if ($this->config->get("database.redis.{$name}") === null && $this->config->get("database.redis.clusters.{$name}") !== null) {
                $notInspected[$name] = 'a Redis Cluster connection, not inspected: add a connection to one node and list it in REDIS_ADVISOR_CONNECTIONS';

                continue;
            }

            try {
                $connection = $this->connect($name);
                $connection->server(); // INFO: proves it answers

                $connections[] = $connection;
            } catch (UnsupportedConnection $e) {
                $notInspected[$name] = $e->getMessage();
            } catch (Throwable $e) {
                $failures[$name] = trim(strtok($e->getMessage(), "\n") ?: $e->getMessage());
            }
        }

        return new Topology($connections, $usages, $failures, $sampleLimit, $scanCount, $notInspected);
    }

    public function connect(string $name): Connection
    {
        /** @var IlluminateConnection $client */
        $client = $this->redis->connection($name);

        $config = (array) $this->config->get("database.redis.{$name}", []);
        $parsed = (new ConfigurationUrlParser)->parseConfiguration($config);
        $host = (string) ($parsed['host'] ?? '127.0.0.1');
        $address = str_starts_with($host, '/') ? $host : $host.':'.($parsed['port'] ?? 6379);

        return new LaravelConnection(
            connection: $client,
            name: $name,
            database: (int) ($parsed['database'] ?? 0),
            prefix: $this->prefix($name),
            address: $address,
            username: isset($parsed['username']) && $parsed['username'] !== '' ? (string) $parsed['username'] : null,
        );
    }

    /**
     * @return list<Usage>
     */
    public function usages(): array
    {
        $usages = [];

        foreach ((array) $this->config->get('cache.stores', []) as $name => $store) {
            if (is_array($store) && ($store['driver'] ?? null) === 'redis') {
                $connection = (string) ($store['connection'] ?? 'default');
                $usages[] = new Usage(Role::Cache, (string) $name, $connection, $this->prefix($connection).$this->cachePrefix((string) $name));
            }
        }

        foreach ((array) $this->config->get('queue.connections', []) as $name => $queue) {
            if (is_array($queue) && ($queue['driver'] ?? null) === 'redis') {
                $connection = (string) ($queue['connection'] ?? 'default');
                $queues = array_values(array_unique([(string) ($queue['queue'] ?? 'default'), ...$this->horizonQueues((string) $name)]));
                $usages[] = new Usage(Role::Queue, (string) $name, $connection, $this->prefix($connection).'queues:', $queues);
            }
        }

        if ($this->config->get('session.driver') === 'redis') {
            // SessionManager: the cache store's RedisStore, switched to session.connection (null = default).
            $connection = (string) ($this->config->get('session.connection') ?? 'default');
            $store = (string) ($this->config->get('session.store') ?: 'redis');
            $prefix = (string) ($this->config->get('session.prefix') ?: $this->cachePrefix($store));
            $usages[] = new Usage(Role::Session, 'session', $connection, $this->prefix($connection).$prefix);
        }

        if (class_exists(Horizon::class) && is_array($this->config->get('horizon'))) {
            // Horizon copies horizon.use into a "horizon" connection whose prefix is horizon.prefix.
            $connection = $this->config->get('database.redis.horizon') !== null ? 'horizon' : (string) ($this->config->get('horizon.use') ?? 'default');
            $usages[] = new Usage(Role::Horizon, 'horizon', $connection, (string) ($this->config->get('horizon.prefix') ?: 'horizon:'));
        }

        return $usages;
    }

    /** The key prefix Laravel adds on this connection: its own options.prefix, else the shared one. */
    public function prefix(string $connection): string
    {
        $own = $this->config->get("database.redis.{$connection}.options.prefix");

        return (string) ($own ?? $this->config->get('database.redis.options.prefix') ?? '');
    }

    private function cachePrefix(string $store): string
    {
        return (string) ($this->config->get("cache.stores.{$store}.prefix") ?? $this->config->get('cache.prefix') ?? '');
    }

    /**
     * @return list<string>
     */
    private function configuredConnections(): array
    {
        $value = $this->config->get('redis-advisor.connections');
        $names = is_string($value) ? explode(',', $value) : (array) $value;

        return array_values(array_filter(array_map(static fn ($n): string => trim((string) $n), $names)));
    }

    /**
     * The queues Horizon's supervisors work on this queue connection, in this environment.
     *
     * @return list<string>
     */
    private function horizonQueues(string $queueConnection): array
    {
        if (! class_exists(Horizon::class)) {
            return [];
        }

        $defaults = (array) $this->config->get('horizon.defaults', []);
        $environment = (array) ($this->config->get('horizon.environments.'.$this->environment) ?? $this->config->get('horizon.environments.*') ?? []);
        $queues = [];

        foreach (array_unique([...array_keys($defaults), ...array_keys($environment)]) as $supervisor) {
            $settings = array_merge((array) ($defaults[$supervisor] ?? []), (array) ($environment[$supervisor] ?? []));

            if (($settings['connection'] ?? 'redis') === $queueConnection) {
                array_push($queues, ...array_map('strval', (array) ($settings['queue'] ?? ['default'])));
            }
        }

        return $queues;
    }
}
