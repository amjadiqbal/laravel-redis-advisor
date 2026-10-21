<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Support;

final class Text
{
    /**
     * ['a', 'b', 'c'] -> "a, b and c"
     *
     * @param  list<string>  $items
     */
    public static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }

    /** 90 -> "1 minute", 7200 -> "2 hours", 2592000 -> "30 days" */
    public static function duration(int $seconds): string
    {
        [$n, $unit] = match (true) {
            $seconds >= 86400 => [round($seconds / 86400, 1), 'day'],
            $seconds >= 3600 => [round($seconds / 3600, 1), 'hour'],
            $seconds >= 60 => [intdiv($seconds, 60), 'minute'],
            default => [$seconds, 'second'],
        };

        return $n.' '.$unit.((float) $n === 1.0 ? '' : 's');
    }
}
