<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor;

/**
 * One problem the advisor found, why it matters, and how to fix it.
 *
 * The fix is text for a person to read. Nothing in this package executes it.
 */
final readonly class Finding
{
    /**
     * @param  string  $check  The id of the check that produced it, e.g. "queue-eviction".
     * @param  string  $subject  What it is about: a server, a connection, a key or a key pattern.
     * @param  string  $title  One line, shown as the headline.
     * @param  string  $detail  Why it matters and what to double-check before acting.
     * @param  string|null  $fix  The redis.conf line or Redis command that would fix it, if any.
     * @param  string|null  $laravel  The same fix in Laravel terms: .env, config or code.
     * @param  array<string, string|int|float>  $evidence  The numbers the decision was based on.
     */
    public function __construct(
        public string $check,
        public Category $category,
        public Severity $severity,
        public string $subject,
        public string $title,
        public string $detail,
        public ?string $fix = null,
        public ?string $laravel = null,
        public array $evidence = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'check' => $this->check,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'subject' => $this->subject,
            'title' => $this->title,
            'detail' => $this->detail,
            'fix' => $this->fix,
            'laravel' => $this->laravel,
            'evidence' => $this->evidence,
        ];
    }
}
