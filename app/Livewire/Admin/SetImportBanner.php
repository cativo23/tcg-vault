<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Jobs\ImportSetJob;
use App\Modules\Catalog\Models\Set;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Import progress for the set grid. It is its own component so the poll
 * re-renders only this banner: the grid's chips and Alpine state are never
 * morphed by a poll, and the grid redraws (event below) only when cards
 * actually arrive.
 *
 * A set counts as importing while it has fewer local cards than tcgdex's
 * announced total; with no announced total there is nothing to wait for.
 */
final class SetImportBanner extends Component
{
    /** Seconds without a new card arriving before an import counts as stalled. */
    private const IMPORT_STALL_SECONDS = 600;

    public const RETRY_LIMIT_PER_MINUTE = 3;

    #[Locked]
    public string $setTcgdexId = '';

    // Progress bookkeeping: only the server moves these.
    #[Locked]
    public int $lastSeenCount = 0;

    #[Locked]
    public int $lastProgressAt = 0;

    public string $notice = '';

    public function mount(string $setTcgdexId): void
    {
        $this->setTcgdexId = $setTcgdexId;
        $this->lastSeenCount = $this->localCount();
        $this->lastProgressAt = now()->getTimestamp();
    }

    /** The poll target: records progress and tells the grid when cards arrived. */
    public function check(): void
    {
        $count = $this->localCount();

        if ($count === $this->lastSeenCount) {
            return;
        }

        $this->lastSeenCount = $count;
        $this->lastProgressAt = now()->getTimestamp();
        $this->dispatch('set-cards-changed');
    }

    /** Re-dispatches the (unique) import for a set that is still short of cards. */
    public function retryImport(): void
    {
        if ($this->missingCount() < 1) {
            return;
        }

        $key = 'import-retry:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::RETRY_LIMIT_PER_MINUTE)) {
            $this->notice = 'Already retrying: give it a minute.';

            return;
        }

        RateLimiter::hit($key, 60);

        $this->notice = '';
        ImportSetJob::dispatch($this->setTcgdexId);
        $this->lastProgressAt = now()->getTimestamp();
    }

    private function set(): Set
    {
        return Set::where('tcgdex_id', $this->setTcgdexId)->first() ?? throw new NotFoundHttpException;
    }

    private function localCount(): int
    {
        return $this->set()->cards()->count();
    }

    private function missingCount(): int
    {
        $set = $this->set();
        $total = (int) $set->card_count;

        return $total > 0 ? max(0, $total - $set->cards()->count()) : 0;
    }

    public function render(): View
    {
        $set = $this->set();
        $localCount = $set->cards()->count();
        $missingCount = (int) $set->card_count > 0 ? max(0, (int) $set->card_count - $localCount) : 0;

        return view('livewire.admin.set-import-banner', [
            'localCount' => $localCount,
            'totalCount' => (int) $set->card_count,
            'missingCount' => $missingCount,
            'importing' => $missingCount > 0,
            'stalled' => $missingCount > 0 && now()->getTimestamp() - $this->lastProgressAt >= self::IMPORT_STALL_SECONDS,
        ]);
    }
}
