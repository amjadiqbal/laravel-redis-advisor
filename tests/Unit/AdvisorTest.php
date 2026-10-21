<?php

use AmjadIqbal\RedisAdvisor\Advisor;
use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\CheckUnavailable;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Tests\Fakes\FakeServer;

function fakeTopology(): Topology
{
    return new Topology([(new FakeServer)->connect()], []);
}

function check(string $id, Category $category, Closure $run): Check
{
    return new class($id, $category, $run) implements Check
    {
        public function __construct(private string $checkId, private Category $cat, private Closure $body) {}

        public function id(): string
        {
            return $this->checkId;
        }

        public function title(): string
        {
            return ucfirst($this->checkId);
        }

        public function category(): Category
        {
            return $this->cat;
        }

        public function run(Topology $redis, Thresholds $thresholds): array
        {
            return ($this->body)();
        }
    };
}

function finding(string $check, Severity $severity): Finding
{
    return new Finding($check, Category::Keys, $severity, 'subject', 'title', 'detail');
}

it('sorts findings worst first and keeps running after a check fails', function () {
    $advisor = new Advisor([
        check('a', Category::Keys, fn () => [finding('a', Severity::Notice)]),
        check('b', Category::Server, fn () => throw new RuntimeException('boom')),
        check('c', Category::Sessions, fn () => throw new CheckUnavailable('no privilege', 'GRANT ...')),
        check('d', Category::Queues, fn () => [finding('d', Severity::Critical), finding('d', Severity::Warning)]),
        check('e', Category::Queues, fn () => []),
    ], new Thresholds);

    $report = $advisor->run(fakeTopology());

    expect(array_map(fn (Finding $f) => $f->severity, $report->findings()))->toBe([Severity::Critical, Severity::Warning, Severity::Notice])
        ->and(array_map(fn ($r) => $r->check, $report->skipped()))->toBe(['b', 'c'])
        ->and($report->skipped()[0]->skippedBecause)->toBe('The check failed: boom')
        ->and($report->skipped()[1]->howToEnable)->toBe('GRANT ...')
        ->and(array_map(fn ($r) => $r->check, $report->passed()))->toBe(['e']);
});

it('decides the CI exit by the worst finding', function () {
    $report = (new Advisor([check('a', Category::Keys, fn () => [finding('a', Severity::Warning)])], new Thresholds))->run(fakeTopology());

    expect($report->failsAt(Severity::Critical))->toBeFalse()
        ->and($report->failsAt(Severity::Warning))->toBeTrue()
        ->and($report->failsAt(Severity::Notice))->toBeTrue()
        ->and($report->failsAt(null))->toBeFalse();
});

it('limits a run to categories or check ids', function () {
    $advisor = new Advisor([
        check('a', Category::Keys, fn () => []),
        check('b', Category::Server, fn () => []),
        check('c', Category::Queues, fn () => []),
    ], new Thresholds);

    expect(array_map(fn ($r) => $r->check, $advisor->run(fakeTopology(), ['server', 'c'])->results))->toBe(['b', 'c']);
});

it('serialises to a stable JSON shape', function () {
    $report = (new Advisor([check('a', Category::Keys, fn () => [finding('a', Severity::Critical)])], new Thresholds))->run(fakeTopology());

    expect($report->toArray())->toHaveKeys(['generated_at', 'servers', 'unreachable', 'summary', 'checks'])
        ->and($report->toArray()['summary'])->toMatchArray(['critical' => 1, 'warning' => 0, 'checks_run' => 1, 'checks_skipped' => 0])
        ->and($report->toArray()['checks'][0]['findings'][0])->toHaveKeys(['check', 'severity', 'fix', 'laravel', 'evidence']);
});
