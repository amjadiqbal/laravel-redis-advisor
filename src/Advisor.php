<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor;

use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use Throwable;

/**
 * Runs every check against the app's Redis and collects the results.
 *
 * A check that throws is reported as skipped with its error; it never takes the
 * report (or the dashboard) down with it.
 */
final readonly class Advisor
{
    /**
     * @param  list<Check>  $checks
     */
    public function __construct(private array $checks, private Thresholds $thresholds) {}

    /**
     * @param  list<string>  $only  Category values or check ids; empty means everything.
     */
    public function run(Topology $redis, array $only = []): Report
    {
        $results = [];

        foreach ($this->checks as $check) {
            if ($only !== [] && ! in_array($check->id(), $only, true) && ! in_array($check->category()->value, $only, true)) {
                continue;
            }

            try {
                $results[] = CheckResult::ran($check->id(), $check->title(), $check->category(), $check->run($redis, $this->thresholds));
            } catch (CheckUnavailable $e) {
                $results[] = CheckResult::skipped($check->id(), $check->title(), $check->category(), $e->getMessage(), $e->howToEnable);
            } catch (Throwable $e) {
                $results[] = CheckResult::skipped($check->id(), $check->title(), $check->category(), 'The check failed: '.$e->getMessage());
            }
        }

        return new Report(Report::describe($redis), $results);
    }

    /**
     * @return list<Check>
     */
    public function checks(): array
    {
        return $this->checks;
    }
}
