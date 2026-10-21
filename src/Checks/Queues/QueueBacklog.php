<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Queues;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\Role;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Support\Text;

/**
 * Jobs piling up: how many wait, how long the oldest has waited, and reservations nobody came back for.
 *
 * Laravel pushes to the tail of "queues:{name}" and pops from the head, so
 * LINDEX 0 is the oldest waiting job; its payload carries "createdAt".
 */
final class QueueBacklog implements Check
{
    public function id(): string
    {
        return 'queue-backlog';
    }

    public function title(): string
    {
        return 'Queue backlog';
    }

    public function category(): Category
    {
        return Category::Queues;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $findings = [];
        $horizon = $redis->usages(Role::Horizon) !== [];

        foreach ($redis->usages(Role::Queue) as $usage) {
            $connection = $redis->connection($usage->connection);

            if ($connection === null) {
                continue;
            }

            foreach ($usage->queues as $queue) {
                $key = $usage->keyPrefix.$queue;
                $pending = (int) $connection->command('LLEN', $key);
                $delayed = (int) $connection->command('ZCARD', $key.':delayed');
                $reserved = (int) $connection->command('ZCARD', $key.':reserved');
                // A reservation's score is when it expires; an expired one belongs to a worker that died or timed out.
                $abandoned = (int) $connection->command('ZCOUNT', $key.':reserved', '-inf', (string) time());
                $oldest = $pending > 0 ? $this->age($connection->command('LINDEX', $key, 0)) : null;

                $severity = $this->severity($pending, $oldest, $thresholds);

                if ($severity === null) {
                    continue;
                }

                $waited = $oldest !== null ? ' (oldest waiting '.Text::duration($oldest).')' : '';

                $findings[] = new Finding(
                    check: $this->id(),
                    category: $this->category(),
                    severity: $severity,
                    subject: "{$usage->name}:{$queue}",
                    title: number_format($pending)." jobs waiting on queue '{$queue}'{$waited}",
                    detail: "Queue connection '{$usage->name}' is taking jobs faster than its workers finish them, or no worker listens to '{$queue}'. "
                        .($abandoned > 0 ? number_format($abandoned).' reserved jobs have outlived their reservation: their workers died or hit the timeout, which points at workers that are down or crashing. ' : '')
                        .($delayed > 0 ? number_format($delayed).' more are delayed and will join the queue when due. ' : '')
                        .'Check that workers run for this queue, then whether they need more processes or faster jobs.',
                    laravel: $horizon
                        ? "php artisan horizon:status\n\n// config/horizon.php: more workers for this queue\n'environments' => [\n    'production' => [\n        'supervisor-1' => ['queue' => ['{$queue}'], 'maxProcesses' => 20, 'balance' => 'auto'],\n    ],\n],"
                        : "# a worker for this queue (run under Supervisor, more numprocs for more throughput)\nphp artisan queue:work {$usage->name} --queue={$queue}\n\n// routes/console.php: get told before it grows again\nSchedule::command('queue:monitor {$usage->name}:{$queue} --max=".$thresholds->int('backlog_warning', 1000)."')->everyMinute();",
                    evidence: array_filter([
                        'pending' => $pending,
                        'delayed' => $delayed,
                        'reserved' => $reserved,
                        'reservations_expired' => $abandoned,
                        'oldest_wait_seconds' => $oldest,
                        'key' => $key,
                    ], static fn ($v): bool => $v !== null),
                );
            }
        }

        return $findings;
    }

    private function severity(int $pending, ?int $oldest, Thresholds $t): ?Severity
    {
        if ($pending >= $t->int('backlog_critical', 10000) || ($oldest ?? 0) >= $t->int('oldest_job_critical_seconds', 3600)) {
            return Severity::Critical;
        }

        if ($pending >= $t->int('backlog_warning', 1000) || ($oldest ?? 0) >= $t->int('oldest_job_warning_seconds', 900)) {
            return Severity::Warning;
        }

        return null;
    }

    /** Seconds since the job was queued, from the payload's createdAt (Laravel 11+). */
    private function age(mixed $payload): ?int
    {
        $job = is_string($payload) ? json_decode($payload, true) : null;
        $created = is_array($job) ? ($job['createdAt'] ?? null) : null;

        return is_numeric($created) ? max(0, time() - (int) $created) : null;
    }
}
