<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks;

/**
 * The numbers each check compares against, from config('redis-advisor.thresholds').
 */
final readonly class Thresholds
{
    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $ignoreKeys  Glob patterns of raw keys no key check reports on.
     */
    public function __construct(private array $values = [], public array $ignoreKeys = []) {}

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default): float
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    public function ignores(string $key): bool
    {
        foreach ($this->ignoreKeys as $pattern) {
            if (fnmatch($pattern, $key, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }
}
