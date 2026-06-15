<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    public const AGGREGATES = ['count', 'avg', 'max', 'min'];

    public const OPERATORS = ['>', '>=', '<', '<='];

    /**
     * Synthetic metric type: alerts on heartbeat age (threshold = minutes).
     */
    public const TYPE_HEARTBEAT = 'heartbeat';

    protected $fillable = [
        'instance_id',
        'name',
        'metric_type',
        'key',
        'aggregate',
        'operator',
        'threshold',
        'window_minutes',
        'cooldown_minutes',
        'webhook_url',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'enabled' => 'boolean',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    public function isHeartbeatRule(): bool
    {
        return $this->metric_type === self::TYPE_HEARTBEAT;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Instance>
     */
    public function applicableInstances()
    {
        return $this->instance_id !== null
            ? Instance::query()->whereKey($this->instance_id)->get()
            : Instance::query()->get();
    }

    public function describeCondition(): string
    {
        if ($this->isHeartbeatRule()) {
            return "no heartbeat for > {$this->threshold} min";
        }

        $key = $this->key !== null ? " [{$this->key}]" : '';

        return "{$this->aggregate}({$this->metric_type}{$key}) {$this->operator} {$this->threshold} in {$this->window_minutes} min";
    }
}
