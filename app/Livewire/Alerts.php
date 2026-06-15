<?php

namespace App\Livewire;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Services\TeamsNotifier;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Alerts extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public ?int $instance_id = null;

    public string $metric_type = AlertRule::TYPE_HEARTBEAT;

    public string $key = '';

    public string $aggregate = 'count';

    public string $operator = '>';

    public string $threshold = '';

    public int $window_minutes = 15;

    public int $cooldown_minutes = 30;

    public string $webhook_url = '';

    public ?string $testResult = null;

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'instance_id' => ['nullable', 'integer', 'exists:instances,id'],
            'metric_type' => ['required', 'string', 'max:60'],
            'key' => ['nullable', 'string', 'max:500'],
            'aggregate' => ['required', 'in:'.implode(',', AlertRule::AGGREGATES)],
            'operator' => ['required', 'in:'.implode(',', AlertRule::OPERATORS)],
            'threshold' => ['required', 'numeric'],
            'window_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'cooldown_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'webhook_url' => ['required', 'url', 'max:500'],
        ]);

        $data['key'] = $data['key'] !== '' ? $data['key'] : null;

        AlertRule::query()->updateOrCreate(
            ['id' => $this->editingId],
            $data + ['enabled' => true],
        );

        $this->resetForm();
    }

    public function edit(int $ruleId): void
    {
        $rule = AlertRule::query()->findOrFail($ruleId);

        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->instance_id = $rule->instance_id;
        $this->metric_type = $rule->metric_type;
        $this->key = $rule->key ?? '';
        $this->aggregate = $rule->aggregate;
        $this->operator = $rule->operator;
        $this->threshold = (string) $rule->threshold;
        $this->window_minutes = $rule->window_minutes;
        $this->cooldown_minutes = $rule->cooldown_minutes;
        $this->webhook_url = $rule->webhook_url;
    }

    public function resetForm(): void
    {
        $this->reset('editingId', 'name', 'instance_id', 'key', 'threshold', 'webhook_url', 'testResult');
        $this->metric_type = AlertRule::TYPE_HEARTBEAT;
        $this->aggregate = 'count';
        $this->operator = '>';
        $this->window_minutes = 15;
        $this->cooldown_minutes = 30;
    }

    public function toggle(int $ruleId): void
    {
        $rule = AlertRule::query()->findOrFail($ruleId);
        $rule->forceFill(['enabled' => ! $rule->enabled])->save();
    }

    public function delete(int $ruleId): void
    {
        AlertRule::query()->findOrFail($ruleId)->delete();

        if ($this->editingId === $ruleId) {
            $this->resetForm();
        }
    }

    public function sendTest(int $ruleId, TeamsNotifier $teams): void
    {
        $rule = AlertRule::query()->findOrFail($ruleId);

        $ok = $teams->send(
            $rule->webhook_url,
            "🔔 Test notification — {$rule->name}",
            'good',
            [
                'Rule' => $rule->name,
                'Condition' => $rule->describeCondition(),
                'Scope' => $rule->instance?->name ?? 'All instances',
            ],
            route('alerts'),
        );

        $this->testResult = $ok
            ? "Test notification for \"{$rule->name}\" delivered."
            : "Delivery failed for \"{$rule->name}\" — check the webhook URL and laravel.log.";
    }

    public function render()
    {
        return view('livewire.alerts', [
            'rules' => AlertRule::query()->with('instance')->orderBy('name')->get(),
            'events' => AlertEvent::query()->with(['rule', 'instance'])->orderByDesc('triggered_at')->limit(50)->get(),
            'instances' => Instance::query()->orderBy('name')->get(),
            'metricTypes' => collect([AlertRule::TYPE_HEARTBEAT])
                ->merge(DB::table('metric_buckets')->distinct()->orderBy('type')->pluck('type'))
                ->unique()
                ->values(),
        ])->title('Alerts — '.config('app.name'));
    }
}
