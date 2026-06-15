<?php

namespace App\Support;

/**
 * Splits the configured app name into a plain part and an accent part for
 * the two-tone wordmark: "Acme Monitor" → Acme + Monitor,
 * "LaraMon" → Lara + Mon (trailing CamelCase hump), "Monitor" → no accent.
 */
class Brand
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function parts(?string $name = null): array
    {
        $name = trim($name ?? (string) config('app.name'));

        if (str_contains($name, ' ')) {
            $position = (int) strrpos($name, ' ');

            return [substr($name, 0, $position + 1), substr($name, $position + 1)];
        }

        if (preg_match('/^(.+?)([A-Z][a-z0-9]+)$/', $name, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [$name, ''];
    }
}
