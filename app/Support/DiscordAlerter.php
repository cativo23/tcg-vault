<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a plain-text operational alert to a Discord webhook — a stalled
 * daily job, a scheduled command that errored outright. Not tied to
 * Horizon: Horizon's own routeSlackNotificationsTo() only speaks Slack,
 * and this project has no Slack workspace.
 *
 * Configured via services.discord.alert_webhook_url (DISCORD_ALERT_WEBHOOK_URL).
 * Unconfigured means alerting is off, not broken — send() silently no-ops
 * rather than throwing, since a missing webhook URL in a fresh
 * environment shouldn't block whatever triggered the alert. A webhook
 * request that fails is logged, never thrown, for the same reason: an
 * alert about a problem must never itself become a second problem for
 * the scheduled command or job that's calling it.
 */
final class DiscordAlerter
{
    public function send(string $message): void
    {
        $webhookUrl = config('services.discord.alert_webhook_url');

        if (blank($webhookUrl)) {
            return;
        }

        try {
            $response = Http::post($webhookUrl, ['content' => $message]);

            if ($response->failed()) {
                Log::warning('Discord alert failed to send', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Discord alert failed to send', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
