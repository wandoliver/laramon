<?php

namespace App\Services;

use App\Models\AlertEmailTemplate;
use InvalidArgumentException;

class AlertEmailRenderer
{
    public const PLACEHOLDERS = [
        'app_name', 'status', 'rule_name', 'instance_name', 'condition', 'value',
        'previous_value', 'triggered_at', 'resolved_at', 'dashboard_url',
    ];

    public static function isValidTemplate(string $template, bool $subject = false): bool
    {
        if (trim($template) === '' || mb_strlen($template) > ($subject ? 200 : 10000)) {
            return false;
        }

        if (preg_match($subject ? '/[\x00-\x1f\x7f]/u' : '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $template)) {
            return false;
        }

        $remaining = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $match): string {
            return in_array($match[1], self::PLACEHOLDERS, true) ? '' : $match[0];
        }, $template);

        return ! str_contains($remaining, '{{') && ! str_contains($remaining, '}}');
    }

    /** @param array<string, string> $variables
     * @return array{subject: string, body: string}
     */
    public function render(AlertEmailTemplate $template, string $phase, array $variables): array
    {
        if (! in_array($phase, ['triggered', 'resolved', 'test'], true)) {
            throw new InvalidArgumentException('Unsupported email phase.');
        }

        $prefix = $phase === 'resolved' ? 'resolved' : 'triggered';
        $subject = (string) $template->getAttribute($prefix.'_subject');
        $body = (string) $template->getAttribute($prefix.'_body');

        if (! self::isValidTemplate($subject, true) || ! self::isValidTemplate($body)) {
            throw new InvalidArgumentException('Invalid alert email template.');
        }

        // A single replacement pass keeps user-controlled variable values inert.
        $replace = static fn (array $match): string => is_string($variables[$match[1]] ?? null)
            ? mb_substr($variables[$match[1]], 0, 2000)
            : '';
        $subject = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', $replace, $subject);
        $body = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', $replace, $body);

        return [
            'subject' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $subject), 0, 200),
            'body' => $body,
        ];
    }

    /** @return array<string, string> */
    public static function previewVariables(string $phase): array
    {
        return [
            'app_name' => (string) config('app.name'),
            'status' => $phase === 'resolved' ? 'Resolved' : 'Alert',
            'rule_name' => 'Example error alert',
            'instance_name' => 'Example production',
            'condition' => 'exception count > 0 over 5 min',
            'value' => $phase === 'resolved' ? '0' : '3',
            'previous_value' => '3',
            'triggered_at' => '2026-10-19 12:00:00 UTC',
            'resolved_at' => $phase === 'resolved' ? '2026-10-19 12:10:00 UTC' : '',
            'dashboard_url' => 'https://monitor.example.com/alerts',
        ];
    }
}
