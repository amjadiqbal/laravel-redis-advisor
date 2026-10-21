<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\Topology;

/**
 * One question asked of the app's Redis servers. Register your own in config('redis-advisor.checks').
 */
interface Check
{
    /** Stable kebab-case id, used in config, JSON output and --only. */
    public function id(): string;

    public function title(): string;

    public function category(): Category;

    /**
     * @return list<Finding>
     *
     * @throws CheckUnavailable when the server cannot answer; the check is reported as skipped.
     */
    public function run(Topology $redis, Thresholds $thresholds): array;
}
