<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricBucket extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'instance_id',
        'type',
        'key',
        'key_hash',
        'bucket_start',
        'bucket_seconds',
        'count',
        'sum',
        'min',
        'max',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public static function hashKey(string $key): string
    {
        return md5($key, true);
    }

    public function avg(): ?float
    {
        if ($this->sum === null || (int) $this->count === 0) {
            return null;
        }

        return (float) $this->sum / (int) $this->count;
    }
}
