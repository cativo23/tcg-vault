<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Jobs\WarmValueSeriesJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Queues a rebuild of one user's Activity chart after their public holdings
 * change, so the next visitor reads a warm cache instead of paying for the
 * rebuild. The model observers call it.
 *
 * The job is queued once the surrounding transaction commits (a rolled-back
 * change queues nothing) and runs a minute later, so a burst of edits is
 * built once, from the final state. The job is unique per user until it
 * starts, so edits in that minute share it and an edit made during a run
 * queues the next one.
 *
 * The change that triggered this is already saved: a queue that fails is
 * reported and never reaches the person saving.
 */
final class ValueSeriesWarmup
{
    /** Seconds between the change and the rebuild. */
    public const DELAY_SECONDS = 60;

    public static function afterChangeFor(int $userId): void
    {
        DB::afterCommit(static function () use ($userId): void {
            $job = (new WarmValueSeriesJob($userId))->delay(now()->addSeconds(self::DELAY_SECONDS));

            try {
                dispatch($job);
            } catch (Throwable $e) {
                report($e);

                // If the push failed after the lock was taken, give it back, or this user's warm
                // would be skipped until the lock expires. A job that never got the lock has no
                // owner, and releasing without one would drop the lock of another pending job.
                if (is_string($job->uniqueLockOwner ?? null) && $job->uniqueLockOwner !== '') {
                    try {
                        (new UniqueLock(Cache::driver()))->release($job);
                    } catch (Throwable $releaseFailure) {
                        report($releaseFailure);
                    }
                }
            }
        });
    }
}
