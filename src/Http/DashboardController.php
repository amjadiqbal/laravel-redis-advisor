<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Http;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\RedisAdvisor;
use AmjadIqbal\RedisAdvisor\RedisAdvisorServiceProvider;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Throwable;

final class DashboardController
{
    public function __invoke(Request $request, RedisAdvisor $advisor, Factory $views): View
    {
        $category = Category::tryFrom((string) $request->query('category', ''));
        $only = $category !== null ? [$category->value] : [];

        try {
            $report = $advisor->report([], $only);
            $error = null;
        } catch (Throwable $e) {
            // Nothing could be inspected (no Redis in use, every server down): say so instead of a 500.
            $report = null;
            $error = $e->getMessage();
        }

        return $views->make('redis-advisor::dashboard', [
            'report' => $report,
            'error' => $error,
            'category' => $category?->value,
            'categories' => Category::cases(),
            'version' => RedisAdvisorServiceProvider::version(),
        ]);
    }
}
