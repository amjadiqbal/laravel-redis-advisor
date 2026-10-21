<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Support;

/**
 * Key names as people read them and as redis-cli patterns.
 */
final class Keys
{
    /** Printable, safe to show: binary bytes become \xNN, long keys are shortened. */
    public static function display(string $key, int $max = 120): string
    {
        $shown = preg_replace_callback('~[^\x20-\x7E]~', static fn (array $m): string => sprintf('\x%02X', ord($m[0])), $key) ?? $key;

        return strlen($shown) > $max ? substr($shown, 0, $max - 1).'…' : $shown;
    }

    /** Escape the characters a SCAN MATCH / --pattern glob treats as special. */
    public static function glob(string $literal): string
    {
        return addcslashes($literal, '*?[]\\');
    }

    /**
     * The shape of a key, as a glob: ids, hashes, uuids and numbers become "*".
     * "app-cache-report:2024:user:81723" -> "app-cache-report:*:user:*"
     */
    public static function shape(string $key): string
    {
        $variable = '~[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\b[0-9a-f]{16,}\b|(?<![A-Za-z0-9])[A-Za-z0-9]{40}(?![A-Za-z0-9])|\d+~i';
        $parts = preg_split($variable, $key);

        if ($parts === false) {
            return self::glob($key);
        }

        $shape = implode('*', array_map(self::glob(...), $parts));

        return preg_replace('~\*{2,}~', '*', $shape) ?? $shape;
    }

    /** A redis-cli invocation for this connection: "redis-cli -h 10.0.0.5 -p 6379 -n 1". */
    public static function cli(string $address, int $database): string
    {
        if (str_starts_with($address, '/')) {
            return 'redis-cli -s '.$address.($database !== 0 ? ' -n '.$database : '');
        }

        $port = strrchr($address, ':');
        $host = $port === false ? $address : substr($address, 0, -strlen($port));

        return 'redis-cli -h '.$host.' -p '.($port === false ? '6379' : substr($port, 1)).($database !== 0 ? ' -n '.$database : '');
    }
}
