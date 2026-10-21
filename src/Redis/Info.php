<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Redis;

/**
 * Parses the text INFO returns.
 */
final class Info
{
    /**
     * @return array<string, string>
     */
    public static function parse(string $raw): array
    {
        $fields = [];

        foreach (preg_split('~\r?\n~', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || ! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $fields[strtolower($key)] = $value;
        }

        return $fields;
    }

    /**
     * "keys=3,expires=1,avg_ttl=0" -> ['keys' => 3, 'expires' => 1, 'avg_ttl' => 0]
     *
     * @return array<string, int>
     */
    public static function keyspace(?string $line): array
    {
        $values = [];

        foreach (explode(',', (string) $line) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $values[$k] = (int) $v;
            }
        }

        return $values;
    }
}
