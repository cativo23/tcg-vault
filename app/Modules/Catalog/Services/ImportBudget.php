<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The one daily budget for set imports a user can start by hand. Every
 * started import fans out per-card jobs on the shared, rate-limited
 * imports queue, so each path that starts one (choosing a set, retrying
 * a stalled one) draws from this budget and one account cannot delay
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

    public function tryStart(int $userId, string $setTcgdexId): ImportBudgetResult
    {
        $date = now()->utc()->toDateString();
        $countKey = "import-budget:count:{$userId}:{$date}";
        $startedKey = "import-budget:started:{$userId}:{$date}:{$setTcgdexId}";

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

                    // Kept an hour past the end of the UTC day so a clock
                    // skew at midnight cannot drop today's count early.
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
}
