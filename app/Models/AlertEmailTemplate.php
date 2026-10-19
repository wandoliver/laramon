<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertEmailTemplate extends Model
{
    // The editor supplies the singleton ID explicitly; auto-increment may already be ahead.
    protected $fillable = ['id', 'triggered_subject', 'triggered_body', 'resolved_subject', 'resolved_body'];

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'triggered_subject' => '[{{ app_name }}] {{ status }}: {{ rule_name }} — {{ instance_name }}',
            'triggered_body' => "{{ status }}: {{ rule_name }}\n\nInstance: {{ instance_name }}\nCondition: {{ condition }}\nValue: {{ value }}\nTriggered at: {{ triggered_at }}\n\nOpen monitoring: {{ dashboard_url }}",
            'resolved_subject' => '[{{ app_name }}] {{ status }}: {{ rule_name }} — {{ instance_name }}',
            'resolved_body' => "{{ status }}: {{ rule_name }}\n\nInstance: {{ instance_name }}\nCondition: {{ condition }}\nCurrent value: {{ value }}\nPrevious value: {{ previous_value }}\nTriggered at: {{ triggered_at }}\nResolved at: {{ resolved_at }}\n\nOpen monitoring: {{ dashboard_url }}",
        ];
    }

    public static function current(): self
    {
        return static::query()->find(1) ?? new static(static::defaults());
    }
}
