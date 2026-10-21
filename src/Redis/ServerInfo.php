<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

/**
 * Which server a connection reached, read from INFO.
 */
final readonly class ServerInfo
{
    public function __construct(
        public string $flavour,
        public string $version,
        public string $id,
        public int $uptimeSeconds,
        public string $address,
    ) {}

    /**
     * @param  array<string, string>  $info
     */
    public static function fromInfo(array $info, string $address): self
    {
        $valkey = isset($info['valkey_version']) || strtolower($info['server_name'] ?? '') === 'valkey';

        return new self(
            flavour: $valkey ? 'Valkey' : 'Redis',
            version: $valkey ? ($info['valkey_version'] ?? $info['redis_version'] ?? '?') : ($info['redis_version'] ?? '?'),
            // run_id is unique per server process; hosts that hide it fall back to the address.
            id: ($info['run_id'] ?? '') !== '' ? $info['run_id'] : $address,
            uptimeSeconds: (int) ($info['uptime_in_seconds'] ?? 0),
            address: $address,
        );
    }

    public function name(): string
    {
        return "{$this->flavour} {$this->version}";
    }

    public function uptimeForHumans(): string
    {
        $s = $this->uptimeSeconds;

        return match (true) {
            $s >= 86400 => intdiv($s, 86400).' day'.(intdiv($s, 86400) === 1 ? '' : 's'),
            $s >= 3600 => intdiv($s, 3600).' hour'.(intdiv($s, 3600) === 1 ? '' : 's'),
            default => intdiv($s, 60).' minute'.(intdiv($s, 60) === 1 ? '' : 's'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'flavour' => $this->flavour,
            'version' => $this->version,
            'address' => $this->address,
            'uptime_seconds' => $this->uptimeSeconds,
        ];
    }
}
