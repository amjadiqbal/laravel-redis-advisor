<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

use AmjadIqbal\RedisAdvisor\Exceptions\UnsupportedConnection;
use Illuminate\Redis\Connections\Connection as IlluminateConnection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Predis\ClientInterface as PredisClient;
use Throwable;

/**
 * A Laravel Redis connection, read through raw commands so that phpredis and
 * predis behave the same: no key prefix, no serializer, replies as Redis sent them.
 */
final class LaravelConnection implements Connection
{
    /** @var array<string, string>|null */
    private ?array $info = null;

    /** @var array<string, string|null> */
    private array $config = [];

    private ?ServerInfo $server = null;

    public function __construct(
        private readonly IlluminateConnection $connection,
        private readonly string $name,
        private readonly int $database = 0,
        private readonly string $prefix = '',
        private readonly string $address = '',
        private readonly ?string $username = null,
    ) {
        if ($connection instanceof PhpRedisClusterConnection || $connection instanceof PredisClusterConnection) {
            throw UnsupportedConnection::cluster($name);
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function client(): string
    {
        return $this->connection->client() instanceof \Redis ? 'phpredis' : 'predis';
    }

    public function database(): int
    {
        return $this->database;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function address(): string
    {
        return $this->address;
    }

    public function command(string $command, string|int ...$arguments): mixed
    {
        ReadOnlyGuard::assertReadOnly($command, array_values($arguments));

        $client = $this->connection->client();

        try {
            if ($client instanceof \Redis) {
                $client->clearLastError();
                // Without OPT_REPLY_LITERAL phpredis turns status replies ("string", "OK") into true.
                $literal = $client->getOption(\Redis::OPT_REPLY_LITERAL);
                $client->setOption(\Redis::OPT_REPLY_LITERAL, true);

                try {
                    $reply = $client->rawCommand($command, ...array_values($arguments));
                } finally {
                    $client->setOption(\Redis::OPT_REPLY_LITERAL, $literal);
                }

                $error = $client->getLastError();

                if ($reply === false && $error !== null) {
                    $client->clearLastError();

                    throw $this->refused($command, $arguments, $error);
                }

                // phpredis returns false for a nil reply.
                return $reply === false ? null : $reply;
            }

            if ($client instanceof PredisClient && method_exists($client, 'executeRaw')) {
                $error = false;
                $reply = $client->executeRaw([$command, ...array_map('strval', array_values($arguments))], $error);

                // executeRaw sets $error by reference.
                if ($error) { // @phpstan-ignore if.alwaysFalse
                    throw $this->refused($command, $arguments, (string) $reply);
                }

                return $reply;
            }
        } catch (CheckUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            if (self::isRefusal($e->getMessage())) {
                throw $this->refused($command, $arguments, $e->getMessage());
            }

            throw $e;
        }

        throw UnsupportedConnection::client($this->name, get_debug_type($client));
    }

    public function info(): array
    {
        return $this->info ??= Info::parse((string) $this->command('INFO'));
    }

    public function setting(string $parameter, ?string $infoField = null): ?string
    {
        if ($infoField !== null && array_key_exists($infoField, $this->info())) {
            return $this->info()[$infoField];
        }

        if (! array_key_exists($parameter, $this->config)) {
            $reply = $this->command('CONFIG', 'GET', $parameter);
            $pairs = is_array($reply) ? array_values($reply) : [];

            // [name, value] from RESP2; some clients return [name => value].
            $this->config[$parameter] = match (true) {
                isset($pairs[1]) => (string) $pairs[1],
                is_array($reply) && isset($reply[$parameter]) => (string) $reply[$parameter],
                default => null,
            };
        }

        return $this->config[$parameter];
    }

    public function server(): ServerInfo
    {
        return $this->server ??= ServerInfo::fromInfo($this->info(), $this->address);
    }

    /** Whether an error means "not allowed" or "no such command", which skips a check instead of failing it. */
    public static function isRefusal(string $message): bool
    {
        return (bool) preg_match('~unknown command|NOPERM|no permissions|not allowed|unsupported command|is not supported|command is disabled~i', $message);
    }

    /**
     * @param  array<int|string, string|int>  $arguments
     */
    private function refused(string $command, array $arguments, string $error): CheckUnavailable
    {
        $what = strtoupper(trim($command.' '.(in_array(strtoupper($command), ['CONFIG', 'MEMORY', 'OBJECT'], true) ? (string) (array_values($arguments)[0] ?? '') : '')));
        $error = trim(preg_replace('~^(ERR|NOPERM)\s+~', '', $error) ?? $error);

        if (stripos($error, 'NOPERM') !== false || stripos($error, 'permission') !== false) {
            $acl = strtolower(str_replace(' ', '|', $what));

            return new CheckUnavailable(
                "The Redis user may not run {$what}: {$error}",
                'ACL SETUSER '.($this->username ?? 'default')." +{$acl}",
            );
        }

        return new CheckUnavailable(
            "This server does not allow {$what} ({$error}). Managed services such as Amazon ElastiCache, MemoryDB and Upstash switch some commands off.",
            $what === 'CONFIG GET'
                ? "Read the value in your provider's console instead (ElastiCache and MemoryDB: the cluster's parameter group)."
                : null,
        );
    }
}
