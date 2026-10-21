<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor;

use AmjadIqbal\RedisAdvisor\Console\ReportCommand;
use AmjadIqbal\RedisAdvisor\Http\Authorize;
use AmjadIqbal\RedisAdvisor\Http\DashboardController;
use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class RedisAdvisorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/redis-advisor.php', 'redis-advisor');
        $this->app->singleton(RedisAdvisor::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'redis-advisor');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/redis-advisor.php' => config_path('redis-advisor.php')], 'redis-advisor-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/redis-advisor')], 'redis-advisor-views');
            $this->commands([ReportCommand::class]);
        }

        // Local only until the app defines its own gate (app providers boot after this one, so theirs wins).
        if (! Gate::has('viewRedisAdvisor')) {
            Gate::define('viewRedisAdvisor', fn ($user = null): bool => $this->app->environment('local'));
        }

        if (config('redis-advisor.dashboard.enabled')) {
            $this->registerRoutes();
        }

        AboutCommand::add('Redis Advisor', fn (): array => [
            'Version' => self::version(),
            'Dashboard' => config('redis-advisor.dashboard.enabled') ? '/'.trim((string) config('redis-advisor.dashboard.path'), '/') : 'off',
            'Support' => 'https://amjad.com.pk/contact?utm_source=artisan&utm_medium=about&utm_campaign=laravel-redis-advisor',
        ]);
    }

    private function registerRoutes(): void
    {
        $middleware = array_values(array_filter((array) config('redis-advisor.dashboard.middleware', ['web']), 'is_string'));

        Route::group([
            'prefix' => trim((string) config('redis-advisor.dashboard.path', 'redis-advisor'), '/'),
            'domain' => config('redis-advisor.dashboard.domain'),
            'middleware' => [...$middleware, Authorize::class],
        ], function (): void {
            Route::get('/', DashboardController::class)->name('redis-advisor.dashboard');
        });
    }

    public static function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('amjadiqbal/laravel-redis-advisor') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}
