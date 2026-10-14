<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\WarmValueSeriesJob;
use App\Models\User;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Warms the cache behind the Activity page's value chart, so a visitor
 * after a price sync reads it instead of paying the rebuild. For every
 * user with a public collection (suspended members excluded, as their
 * page is a 404) it queues a WarmValueSeriesJob, which warms every chart
 * range with what an anonymous visitor's Activity page asks for. Prices
 * change during the daily sync and the tcgcsv sync, so this runs hourly.
 *
 * The work is on the queue because the scheduler container is small; the
 * command itself only lists user ids. --sync runs the jobs here instead,
 * one user at a time: one failing is logged and the run goes on, and the
 * exit code then reports the failure.
 */
final class WarmValueSeries extends Command
{
    protected $signature = 'gallery:warm-value-series {--sync : Warm in this process instead of queueing}';

    protected $description = 'Warm the Activity value-series cache for every public collection and chart range.';

    public function handle(): int
    {
        $ids = User::query()
            ->whereNull('suspended_at')
            ->whereIn('id', Collection::withoutGlobalScope(TenantScope::class)->where('is_public', true)->select('user_id'))
            ->orderBy('id')
            ->pluck('id');

        if (! $this->option('sync')) {
            $ids->each(fn (int $id) => WarmValueSeriesJob::dispatch($id));
            Log::info('gallery:warm-value-series queued', ['users' => $ids->count()]);
            $this->info("Queued {$ids->count()} users.");

            return self::SUCCESS;
        }

        $computed = 0;
        $warm = 0;
        $failures = 0;

        foreach ($ids as $id) {
            try {
                $counts = (new WarmValueSeriesJob($id))->warm();
                $computed += $counts['computed'] ?? 0;
                $warm += $counts['warm'] ?? 0;
            } catch (Throwable $e) {
                $failures++;
                Log::error('gallery:warm-value-series failed for a user', ['user_id' => $id, 'exception' => $e]);
            }
        }

        Log::info('gallery:warm-value-series finished', [
            'users' => $ids->count(),
            'computed' => $computed,
            'warm' => $warm,
            'failures' => $failures,
        ]);
        $this->info("Warmed {$ids->count()} users: {$computed} series computed, {$warm} already warm, {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
