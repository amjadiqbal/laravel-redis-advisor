<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor;

use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Redis\Usage;
use DateTimeImmutable;

/**
 * Everything one run of the advisor produced, plus what it looked at.
 */
final readonly class Report
{
    /**
     * @param  array{servers: list<array<string, mixed>>, unreachable: array<string, string>}  $redis
     * @param  list<CheckResult>  $results
     */
    public function __construct(
        public array $redis,
        public array $results,
        public DateTimeImmutable $generatedAt = new DateTimeImmutable,
    ) {}

    /**
     * Which servers were inspected, through which connections, holding what.
     *
     * @return array{servers: list<array<string, mixed>>, unreachable: array<string, string>}
     */
    public static function describe(Topology $redis): array
    {
        $servers = [];

        foreach ($redis->servers() as $server) {
            $connections = [];

            foreach ($redis->connections() as $c) {
                if ($c->server()->id !== $server->server()->id) {
                    continue;
                }

                $connections[] = [
                    'name' => $c->name(),
                    'database' => $c->database(),
                    'client' => $c->client(),
                    'holds' => array_map(
                        static fn (Usage $u): string => $u->label(),
                        array_values(array_filter($redis->usages(), static fn (Usage $u): bool => $u->connection === $c->name())),
                    ),
                ];
            }

            $servers[] = [...$server->server()->toArray(), 'name' => $server->server()->name(), 'uptime' => $server->server()->uptimeForHumans(), 'connections' => $connections];
        }

        return ['servers' => $servers, 'unreachable' => $redis->failures()];
    }

    /**
     * Every finding, worst first, then in the order the checks ran.
     *
     * @return list<Finding>
     */
    public function findings(?Category $category = null): array
    {
        $findings = [];

        foreach ($this->results as $result) {
            if ($category !== null && $result->category !== $category) {
                continue;
            }

            array_push($findings, ...$result->findings);
        }

        // usort is stable since PHP 8.0, so equal severities keep check order.
        usort($findings, static fn (Finding $a, Finding $b): int => $b->severity->rank() <=> $a->severity->rank());

        return $findings;
    }

    /**
     * @return list<CheckResult>
     */
    public function skipped(): array
    {
        return array_values(array_filter($this->results, static fn (CheckResult $r): bool => $r->wasSkipped()));
    }

    /**
     * @return list<CheckResult>
     */
    public function passed(): array
    {
        return array_values(array_filter($this->results, static fn (CheckResult $r): bool => $r->passed()));
    }

    public function count(Severity $severity): int
    {
        return count(array_filter($this->findings(), static fn (Finding $f): bool => $f->severity === $severity));
    }

    public function worst(): ?Severity
    {
        return $this->findings()[0]->severity ?? null;
    }

    /**
     * Whether a CI run should fail: any finding at or above the given severity.
     */
    public function failsAt(?Severity $threshold): bool
    {
        if ($threshold === null) {
            return false;
        }

        $worst = $this->worst();

        return $worst !== null && $worst->atLeast($threshold);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->format(DATE_ATOM),
            'servers' => $this->redis['servers'],
            'unreachable' => $this->redis['unreachable'],
            'summary' => [
                'critical' => $this->count(Severity::Critical),
                'warning' => $this->count(Severity::Warning),
                'notice' => $this->count(Severity::Notice),
                'checks_run' => count($this->results) - count($this->skipped()),
                'checks_skipped' => count($this->skipped()),
            ],
            'checks' => array_map(static fn (CheckResult $r): array => $r->toArray(), $this->results),
        ];
    }
}
