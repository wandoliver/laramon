<?php

namespace App\Models;

use App\Support\InstanceToken;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instance extends Model
{
    /** @use HasFactory<\Database\Factories\InstanceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'base_url',
        'environment',
        'token_hash',
        'previous_token_hash',
        'previous_token_expires_at',
        'last_heartbeat_at',
        'last_ingest_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'previous_token_expires_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_ingest_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function metricBuckets(): HasMany
    {
        return $this->hasMany(MetricBucket::class);
    }

    public function exceptionGroups(): HasMany
    {
        return $this->hasMany(ExceptionGroup::class);
    }

    /**
     * Resolve and verify a bearer token. Returns the instance only when the
     * token matches the current hash, or the previous hash within its
     * rotation grace window.
     */
    public static function authenticateToken(?string $token): ?self
    {
        if ($token === null) {
            return null;
        }

        $instanceId = InstanceToken::instanceId($token);

        if ($instanceId === null) {
            return null;
        }

        $instance = static::find($instanceId);

        if ($instance === null) {
            return null;
        }

        $hash = InstanceToken::hash($token);

        if (hash_equals($instance->token_hash, $hash)) {
            return $instance;
        }

        if ($instance->previous_token_hash !== null
            && $instance->previous_token_expires_at?->isFuture()
            && hash_equals($instance->previous_token_hash, $hash)) {
            return $instance;
        }

        return null;
    }

    /**
     * Issue a fresh token, moving the current one into a 7-day grace window.
     * Returns the plaintext token — it is never stored or shown again.
     */
    public function rotateToken(): string
    {
        $token = InstanceToken::generate($this->id);

        $this->forceFill([
            'previous_token_hash' => $this->token_hash,
            'previous_token_expires_at' => now()->addDays(7),
            'token_hash' => InstanceToken::hash($token),
        ])->save();

        return $token;
    }

    public function heartbeatAgeSeconds(): ?int
    {
        if ($this->last_heartbeat_at === null) {
            return null;
        }

        return (int) $this->last_heartbeat_at->diffInSeconds(now());
    }

    public function health(): string
    {
        $age = $this->heartbeatAgeSeconds();

        return match (true) {
            $age === null => 'unknown',
            $age < 120 => 'healthy',
            $age < 600 => 'degraded',
            default => 'down',
        };
    }
}
