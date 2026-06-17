<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'alert_rule_id',
        'instance_id',
        'value',
        'triggered_at',
        'resolved_at',
        'resolved_by_user_id',
        'resolved_comment',
        'notified',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified' => 'boolean',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
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
