<?php

namespace AmjadIqbal\RedisAdvisor\Tests\Integration;

use AmjadIqbal\RedisAdvisor\Tests\TestCase;

/**
 * Runs against a real Redis or Valkey. Start one with scripts/integration.sh, or point
 * REDIS_ADVISOR_TEST_HOST / _PORT / _CLIENT (phpredis or predis) at your own.
 *
 * The app is configured the way a typical Laravel app is: cache on one connection,
 * queue and sessions on another. Tests change that config to set up each problem.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('REDIS_ADVISOR_TEST_PORT')) {
            $this->markTestSkipped('Set REDIS_ADVISOR_TEST_PORT (see scripts/integration.sh) to run against a real Redis or Valkey.');
        }

        if (self::client() === 'phpredis' && ! extension_loaded('redis')) {
            $this->markTestSkipped('The phpredis extension is not loaded in this PHP.');
        }

        parent::setUp();
    }

    public static function client(): string
    {
        return getenv('REDIS_ADVISOR_TEST_CLIENT') ?: 'predis';
    }

    /**
     * @return array<string, mixed>
     */
    public static function connection(int $database, array $extra = []): array
    {
        return array_merge([
            'host' => getenv('REDIS_ADVISOR_TEST_HOST') ?: '127.0.0.1',
            'port' => (int) getenv('REDIS_ADVISOR_TEST_PORT'),
            'database' => $database,
        ], $extra);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis', [
            'client' => self::client(),
            'options' => ['prefix' => 'shop-database-'],
            'default' => self::connection(0),
            'cache' => self::connection(1),
        ]);
        $app['config']->set('cache.prefix', 'shop-cache-');
        $app['config']->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default']);
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90]);
        $app['config']->set('session.driver', 'redis');
        $app['config']->set('session.lifetime', 120);
    }
}
