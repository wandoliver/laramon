<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sample extends Model
{
    public const KINDS = ['exception', 'slow_query', 'slow_request'];

    /**
     * Newest samples kept per (instance, kind, fingerprint).
     */
    public const KEEP_PER_FINGERPRINT = 20;

    public $timestamps = false;

    protected $fillable = [
        'instance_id',
        'kind',
        'fingerprint',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
