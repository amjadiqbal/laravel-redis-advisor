<?php

namespace AmjadIqbal\RedisAdvisor\Tests\Fakes;

use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\Connection;
use AmjadIqbal\RedisAdvisor\Redis\ReadOnlyGuard;
use AmjadIqbal\RedisAdvisor\Redis\ServerInfo;
use RuntimeException;

final class FakeConnection implements Connection
{
    /** @var list<string> Every command sent, for assertions. */
    public array $sent = [];

    public function __construct(
        private FakeServer $server,
        private string $name,
        private int $db,
        private string $prefix,
        private string $address,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function client(): string
    {
        return 'fake';
    }

    public function database(): int
    {
        return $this->db;
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
        $this->sent[] = strtoupper($command);
        $keys = $this->server->databases[$this->db] ?? [];
        $key = (string) ($arguments[0] ?? '');

        return match (strtoupper($command)) {
            'DBSIZE' => count($keys),
            'SCAN' => ['0', array_values(array_filter(array_keys($keys), fn (string $k): bool => ! in_array('MATCH', $arguments, true)
                || fnmatch((string) $arguments[array_search('MATCH', $arguments, true) + 1], $k)))],
            'PTTL' => isset($keys[$key]) ? $keys[$key]['ttl'] : -2,
            'TYPE' => $keys[$key]['type'] ?? 'none',
            'MEMORY' => $this->server->memoryDisabled
                ? throw new CheckUnavailable('This server does not allow MEMORY USAGE.')
                : ($keys[(string) $arguments[1]]['bytes'] ?? null),
            'LLEN' => count($keys[$key]['list'] ?? []),
            'LINDEX' => $keys[$key]['list'][(int) $arguments[1]] ?? null,
            'ZCARD' => count($keys[$key]['zset'] ?? []),
            'ZCOUNT' => count(array_filter($keys[$key]['zset'] ?? [], fn (float $s): bool => $s <= (float) $arguments[2])),
            'INFO' => '',
            default => throw new RuntimeException("FakeConnection does not implement {$command}"),
        };
    }

    public function info(): array
    {
        return $this->server->info;
    }

    public function setting(string $parameter, ?string $infoField = null): ?string
    {
        if ($infoField !== null && array_key_exists($infoField, $this->server->info)) {
            return $this->server->info[$infoField];
        }

        if ($this->server->config === null) {
            throw new CheckUnavailable('This server does not allow CONFIG GET.', "Read the value in your provider's console.");
        }

        return $this->server->config[$parameter] ?? null;
    }

    public function server(): ServerInfo
    {
        return ServerInfo::fromInfo($this->server->info, $this->address);
    }
}
