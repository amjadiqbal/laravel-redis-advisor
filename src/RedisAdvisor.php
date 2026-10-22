<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor;

use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Exceptions\UnsupportedConnection;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Redis\TopologyFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RuntimeException;

/**
 * The entry point: find the app's Redis connections and run the checks on them.
 */
final readonly class RedisAdvisor
{
    public function __construct(
        private Container $container,
        private RedisFactory $redis,
        private Repository $config,
    ) {}

    /**
     * @param  list<string>  $connections  Redis connection names; empty means every one the app uses.
     * @param  list<string>  $only  Category names or check ids; empty means all.
     */
    public function report(array $connections = [], array $only = []): Report
    {
        return $this->advisor()->run($this->topology($connections), $only);
    }

    /**
     * @param  list<string>  $connections
     *
     * @throws UnsupportedConnection when there is nothing to inspect.
     * @throws RuntimeException when no connection answers.
     */
    public function topology(array $connections = []): Topology
    {
        $environment = $this->container instanceof Application ? $this->container->environment() : 'production';
        $thresholds = (array) $this->config->get('redis-advisor.thresholds', []);

        $topology = (new TopologyFactory($this->config, $this->redis, $environment))->make(
            $connections,
            max(1, (int) ($thresholds['sample_keys'] ?? 10000)),
            max(10, (int) ($thresholds['scan_count'] ?? 1000)),
        );

        if ($topology->connections() === [] && $topology->failures() === [] && $topology->notInspected() !== []) {
            throw UnsupportedConnection::onlyClusters(array_keys($topology->notInspected()));
        }

        if ($topology->connections() === [] && $topology->failures() === []) {
            throw UnsupportedConnection::nothingToInspect();
        }

        if ($topology->connections() === []) {
            $reasons = [];

            foreach ($topology->failures() as $name => $why) {
                $reasons[] = "'{$name}': {$why}";
            }

            throw new RuntimeException('No Redis connection answered. '.implode('; ', $reasons));
        }

        return $topology;
    }

    public function advisor(): Advisor
    {
        $checks = [];

        foreach ((array) $this->config->get('redis-advisor.checks', []) as $class) {
            $check = is_string($class) ? $this->container->make($class) : $class;

            if ($check instanceof Check) {
                $checks[] = $check;
            }
        }

        $ignore = array_values(array_filter((array) $this->config->get('redis-advisor.ignore_keys', []), 'is_string'));

        return new Advisor($checks, new Thresholds((array) $this->config->get('redis-advisor.thresholds', []), $ignore));
    }
}
