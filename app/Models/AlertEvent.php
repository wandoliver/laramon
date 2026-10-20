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
        'notification_status',
        'breach_started_at',
        'recovery_started_at',
        'last_evaluated_at',
        'notification_attempted_at',
        'notification_targets',
        'reason_code',
        'last_reason_code',
        'last_reason_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified' => 'boolean',
            'notification_status' => 'array',
            'breach_started_at' => 'datetime',
            'recovery_started_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'notification_attempted_at' => 'datetime',
            'notification_targets' => 'array',
            'last_reason_at' => 'datetime',
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
