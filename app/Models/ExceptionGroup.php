<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExceptionGroup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'instance_id',
        'fingerprint',
        'class',
        'location',
        'first_seen_at',
        'last_seen_at',
        'total_count',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
