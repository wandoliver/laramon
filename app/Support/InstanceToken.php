<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Instance API tokens look like "lm_{instanceId}_{40 random chars}".
 * Legacy "ahm_" tokens are still accepted; only sha256 hashes are stored.
 */
class InstanceToken
{
    public const PREFIX = 'lm';

    public const LEGACY_PREFIX = 'ahm';

    public static function generate(int $instanceId): string
    {
        return sprintf('%s_%d_%s', self::PREFIX, $instanceId, Str::random(40));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function instanceId(string $token): ?int
    {
        $prefixes = implode('|', [self::PREFIX, self::LEGACY_PREFIX]);

        if (preg_match('/^(?:'.$prefixes.')_(\d+)_[A-Za-z0-9]{40}$/', $token, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
