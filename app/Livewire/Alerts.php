<?php

namespace App\Livewire;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Services\AlertNotificationSender;
use App\Services\DataFlowStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

    public string $email_recipients = '';

    public ?string $testResult = null;

    public ?int $resolvingEventId = null;

    public string $resolutionComment = '';

    public function save(): void
    {
        $this->webhook_url = trim($this->webhook_url);

        if ($this->metric_type === AlertRule::TYPE_DATA_FLOW) {
            $this->key = '';
            $this->aggregate = 'count';
            $this->operator = '>';
            $this->threshold = '0';
            $this->window_minutes = 5;
        }

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
            'webhook_url' => ['nullable', 'url:http,https', 'max:500'],
            'email_recipients' => ['nullable', 'string', 'max:3000', function (string $attribute, mixed $value, \Closure $fail): void {
                $input = str_replace("\r\n", "\n", $value);

                if (preg_match('/[\x00-\x09\x0B-\x1F\x7F<>]/', $input)) {
                    $fail('Enter plain email addresses without display names or control characters.');

                    return;
                }

                $addresses = $this->parseEmailRecipients($input);

                if (count($addresses) > 10) {
                    $fail('Enter no more than 10 email recipients.');

                    return;
                }

                foreach ($addresses as $address) {
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || preg_match('/\s/', $address) || Validator::make(['address' => $address], [
                        'address' => ['required', 'string', 'max:254', 'email:rfc'],
                    ])->fails()) {
                        $fail('Enter valid email addresses separated by commas, semicolons or new lines.');

                        return;
                    }
                }
            }],
        ]);

        $data['key'] = $data['key'] !== '' ? $data['key'] : null;
        $data['webhook_url'] = $data['webhook_url'] !== '' ? $data['webhook_url'] : null;
        $data['email_recipients'] = $this->parseEmailRecipients($data['email_recipients'] ?? '');

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
        $this->webhook_url = $rule->webhook_url ?? '';
        $this->email_recipients = implode(', ', $rule->email_recipients ?? []);
    }

    public function resetForm(): void
    {
        $this->reset('editingId', 'name', 'instance_id', 'key', 'threshold', 'webhook_url', 'email_recipients', 'testResult');
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

    public function sendTest(int $ruleId, AlertNotificationSender $notifications): void
    {
        $rule = AlertRule::query()->findOrFail($ruleId);
        $statuses = $notifications->test($rule);

        if ($statuses['teams'] === 'disabled' && $statuses['email'] === 'disabled') {
            $this->testResult = "\"{$rule->name}\" uses internal alarms only. No external channel is configured and no test notification was sent.";

            return;
        }

        $results = [];

        foreach (['teams' => 'Teams', 'email' => 'Email'] as $channel => $label) {
            if ($statuses[$channel] !== 'disabled') {
                $results[] = $label.': '.($statuses[$channel] === 'sent' ? 'delivered' : 'delivery failed');
            }
        }

        $this->testResult = "Test for \"{$rule->name}\" — ".implode('; ', $results).'.';
    }

    /** @return list<string> */
    private function parseEmailRecipients(string $input): array
    {
        $addresses = [];

        foreach (preg_split('/[,;\r\n]/', $input) ?: [] as $candidate) {
            $address = trim($candidate);

            if ($address !== '') {
                $addresses[strtolower($address)] ??= $address;
            }
        }

        return array_values($addresses);
    }

    public function startResolving(int $eventId): void
    {
        AlertEvent::query()->whereKey($eventId)->whereNull('resolved_at')->firstOrFail();

        $this->resetValidation('resolutionComment');
        $this->resolvingEventId = $eventId;
        $this->resolutionComment = '';
    }

    public function cancelResolution(): void
    {
        $this->resetValidation('resolutionComment');
        $this->reset('resolvingEventId', 'resolutionComment');
    }

    public function resolveEvent(): void
    {
        $data = $this->validate([
            'resolvingEventId' => ['required', 'integer', 'exists:alert_events,id'],
            'resolutionComment' => ['nullable', 'string', 'max:1000'],
        ]);

        $event = AlertEvent::query()
            ->whereKey($data['resolvingEventId'])
            ->whereNull('resolved_at')
            ->firstOrFail();

        $event->forceFill([
            'resolved_at' => now(),
            'resolved_by_user_id' => auth()->id(),
            'resolved_comment' => $data['resolutionComment'] !== '' ? $data['resolutionComment'] : null,
        ])->save();

        $this->cancelResolution();
    }

    public function render()
    {
        return view('livewire.alerts', [
            'rules' => AlertRule::query()->with('instance')->orderBy('name')->get(),
            'events' => AlertEvent::query()->with(['rule', 'instance', 'resolvedBy'])->orderByDesc('triggered_at')->limit(50)->get(),
            'instances' => Instance::query()->orderBy('name')->get(),
            'metricTypes' => collect([AlertRule::TYPE_HEARTBEAT, AlertRule::TYPE_DATA_FLOW])
                ->merge(collect(DataFlowStatus::TYPES)
                    ->merge([
                        'gauge:queue.pending', 'gauge:queue.oldest_pending_seconds', 'gauge:queue.failed',
                        'counter:agent.export_gap_minutes',
                    ])
                    ->merge(DB::table('metric_buckets')->distinct()->pluck('type'))
                    ->sort(SORT_STRING))
                ->unique()
                ->values(),
        ])->title('Alerts — '.config('app.name'));
    }
}
