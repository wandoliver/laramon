<?php

namespace App\Support;

/**
 * The dashboard's shared time-range vocabulary.
 */
class TimeRange
{
    public const RANGES = [
        '1h' => 3600,
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ];

    public static function seconds(string $range): int
    {
        return self::RANGES[$range] ?? self::RANGES['24h'];
    }

    public static function valid(string $range): string
    {
        return array_key_exists($range, self::RANGES) ? $range : '24h';
    }
}
