<?php

use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    config([
        'database.redis.client' => 'predis',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 1],
    ]);
});

it('is closed outside the local environment by default', function () {
    $this->get('/redis-advisor')->assertForbidden();
});

it('is open in local and shows a readable error instead of a 500 when Redis is down', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->get('/redis-advisor')
        ->assertOk()
        ->assertSee('Could not inspect Redis.')
        ->assertSee('No Redis connection answered');
});

it('lets the app decide access through the viewRedisAdvisor gate', function () {
    Gate::define('viewRedisAdvisor', fn ($user = null) => true);

    $this->get('/redis-advisor')->assertOk();
});
