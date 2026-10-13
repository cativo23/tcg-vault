<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Jobs\ImportSetJob;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\ImportBudget;
use App\Modules\Catalog\Services\ImportBudgetResult;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Import progress for the set grid. It is its own component so the poll
 * re-renders only this banner: the grid's chips and Alpine state are never
 * morphed by a poll. When cards arrive it dispatches `set-cards-changed`,
 * at most once per REFRESH_INTERVAL_SECONDS while importing and at once
 * when the import completes; the grid's Alpine component decides
 * when it is safe to redraw.
 *
 * A set counts as importing while it has fewer local cards than tcgdex's
 * announced total; with no announced total there is nothing to wait for.
 */
final class SetImportBanner extends Component
{
    /** Seconds without a new card arriving before an import counts as stalled. */
    public const IMPORT_STALL_SECONDS = 600;

    /**
     * Seconds after the banner appears during which it keeps the fast poll
     * even if the newest stored card is already older than the stall window,
     * so an import that has just been started shows its progress.
     */
    public const MOUNT_GRACE_SECONDS = 60;

    /**
     * Poll interval of a stalled banner. A slow queue can recover on its own,
     * so the banner keeps polling for as long as the page is open; one cheap
     * count query a minute per open tab is an acceptable price for noticing.
     */
    public const STALLED_POLL_SECONDS = 60;

    /** Minimum seconds between two grid redraws while cards keep arriving. */
    public const REFRESH_INTERVAL_SECONDS = 20;

    #[Locked]
    public string $setTcgdexId = '';

    // Progress bookkeeping: only the server moves these.
    #[Locked]
    public int $lastSeenCount = 0;

    #[Locked]
    public int $lastProgressAt = 0;

    #[Locked]
    public int $mountedAt = 0;

    // The card count the grid was last told about, and when.
    #[Locked]
    public int $announcedCount = 0;

    #[Locked]
    public int $announcedAt = 0;

    // Whether this page saw the set short of cards; only then does finishing
    // announce "All cards loaded".
    #[Locked]
    public bool $sawImport = false;

    public string $notice = '';

    /** @var array{local: int, total: int, missing: int}|null */
    private ?array $progress = null;

    public function mount(string $setTcgdexId): void
    {
        $this->setTcgdexId = $setTcgdexId;

        $progress = $this->progress();
        $this->lastSeenCount = $progress['local'];
        $this->announcedCount = $progress['local'];
        // The stall clock starts at the newest card already stored, not at
        // this visit, so a set that stopped growing long ago is stalled at once.
        $newest = Set::where('tcgdex_id', $setTcgdexId)->first()?->cards()->max('created_at');
        $this->lastProgressAt = $newest !== null ? Carbon::parse($newest)->getTimestamp() : now()->getTimestamp();
        $this->announcedAt = now()->getTimestamp();
        $this->mountedAt = now()->getTimestamp();
        $this->sawImport = $progress['missing'] > 0;
    }

    /** The poll target: records progress and tells the grid when cards arrived. */
    public function check(): void
    {
        $local = $this->progress()['local'];
        $now = now()->getTimestamp();

        if ($local !== $this->lastSeenCount) {
            $this->lastSeenCount = $local;
            $this->lastProgressAt = $now;
        }

        // Cards the grid has not drawn yet are announced on a slow cadence
        // while the import runs, and at once when it finishes. A stall needs
        // longer than the cadence, so it never waits on it.
        $settled = $this->progress()['missing'] === 0;

        if ($local !== $this->announcedCount && ($settled || $now - $this->announcedAt >= self::REFRESH_INTERVAL_SECONDS)) {
            $this->announcedCount = $local;
            $this->announcedAt = $now;
            $this->dispatch('set-cards-changed');
        }
    }

    /**
     * Re-dispatches the (unique) import, but only for a stalled one: every
     * accepted retry fans out per-card sync jobs, so a client cannot ask
     * while cards are still arriving. One retry per user and set per stall
     * window, and each retry draws from the user's daily ImportBudget (free
     * for a set the user already started today).
     */
    public function retryImport(): void
    {
        if (! $this->isStalled()) {
            return;
        }

        $key = 'import-retry:'.auth()->id().':'.$this->setTcgdexId;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->notice = 'Already retried: give it a few minutes.';

            return;
        }

        $budget = app(ImportBudget::class)->tryStart((int) auth()->id(), $this->setTcgdexId);

        if ($budget !== ImportBudgetResult::Started) {
            $this->notice = $budget === ImportBudgetResult::Busy
                ? 'Another import is being started — try again.'
                : 'Not imported '.ImportBudget::limitNote().'.';

            return;
        }

        RateLimiter::hit($key, self::IMPORT_STALL_SECONDS);

        $this->notice = '';
        ImportSetJob::dispatch($this->setTcgdexId);
        $this->lastProgressAt = now()->getTimestamp();
    }

    private function isStalled(): bool
    {
        return $this->progress()['missing'] > 0
            && now()->getTimestamp() >= max($this->lastProgressAt + self::IMPORT_STALL_SECONDS, $this->mountedAt + self::MOUNT_GRACE_SECONDS);
    }

    /**
     * The daily-limit message keeps the fast poll for this long, so an
     * import someone else just started on the same set still shows as
     * loading within seconds.
     */
    private function pastMountGrace(): bool
    {
        return now()->getTimestamp() >= $this->mountedAt + self::MOUNT_GRACE_SECONDS;
    }

    /**
     * Local card count, announced total and the shortfall, read once per
     * request. No announced total (null or 0) means nothing is missing, and
     * a surplus never goes negative.
     *
     * @return array{local: int, total: int, missing: int}
     */
    private function progress(): array
    {
        if ($this->progress === null) {
            $set = Set::where('tcgdex_id', $this->setTcgdexId)->first() ?? throw new NotFoundHttpException;
            $local = $set->cards()->count();
            $total = (int) $set->card_count;

            $this->progress = ['local' => $local, 'total' => $total, 'missing' => $total > 0 ? max(0, $total - $local) : 0];
        }

        return $this->progress;
    }

    public function render(): View
    {
        $progress = $this->progress();
        $stalled = $this->isStalled();

        if ($progress['missing'] > 0) {
            $this->sawImport = true;
        }

        // With no card arriving since the page opened and no way for this
        // user to start the set today, "Loading cards" would promise an
        // import that may not be running and a retry that would be refused.
        $budgetSpent = $progress['missing'] > 0
            && $this->lastProgressAt <= $this->mountedAt
            && app(ImportBudget::class)->isSpentFor((int) auth()->id(), $this->setTcgdexId);

        return view('livewire.admin.set-import-banner', [
            'localCount' => $progress['local'],
            'totalCount' => $progress['total'],
            'missingCount' => $progress['missing'],
            'importing' => $progress['missing'] > 0,
            'stalled' => $stalled,
            'budgetSpent' => $budgetSpent,
            'pollSeconds' => $stalled || ($budgetSpent && $this->pastMountGrace()) ? self::STALLED_POLL_SECONDS : 5,
            'finished' => $this->sawImport && $progress['missing'] === 0,
        ]);
    }
}
