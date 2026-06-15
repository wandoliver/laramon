<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers alert cards to Microsoft Teams via a Workflows webhook
 * ("Post to a channel when a webhook request is received"). Uses the
 * Adaptive Card envelope — the legacy O365 connector format is retired.
 * Never throws: a broken webhook must not stall alert evaluation.
 */
class TeamsNotifier
{
    /**
     * @param  array<string, string>  $facts
     */
    public function send(string $webhookUrl, string $title, string $tone, array $facts, ?string $linkUrl = null): bool
    {
        $card = [
            'type' => 'AdaptiveCard',
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'version' => '1.4',
            'msteams' => ['width' => 'Full'],
            'body' => [
                [
                    'type' => 'TextBlock',
                    'text' => $title,
                    'weight' => 'Bolder',
                    'size' => 'Medium',
                    'color' => $tone === 'good' ? 'Good' : 'Attention',
                    'wrap' => true,
                ],
                [
                    'type' => 'FactSet',
                    'facts' => collect($facts)
                        ->map(fn (string $value, string $key) => ['title' => $key, 'value' => $value])
                        ->values()
                        ->all(),
                ],
            ],
        ];

        if ($linkUrl !== null) {
            $card['actions'] = [
                ['type' => 'Action.OpenUrl', 'title' => 'Open in Monitor', 'url' => $linkUrl],
            ];
        }

        try {
            $response = Http::timeout(5)->post($webhookUrl, [
                'type' => 'message',
                'attachments' => [
                    [
                        'contentType' => 'application/vnd.microsoft.card.adaptive',
                        'content' => $card,
                    ],
                ],
            ]);

            if (! $response->successful()) {
                Log::warning('Teams notification failed', ['status' => $response->status()]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("Teams notification failed: {$e->getMessage()}");

            return false;
        }
    }
}
