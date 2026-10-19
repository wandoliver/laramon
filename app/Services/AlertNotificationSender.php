<?php

namespace App\Services;

use App\Models\AlertRule;
use Illuminate\Support\Facades\Log;

/** Notification channels are independent; internal events never depend on delivery. */
class AlertNotificationSender
{
    public function __construct(
        protected TeamsNotifier $teams,
        protected AlertEmailNotifier $email,
    ) {}

    /** @return array{teams: string, email: string} */
    public function pending(AlertRule $rule): array
    {
        return [
            'teams' => trim((string) $rule->webhook_url) === '' ? 'disabled' : 'pending',
            'email' => empty($rule->email_recipients) ? 'disabled' : 'pending',
        ];
    }

    /**
     * @param  array{title: string, tone: string, facts: array<string, string>, link: string, variables: array<string, string>}  $message
     * @return array{teams: string, email: string}
     */
    public function send(AlertRule $rule, string $phase, array $message): array
    {
        $status = $this->pending($rule);

        if ($status['teams'] !== 'disabled') {
            try {
                $url = trim((string) $rule->webhook_url);
                $valid = filter_var($url, FILTER_VALIDATE_URL) !== false
                    && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
                $status['teams'] = $valid && $this->teams->send(
                    $url, $message['title'], $message['tone'], $message['facts'], $message['link'],
                ) ? 'sent' : 'failed';
            } catch (\Throwable) {
                $status['teams'] = 'failed';
                Log::warning('Alert notification channel failed.', ['channel' => 'teams', 'rule_id' => $rule->id]);
            }
        }

        if ($status['email'] !== 'disabled') {
            try {
                $status['email'] = $this->email->send($rule->email_recipients, $phase, $message['variables'])
                    ? 'sent' : 'failed';
            } catch (\Throwable) {
                $status['email'] = 'failed';
                Log::warning('Alert notification channel failed.', ['channel' => 'email', 'rule_id' => $rule->id]);
            }
        }

        return $status;
    }

    /** @return array{teams: string, email: string} */
    public function test(AlertRule $rule): array
    {
        $scope = $rule->instance?->name ?? 'All instances';
        $link = route('alerts');

        return $this->send($rule, 'test', [
            'title' => "🔔 Test notification — {$rule->name}",
            'tone' => 'good',
            'facts' => ['Rule' => $rule->name, 'Condition' => $rule->describeCondition(), 'Scope' => $scope],
            'link' => $link,
            'variables' => [
                'app_name' => (string) config('app.name'), 'status' => 'Test',
                'rule_name' => $rule->name, 'instance_name' => $scope,
                'condition' => $rule->describeCondition(), 'value' => 'Test', 'previous_value' => '',
                'triggered_at' => now()->utc()->format('d.m.Y H:i').' UTC', 'resolved_at' => '',
                'dashboard_url' => $link,
            ],
        ]);
    }

    /** Keep the legacy flag truthful when several channels are configured. */
    public static function allConfiguredSent(array $status): bool
    {
        $configured = array_filter($status, fn (string $state) => $state !== 'disabled');

        return $configured !== [] && count(array_filter($configured, fn (string $state) => $state === 'sent')) === count($configured);
    }
}
