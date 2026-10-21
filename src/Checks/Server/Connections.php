<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Server;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Redis\Usage;
use AmjadIqbal\RedisAdvisor\Severity;

/**
 * Every Redis connection the app relies on answers.
 */
final class Connections implements Check
{
    public function id(): string
    {
        return 'connections';
    }

    public function title(): string
    {
        return 'Every Redis connection answers';
    }

    public function category(): Category
    {
        return Category::Server;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $findings = [];

        foreach ($redis->failures() as $name => $why) {
            $holds = array_values(array_filter($redis->usages(), static fn (Usage $u): bool => $u->connection === $name));
            $critical = array_filter($holds, static fn (Usage $u): bool => $u->role->mustSurvive()) !== [];
            $labels = implode(', ', array_map(static fn (Usage $u): string => $u->label(), $holds));

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $critical ? Severity::Critical : Severity::Warning,
                subject: $name,
                title: "The Redis connection '{$name}' did not answer",
                detail: ($labels !== '' ? "It holds {$labels}. " : '')
                    ."The error was: {$why}. Its checks were not run. "
                    .'Check the host, port, password and TLS settings of this connection in config/database.php, '
                    .'and that this machine can reach the server.',
                laravel: "php artisan tinker --execute=\"Illuminate\\Support\\Facades\\Redis::connection('{$name}')->ping();\"",
                evidence: ['connection' => $name, 'error' => $why],
            );
        }

        return $findings;
    }
}
