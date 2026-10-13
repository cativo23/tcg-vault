<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The one daily budget for set imports a user can start. Every started
 * import fans out per-card jobs on the shared, rate-limited imports
 * queue, so every path that queues one draws from this budget: choosing
 * a set, retrying a stalled one, and adding a card from a set that is
 * not fully stored yet (single add and TCGplayer import, through
 * CollectionService::syncCardAndQueueImport()). One account cannot delay
 * everyone's imports.
 *
 * A set counts once per user and UTC day: starting it again the same day
 * is free. Check and consume happen under a per-user lock, so concurrent
 * requests cannot all pass the check before any of them counts.
 */
final class ImportBudget
{
    public const DAILY_LIMIT = 20;

    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 3;

    /**
     * Whether tryStart() would answer Exhausted for this set right now: the
     * user has spent today's budget and did not start this set today. A
     * read without the lock, for display only; tryStart() stays the check
     * that counts.
     */
    public function isSpentFor(int $userId, string $setTcgdexId): bool
    {
        [$countKey, $startedKey] = $this->keys($userId, $setTcgdexId);

        return ! Cache::has($startedKey) && (int) Cache::get($countKey, 0) >= self::DAILY_LIMIT;
    }

    public function tryStart(int $userId, string $setTcgdexId): ImportBudgetResult
    {
        [$countKey, $startedKey] = $this->keys($userId, $setTcgdexId);

        try {
            return Cache::lock("import-budget:lock:{$userId}", self::LOCK_SECONDS)->block(
                self::LOCK_WAIT_SECONDS,
                function () use ($countKey, $startedKey): ImportBudgetResult {
                    if (Cache::has($startedKey)) {
                        return ImportBudgetResult::Started;
                    }

                    $count = (int) Cache::get($countKey, 0);

                    if ($count >= self::DAILY_LIMIT) {
                        return ImportBudgetResult::Exhausted;
                    }

                    // The keys carry the date, so the extra hour only keeps
                    // today's keys alive until the day has fully ended.
                    $expiresAt = now()->utc()->endOfDay()->addHour();
                    Cache::put($countKey, $count + 1, $expiresAt);
                    Cache::put($startedKey, true, $expiresAt);

                    return ImportBudgetResult::Started;
                },
            );
        } catch (LockTimeoutException) {
            return ImportBudgetResult::Busy;
        }
    }

    /** @return array{0: string, 1: string} today's count key and this set's started key */
    private function keys(int $userId, string $setTcgdexId): array
    {
        $date = now()->utc()->toDateString();

        return [
            "import-budget:count:{$userId}:{$date}",
            "import-budget:started:{$userId}:{$date}:{$setTcgdexId}",
        ];
    }
}
