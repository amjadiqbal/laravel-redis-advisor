<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor;

/**
 * What one check did: ran and found things, ran and found nothing, or could not run.
 */
final readonly class CheckResult
{
    /**
     * @param  list<Finding>  $findings
     */
    private function __construct(
        public string $check,
        public string $title,
        public Category $category,
        public array $findings,
        public ?string $skippedBecause = null,
        public ?string $howToEnable = null,
    ) {}

    /**
     * @param  list<Finding>  $findings
     */
    public static function ran(string $check, string $title, Category $category, array $findings): self
    {
        return new self($check, $title, $category, $findings);
    }

    public static function skipped(string $check, string $title, Category $category, string $because, ?string $howToEnable = null): self
    {
        return new self($check, $title, $category, [], $because, $howToEnable);
    }

    public function wasSkipped(): bool
    {
        return $this->skippedBecause !== null;
    }

    public function passed(): bool
    {
        return ! $this->wasSkipped() && $this->findings === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'check' => $this->check,
            'title' => $this->title,
            'category' => $this->category->value,
            'status' => $this->wasSkipped() ? 'skipped' : ($this->passed() ? 'passed' : 'findings'),
            'skipped_because' => $this->skippedBecause,
            'how_to_enable' => $this->howToEnable,
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
