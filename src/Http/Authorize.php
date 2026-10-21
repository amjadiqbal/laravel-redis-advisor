<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Gate::check('viewRedisAdvisor', [$request]), 403);

        return $next($request);
    }
}
