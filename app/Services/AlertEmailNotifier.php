<?php

namespace App\Services;

use App\Mail\AlertNotificationMail;
use App\Models\AlertEmailTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AlertEmailNotifier
{
    /** @param array<string> $recipients
     * @param  array<string, string>  $variables
     */
    public function send(array $recipients, string $phase, array $variables): bool
    {
        try {
            $recipients = $this->validatedRecipients($recipients);
            $mailer = config('mail.default');

            if ($recipients === [] || ! is_string($mailer) || ! $this->hasDeliverableTransport($mailer)) {
                Log::warning('Alert email was not sent: delivery configuration is unavailable.');

                return false;
            }

            if ($phase === 'test') {
                $variables['status'] = 'Test';
            }

            $rendered = app(AlertEmailRenderer::class)->render(AlertEmailTemplate::current(), $phase, $variables);
            $delivered = true;

            foreach ($recipients as $recipient) {
                try {
                    // Separate messages keep other recipients private. No queue worker is required.
                    $sent = Mail::mailer($mailer)->to($recipient)->send(new AlertNotificationMail($rendered['subject'], $rendered['body']));
                    if ($sent === null) {
                        $delivered = false;
                    }
                } catch (\Throwable) {
                    $delivered = false;
                }
            }

            if (! $delivered) {
                Log::warning('Alert email delivery failed.');
            }

            return $delivered;
        } catch (\Throwable) {
            // Exception messages may contain addresses, SMTP credentials or message content.
            Log::warning('Alert email delivery failed.');

            return false;
        }
    }

    /** @return list<string> */
    private function validatedRecipients(array $recipients): array
    {
        if (count($recipients) > 10) {
            throw new \InvalidArgumentException('Too many alert email recipients.');
        }

        $validated = [];
        foreach ($recipients as $recipient) {
            if (! is_string($recipient) || preg_match('/[\x00-\x1f\x7f]/', $recipient)) {
                throw new \InvalidArgumentException('Invalid alert email recipient.');
            }
            $recipient = trim($recipient);
            if (strlen($recipient) > 254 || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid alert email recipient.');
            }
            $validated[strtolower($recipient)] ??= $recipient;
        }

        return array_values($validated);
    }

    private function hasDeliverableTransport(string $name, array $seen = []): bool
    {
        if (count($seen) >= 8 || in_array($name, $seen, true)) {
            return false;
        }

        $configuration = config('mail.mailers.'.$name);
        if (! is_array($configuration)) {
            return false;
        }
        $transport = $configuration['transport'] ?? null;
        if (in_array($transport, ['smtp', 'sendmail', 'mailgun', 'ses', 'ses-v2', 'postmark', 'resend'], true)) {
            return true;
        }
        if (! in_array($transport, ['failover', 'roundrobin'], true) || empty($configuration['mailers']) || ! is_array($configuration['mailers'])) {
            return false;
        }

        foreach ($configuration['mailers'] as $child) {
            if (! is_string($child) || ! $this->hasDeliverableTransport($child, [...$seen, $name])) {
                return false;
            }
        }

        return true;
    }
}
