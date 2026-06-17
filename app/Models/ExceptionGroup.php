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
        'resolved_at',
        'resolved_by_user_id',
        'resolved_comment',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
