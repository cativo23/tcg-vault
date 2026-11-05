<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Services\WeekBaselines;
use Illuminate\Console\Command;

/**
 * Rebuilds the whole week-baseline read model (see WeekBaselines) from
 * card_price_snapshots. Idempotent. Run after the migration that creates
 * the table (the gallery shows no 7-day moves until then), after any change
 * to card_price_snapshots made outside the app (with prices:bump-version),
 * and nightly by the scheduler to heal a refresh a write path missed.
 */
final class RefreshWeekBaselines extends Command
{
    protected $signature = 'prices:refresh-week-baselines';

    protected $description = 'Rebuild every print\'s 7-day-move baseline from the stored prices.';

    public function handle(WeekBaselines $baselines): int
    {
        $written = $baselines->refreshAll();

        $this->info(sprintf('Week baselines refreshed: %d %s.', $written, $written === 1 ? 'print has one' : 'prints have one'));

        return self::SUCCESS;
    }
}
