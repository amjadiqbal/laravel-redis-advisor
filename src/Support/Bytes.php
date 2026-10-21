<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Support;

final class Bytes
{
    /** 1536 -> "1.5 KB" */
    public static function human(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while (abs($bytes) >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $bytes : rtrim(rtrim(number_format($bytes, 1), '0'), '.')).' '.$units[$i];
    }

    /** A redis.conf size at least $bytes, rounded up to a tidy step: "768mb", "2gb". */
    public static function config(int|float $bytes): string
    {
        $mb = max(256, (int) ceil($bytes / 1024 ** 2 / 256) * 256);

        return $mb % 1024 === 0 ? ($mb / 1024).'gb' : $mb.'mb';
    }

    public static function percent(float $ratio, int $decimals = 0): string
    {
        return number_format($ratio * 100, $decimals).'%';
    }
}
