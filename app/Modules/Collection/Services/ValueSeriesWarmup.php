<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Jobs\WarmValueSeriesJob;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Scopes\TenantScope;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Queues a rebuild of one user's Activity chart after their public holdings
 * change, so the next visitor reads a warm cache instead of paying the
 * rebuild. The model observers call it.
 *
 * The job is queued once the surrounding transaction commits (a rolled-back
 * change queues nothing) and runs a minute later, so a burst of edits is
 * built once, from the final state. The job is unique per user until it
 * starts, so edits in that minute share it and an edit made during a run
 * queues the next one.
 *
 * A bulk change (an import) edits many copies of one collection in one
 * process, and each would otherwise look the collection up and try the
 * unique lock. This instance remembers, for the length of the delay, which
 * users it has already queued: any change inside that window is covered
 * by the job that will start after it, so it is skipped. The instance is
 * registered as scoped, so a request or a queue job starts without that
 * memory, and the window keeps a long-running process (an artisan command)
 * from skipping a change the queued job could no longer see.
 *
 * The change that triggered this is already saved: a queue that fails is
 * reported and never reaches the person saving.
 */
final class ValueSeriesWarmup
{
    /** Seconds between the change and the rebuild. */
    public const DELAY_SECONDS = 60;

    /** @var array<int, int> user id => timestamp until which a queued warm covers further changes */
    private array $coveredUntil = [];

    /** @var array<int, int> collection id => owner's user id */
    private array $owners = [];

    /** Warms the owner of the collection if it is public; does nothing for a private or missing one. */
    public function afterChangeInCollection(int $collectionId): void
    {
        $userId = $this->owners[$collectionId] ?? null;

        if ($userId !== null && $this->isCovered($userId)) {
            return;
        }

        $collection = Collection::withoutGlobalScope(TenantScope::class)->find($collectionId, ['id', 'user_id', 'is_public']);

        if ($collection === null || ! $collection->is_public) {
            return;
        }

        $this->owners[$collectionId] = (int) $collection->user_id;
        $this->afterChangeFor((int) $collection->user_id);
    }

    public function afterChangeFor(int $userId): void
    {
        if ($this->isCovered($userId)) {
            return;
        }

        $this->coveredUntil[$userId] = now()->getTimestamp() + self::DELAY_SECONDS;

        // A rolled-back change queued nothing, so it covers nothing.
        app('db.transactions')->addCallbackForRollback(function () use ($userId): void {
            unset($this->coveredUntil[$userId]);
        });

        DB::afterCommit(function () use ($userId): void {
            $job = (new WarmValueSeriesJob($userId))->delay(now()->addSeconds(self::DELAY_SECONDS));

            try {
                dispatch($job);
            } catch (Throwable $e) {
                report($e);
                unset($this->coveredUntil[$userId]);

                // If the push failed after the lock was taken, give it back, or this user's warm
                // would be skipped until the lock expires. A job that never got the lock has no
                // owner, and releasing without one would drop the lock of another pending job.
                if ($job->uniqueLockOwner !== '') {
                    try {
                        (new UniqueLock(Cache::driver()))->release($job);
                    } catch (Throwable $releaseFailure) {
                        report($releaseFailure);
                    }
                }
            }
        });
    }

    private function isCovered(int $userId): bool
    {
        return ($this->coveredUntil[$userId] ?? 0) > now()->getTimestamp();
    }
}
