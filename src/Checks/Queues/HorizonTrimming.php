<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Queues;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\Role;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Severity;
use Illuminate\Contracts\Config\Repository;

/**
 * How long Horizon keeps job records, and how many it holds now.
 *
 * Horizon stores every job as a hash plus an entry in recent/pending/completed
 * sorted sets, trimmed after horizon.trim minutes. Long trims on a busy queue
 * turn Redis into a job archive.
 */
final class HorizonTrimming implements Check
{
    /** horizon.trim key => the sorted set it trims. */
    private const array SETS = [
        'recent' => 'recent_jobs',
        'pending' => 'pending_jobs',
        'completed' => 'completed_jobs',
        'failed' => 'failed_jobs',
    ];

    private const array DEFAULTS = ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080];

    public function __construct(private readonly Repository $config) {}

    public function id(): string
    {
        return 'horizon-trimming';
    }

    public function title(): string
    {
        return 'Horizon trimming and prefix';
    }

    public function category(): Category
    {
        return Category::Queues;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $usage = $redis->usages(Role::Horizon)[0] ?? null;

        if ($usage === null) {
            throw new CheckUnavailable('Horizon is not installed in this app.');
        }

        $connection = $redis->connection($usage->connection);

        if ($connection === null) {
            throw new CheckUnavailable("Horizon's Redis connection '{$usage->connection}' did not answer.");
        }

        $findings = [];
        $trim = array_merge(self::DEFAULTS, array_filter((array) $this->config->get('horizon.trim', []), 'is_numeric'));
        $jobsWarning = $thresholds->int('horizon_jobs_warning', 100000);

        foreach (self::SETS as $setting => $set) {
            $minutes = (int) $trim[$setting];
            $held = (int) $connection->command('ZCARD', $usage->keyPrefix.$set);
            $limit = $setting === 'failed' ? $thresholds->int('horizon_failed_trim_max_minutes', 43200) : $thresholds->int('horizon_trim_max_minutes', 1440);

            if ($minutes <= $limit && $held < $jobsWarning) {
                continue;
            }

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $held >= $jobsWarning && $minutes > $limit ? Severity::Warning : Severity::Notice,
                subject: "horizon.trim.{$setting}",
                title: "Horizon keeps {$setting} jobs for ".self::minutes($minutes).' ('.number_format($held).' held now)',
                detail: "Each job Horizon tracks is a hash in Redis, listed in {$set} until horizon.trim.{$setting} minutes pass. "
                    .($minutes > $limit
                        ? 'A trim this long makes Redis an archive of job payloads: memory grows with throughput, and the Horizon dashboard slows down. '
                        : 'The trim is reasonable, but at this throughput Redis still holds many job payloads; a shorter trim frees that memory. ')
                    .'Horizon\'s defaults are 60 minutes for recent, pending and completed jobs and 7 days for failed ones. Keep failed jobs as long as you need them to investigate.',
                laravel: "// config/horizon.php\n'trim' => [\n    'recent' => 60,\n    'pending' => 60,\n    'completed' => 60,\n    'recent_failed' => 10080,\n    'failed' => 10080,\n    'monitored' => 10080,\n],",
                evidence: ['trim_minutes' => $minutes, 'jobs_held' => $held, 'key' => $usage->keyPrefix.$set],
            );
        }

        if (in_array($usage->keyPrefix, ['laravel_horizon:', 'horizon:'], true)) {
            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: Severity::Notice,
                subject: 'horizon.prefix',
                title: "Horizon uses the default prefix '{$usage->keyPrefix}'",
                detail: 'Every app that never set APP_NAME or HORIZON_PREFIX uses this prefix. Two such apps on one Redis share '
                    .'Horizon\'s supervisors, metrics and dashboard, and one app\'s horizon:terminate stops the other\'s workers. '
                    .'Change it while the queues are empty: jobs Horizon tracks under the old prefix are not carried over.',
                laravel: "# .env\nHORIZON_PREFIX=your_app_horizon:",
                evidence: ['prefix' => $usage->keyPrefix],
            );
        }

        return $findings;
    }

    private static function minutes(int $minutes): string
    {
        return match (true) {
            $minutes >= 1440 && $minutes % 1440 === 0 => ($minutes / 1440).' day'.($minutes === 1440 ? '' : 's'),
            $minutes >= 60 && $minutes % 60 === 0 => ($minutes / 60).' hour'.($minutes === 60 ? '' : 's'),
            default => $minutes.' minutes',
        };
    }
}
