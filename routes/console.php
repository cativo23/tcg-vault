<?php

use App\Modules\Invites\Models\Invite;
use App\Support\DiscordAlerter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// onFailure() only catches the dispatch command itself erroring out (an
// OOM kill, an exception) — it says nothing about whether the jobs it
// dispatched actually ran. catalog:check-pricing-freshness below is the
// one that catches a silent stall of the kind that went unnoticed for
// 30 hours on 2026-09-24/25.
Schedule::command('catalog:refresh-prices')
    ->daily()
    ->onFailure(fn () => app(DiscordAlerter::class)->send(
        '⚠️ tcg-vault: catalog:refresh-prices itself errored — the daily pricing sync did not even dispatch.'
    ));

// Runs 4 hours after the dispatch above, well past how long a normal
// day's worth of jobs takes to drain — see CheckPricingFreshness's own
// docblock for the staleness threshold and why it exists.
Schedule::command('catalog:check-pricing-freshness')->dailyAt('04:00');

// Telescope's storage driver is `database` with no retention of its own —
// the table grows until the disk does not. 48h matches this project's
// other short-lived operational data (Horizon's failed-job retention).
Schedule::command('telescope:prune --hours=48')->daily();

// Reset tokens expire after an hour but stay in the table until cleared.
Schedule::command('auth:clear-resets')->daily();

// model:prune only discovers models under app/Models, so module models
// are listed here.
Schedule::command('model:prune', ['--model' => [Invite::class]])->daily();
