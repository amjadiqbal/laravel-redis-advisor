<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Console;

use AmjadIqbal\RedisAdvisor\CheckResult;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\RedisAdvisor;
use AmjadIqbal\RedisAdvisor\Report;
use AmjadIqbal\RedisAdvisor\Severity;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'redis-advisor:report')]
final class ReportCommand extends Command
{
    /** Exit code when findings reach --fail-on. */
    public const FINDINGS = 1;

    /** Exit code when Redis could not be inspected at all. */
    public const UNAVAILABLE = 2;

    protected $signature = 'redis-advisor:report
        {--connection=* : Redis connections to inspect (default: every one the app uses)}
        {--only=* : Limit to categories (queues, keys, server, sessions) or check ids}
        {--fail-on=critical : Exit 1 when a finding is at least this severe: critical, warning, notice or never}
        {--format=text : text or json}';

    protected $description = 'Check the app\'s Redis or Valkey for queue, key, memory, persistence and session problems (read-only)';

    public function handle(RedisAdvisor $advisor): int
    {
        $failOn = strtolower((string) $this->option('fail-on'));
        $threshold = $failOn === 'never' ? null : Severity::tryFrom($failOn);

        if ($threshold === null && $failOn !== 'never') {
            $this->error("--fail-on must be critical, warning, notice or never, not '{$failOn}'.");

            return self::INVALID;
        }

        try {
            $report = $advisor->report($this->list('connection'), $this->list('only'));
        } catch (Throwable $e) {
            $this->error('Could not inspect Redis: '.$e->getMessage());

            return self::UNAVAILABLE;
        }

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        } else {
            $this->render($report);
        }

        return $report->failsAt($threshold) ? self::FINDINGS : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $values = [];

        foreach ((array) $this->option($option) as $value) {
            array_push($values, ...array_filter(array_map('trim', explode(',', (string) $value))));
        }

        return $values;
    }

    private function render(Report $report): void
    {
        $this->newLine();
        $this->line('  <options=bold>Redis Advisor</>');

        foreach ($report->redis['servers'] as $server) {
            $connections = implode(', ', array_map(
                static fn (array $c): string => "{$c['name']} (db{$c['database']}".($c['holds'] !== [] ? ': '.implode(', ', $c['holds']) : '').')',
                $server['connections'],
            ));

            $this->line("  {$server['name']} · <options=bold>{$server['address']}</> · up {$server['uptime']} · {$connections}");
        }

        foreach ($report->redis['unreachable'] as $name => $why) {
            $this->line("  <fg=red>Connection '{$name}' did not answer:</> {$why}");
        }

        $findings = $report->findings();

        foreach ($findings as $i => $finding) {
            $this->finding($i + 1, $finding);
        }

        $skipped = $report->skipped();

        if ($skipped !== []) {
            $this->newLine();
            $this->line('  <options=bold>Skipped</>');

            foreach ($skipped as $result) {
                $this->skipped($result);
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '  <fg=red>%d critical</> · <fg=yellow>%d warning</> · <fg=cyan>%d notice</> · %d checks passed · %d skipped',
            $report->count(Severity::Critical),
            $report->count(Severity::Warning),
            $report->count(Severity::Notice),
            count($report->passed()),
            count($skipped),
        ));
        $this->line('  <fg=gray>Read-only: nothing above was run. Review each fix before applying it.</>');
        $this->newLine();
    }

    private function finding(int $n, Finding $f): void
    {
        $color = match ($f->severity) {
            Severity::Critical => 'red',
            Severity::Warning => 'yellow',
            Severity::Notice => 'cyan',
        };

        $this->newLine();
        $this->line("  <fg={$color};options=bold>".strtoupper($f->severity->value)."</> <fg=gray>{$f->check}</>  <options=bold>{$n}. ".$this->escape($f->title).'</>');
        $this->line('     '.wordwrap($this->escape($f->detail), 110, "\n     "));

        if ($f->fix !== null) {
            $this->line('     <fg=gray>Fix (Redis):</>');
            $this->line('       <fg=green>'.str_replace("\n", "\n       ", $this->escape($f->fix)).'</>');
        }

        if ($f->laravel !== null) {
            $this->line('     <fg=gray>Fix (Laravel):</>');
            $this->line('       <fg=green>'.str_replace("\n", "\n       ", $this->escape($f->laravel)).'</>');
        }
    }

    private function skipped(CheckResult $r): void
    {
        $this->line("   - {$r->title}: <fg=gray>".$this->escape((string) $r->skippedBecause).'</>');

        if ($r->howToEnable !== null) {
            $this->line('     <fg=gray>To enable:</> '.str_replace("\n", "\n                ", $this->escape($r->howToEnable)));
        }
    }

    /** Key names and fixes can contain "<", which the console would read as a style tag. */
    private function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }
}
