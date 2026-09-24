<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardSummaryData;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Support\CardVariants;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\CollectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('layouts.app')]
final class AddCollectionItem extends Component
{
    use WithFileUploads;

    public ?int $collectionId = null;

    public string $search = '';

    /**
     * Must match TcgdexCardCatalogProvider::SEARCH_PAGE_SIZE — used only to
     * infer whether a page might have more results (a full page means
     * "maybe more", not a guarantee; tcgdex's search has no total-count
     * field to check against).
     */
    private const RESULTS_PER_PAGE = 24;

    /**
     * Ceiling on how many results loadMoreResults() can accumulate.
     * loadMoreResults() is a public Livewire action a client can invoke
     * directly regardless of what's rendered — hasMoreResults is only a
     * rendering hint (it decides whether the button/scroll-trigger
     * exists), not something that stops the action itself. Without a
     * ceiling enforced inside the action, repeated direct calls would
     * fetch tcgdex pages without limit — unbounded outbound requests
     * (the exact rate-limit/ban risk this app already works to avoid
     * elsewhere) and an ever-growing $results array kept in Livewire's
     * serialized component state.
     */
    private const MAX_RESULTS = self::RESULTS_PER_PAGE * 10;

    /** @var array<int, CardSummaryData> */
    public array $results = [];

    /** Which page of the current search $results currently covers. */
    public int $searchPage = 1;

    /** True when the last page fetched was full, so there might be more. */
    public bool $hasMoreResults = false;

    /**
     * Human set names for the current $results, keyed by setTcgdexId —
     * searchCardsByName() only returns the raw tcgdex set id (e.g.
     * "sv02"), which isn't enough to tell apart the many cards sharing
     * the same name across different sets. Resolved from the local
     * Catalog only — no extra tcgdex round-trip per result — so a set
     * that was never synced locally just falls back to showing its raw
     * code.
     *
     * @var array<string, string>
     */
    public array $resultSetNames = [];

    /**
     * Narrows runSearch() to one set when set — populated from the
     * dropdown, tcgdex-id keyed (e.g. "sv02"). null = "All sets", the
     * same unfiltered behavior as before this feature existed.
     */
    #[Validate('nullable|string|max:32')]
    public ?string $setFilter = null;

    /**
     * Dropdown options: locally synced sets only, tcgdex_id => name.
     * Populated once in mount() — no tcgdex round-trip, per design (a set
     * that hasn't been synced yet simply isn't offered as a filter).
     *
     * @var array<string, string>
     */
    public array $availableSets = [];

    public ?string $selectedTcgdexId = null;

    public ?string $selectedName = null;

    /**
     * Populated in selectCard() from the selected card's actual pricing data
     * — not every card has all 3 known variant values (some are holofoil-only,
     * some have no reverse-holofoil print, etc.), so the dropdown only offers
     * what's real for this specific card. Falls back to the full known list
     * when tcgdex can't be reached, rather than leaving the field unusable.
     *
     * @var array<int, string>
     */
    public array $availableVariants = self::KNOWN_VARIANTS;

    private const KNOWN_VARIANTS = ['normal', 'holofoil', 'reverse-holofoil'];

    /**
     * One row per variant/condition/grading combo being added for the
     * selected card in this single submission — @see save(). Same shape
     * as CollectionItems::$editingRows, minus `id` (nothing here is
     * persisted yet).
     *
     * @var array<int, array{variant: ?string, condition: string, quantity: int, grade_company: ?string, grade_value: ?string, notes: ?string, photo: mixed, showDetails: bool}>
     */
    public array $rows = [];

    /**
     * Ceiling on how many rows a single Crear submission can carry.
     * addRow() is a public Livewire action a client can invoke directly
     * regardless of what's rendered, so this is enforced there too — not
     * just as a `rules()` cap on the final submitted array, which a
     * client manipulating the wire:model payload directly could bypass.
     * Each row triggers its own tcgdex-backed CollectionItem write, so an
     * unbounded array is a resource-exhaustion vector, not just a UI
     * nuisance.
     */
    private const MAX_ROWS = 25;

    private function blankRow(): array
    {
        return [
            'variant' => null,
            'condition' => 'NM',
            'quantity' => 1,
            'grade_company' => null,
            'grade_value' => null,
            'notes' => null,
            'photo' => null,
            'showDetails' => false,
        ];
    }

    protected function rules(): array
    {
        return [
            'rows' => 'array|max:'.self::MAX_ROWS,
            'rows.*.variant' => 'nullable|in:normal,holofoil,reverse-holofoil',
            'rows.*.condition' => 'required|string|max:16',
            'rows.*.quantity' => 'required|integer|min:1|max:9999',
            'rows.*.grade_company' => 'nullable|string|max:32',
            'rows.*.grade_value' => 'nullable|string|max:16',
            'rows.*.notes' => 'nullable|string|max:2000',
            'rows.*.photo' => 'nullable|image|mimes:jpeg,png,webp|max:5120',
        ];
    }

    public function addRow(): void
    {
        if (count($this->rows) >= self::MAX_ROWS) {
            return;
        }

        $this->rows[] = $this->blankRow();
    }

    public function removeRow(int $index): void
    {
        if (count($this->rows) <= 1 || ! isset($this->rows[$index])) {
            return;
        }
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function toggleRowDetails(int $index): void
    {
        if (isset($this->rows[$index])) {
            $this->rows[$index]['showDetails'] = ! $this->rows[$index]['showDetails'];
        }
    }

    public function mount(): void
    {
        $this->availableSets = Set::orderBy('name')->pluck('name', 'tcgdex_id')->all();
        $this->rows = [$this->blankRow()];
    }

    public function runSearch(CardCatalogProvider $provider): void
    {
        $this->resetErrorBag('search');
        $this->searchPage = 1;

        if ($this->search === '') {
            $this->results = [];
            $this->resultSetNames = [];
            $this->hasMoreResults = false;

            return;
        }

        try {
            $this->results = $provider->searchCardsByName($this->search, $this->setFilter ?: null);
            $this->resultSetNames = $this->setNamesFor($this->results);
            $this->hasMoreResults = count($this->results) >= self::RESULTS_PER_PAGE;
        } catch (Throwable $e) {
            report($e);

            $this->addError('search', 'Could not search right now. Please try again.');

            $this->results = [];
            $this->resultSetNames = [];
            $this->hasMoreResults = false;
        }
    }

    /**
     * Fetches the next page and appends it — never replaces $results, so a
     * card from an earlier page stays visible and selectable (selectCard()
     * matches against the full accumulated list).
     */
    public function loadMoreResults(CardCatalogProvider $provider): void
    {
        // Mirrors runSearch()'s own empty-search gate — this is a public
        // action, not just a button behind that same check in the view.
        // hasMoreResults/MAX_RESULTS are enforced here too, not only used
        // to decide whether to render the "Load more" trigger, so a
        // client calling this directly and repeatedly can't outrun them.
        if ($this->search === '' || ! $this->hasMoreResults || count($this->results) >= self::MAX_RESULTS) {
            return;
        }

        $this->searchPage++;

        try {
            $nextPage = $provider->searchCardsByName($this->search, $this->setFilter ?: null, $this->searchPage);
            $this->results = [...$this->results, ...$nextPage];
            $this->resultSetNames = [...$this->resultSetNames, ...$this->setNamesFor($nextPage)];
            $this->hasMoreResults = count($nextPage) >= self::RESULTS_PER_PAGE
                && count($this->results) < self::MAX_RESULTS;
        } catch (Throwable $e) {
            report($e);

            $this->addError('search', 'Could not load more results right now. Please try again.');
            $this->hasMoreResults = false;
        }
    }

    /**
     * @param  array<int, CardSummaryData>  $cards
     * @return array<string, string>
     */
    private function setNamesFor(array $cards): array
    {
        return Set::whereIn(
            'tcgdex_id',
            array_unique(array_map(fn (CardSummaryData $r) => $r->setTcgdexId, $cards)),
        )->pluck('name', 'tcgdex_id')->all();
    }

    public function updatedSetFilter(): void
    {
        // Livewire only coerces a submitted '' to null when the raw
        // assignment throws a TypeError — '' is itself a valid ?string,
        // so it never throws. The <select>'s "All sets" option round-trips
        // as '' over the wire, not null, so without this normalization
        // runSearch() would forward set.id='' to tcgdex instead of
        // omitting the filter entirely.
        $this->setFilter = $this->setFilter === '' ? null : $this->setFilter;

        // Livewire lifecycle hooks (unlike component actions) don't support
        // method injection, so the provider has to be resolved manually here.
        $this->runSearch(app(CardCatalogProvider::class));
    }

    public function selectCard(string $tcgdexId, CardCatalogProvider $provider): void
    {
        $previousTcgdexId = $this->selectedTcgdexId;
        $this->selectedTcgdexId = $tcgdexId;
        $match = collect($this->results)->first(fn (CardSummaryData $c) => $c->tcgdexId === $tcgdexId);
        $this->selectedName = $match?->name;

        // A tcgdex failure here must not block selecting the card — it only
        // narrows the Variant dropdown to what's real for this card, so on
        // failure we fall back to the full known list rather than an empty
        // or broken select.
        try {
            $card = $provider->findCard($tcgdexId);

            // The card's OWN print flags are the real source of truth —
            // not which prices tcgdex happens to have synced (the
            // cardmarket importer names its only foil-tier price
            // 'holofoil' regardless of whether the card actually has a
            // straight holo print or only a reverse-holo one).
            $this->availableVariants = CardVariants::available($card->variants);

            if ($this->availableVariants === []) {
                $prices = collect($card->prices->items())->pluck('variant');
                $this->availableVariants = array_values(array_intersect(self::KNOWN_VARIANTS, $prices->unique()->all()));
            }

            if ($this->availableVariants === []) {
                $this->availableVariants = self::KNOWN_VARIANTS;
            }
        } catch (Throwable $e) {
            report($e);

            $this->availableVariants = self::KNOWN_VARIANTS;
        }

        // A newly-selected card starts a fresh single row — rows typed
        // for whatever card was selected before must not carry over. But
        // re-clicking the SAME already-selected tile (the common case: a
        // user re-confirming their choice) must not silently wipe rows
        // they already typed for it.
        if ($this->selectedTcgdexId !== $previousTcgdexId) {
            $this->rows = [$this->blankRow()];

            if (count($this->availableVariants) === 1) {
                $this->rows[0]['variant'] = $this->availableVariants[0];
            }
        }
    }

    public function save(CollectionService $service): mixed
    {
        $this->validate();

        if ($this->selectedTcgdexId === null) {
            $this->addError('selectedTcgdexId', 'Choose a card from the search results first.');

            return null;
        }

        // Reject two rows in this submission that would collide on the
        // same identity CollectionService::addItem() merges on — silently
        // merging two rows the user typed side by side would drop one of
        // them with no visible error.
        $seen = [];
        foreach ($this->rows as $index => $row) {
            $key = implode('|', [$row['variant'] ?? '', $row['condition'], $row['grade_company'] ?? '', $row['grade_value'] ?? '']);
            if (isset($seen[$key])) {
                $this->addError("rows.$index.variant", 'This is the same variant/condition/grading as another row above — combine them into one row instead.');

                return null;
            }
            $seen[$key] = true;
        }

        $storedPhotoPaths = [];

        try {
            // Collection resolution lives inside this try too: $collectionId
            // is a public Livewire property, so it's client-settable. A
            // foreign ID trips TenantScope and findOrFail() throws a
            // ModelNotFoundException here — caught below like any other
            // save failure, so an IDOR attempt degrades to the same
            // friendly error instead of an uncaught exception.
            $collection = $this->collectionId !== null
                ? Collection::findOrFail($this->collectionId)
                : Collection::firstOrCreate(
                    ['user_id' => auth()->id(), 'slug' => 'my-collection'],
                    ['name' => 'My Collection', 'is_public' => false],
                );

            // Every row in this submission is the SAME card (selectedTcgdexId
            // doesn't change per row) — synced once here, outside the
            // per-item transaction below, rather than once per row inside it.
            // CatalogSyncService::syncCard() commits its own Card/Set/price-
            // snapshot writes independently; nesting N redundant calls to it
            // inside the CollectionItem transaction would mean a later row's
            // failure rolls back that GLOBAL, shared catalog data too, not
            // just this submission's own items.
            $card = $service->syncCardAndQueueImport($this->selectedTcgdexId);

            DB::transaction(function () use ($service, $collection, $card, &$storedPhotoPaths): void {
                foreach ($this->rows as $row) {
                    $photoPath = null;
                    if ($row['photo']) {
                        $photoPath = basename($row['photo']->store('/', 'collection-photos'));
                        $storedPhotoPaths[] = $photoPath;
                    }

                    $service->addItemForCard($collection, $card, [
                        'variant' => $row['variant'],
                        'condition' => $row['condition'],
                        'grade_company' => $row['grade_company'],
                        'grade_value' => $row['grade_value'],
                        'quantity' => $row['quantity'],
                        'notes' => $row['notes'],
                        'photo_path' => $photoPath,
                    ]);
                }
            });
        } catch (Throwable $e) {
            report($e);

            // Storage::store() isn't transactional — a later row's DB
            // failure rolling back the transaction above wouldn't undo
            // earlier rows' already-stored photo files, leaking them on
            // disk forever if they're not cleaned up here.
            foreach ($storedPhotoPaths as $path) {
                Storage::disk('collection-photos')->delete($path);
            }

            $this->addError('selectedTcgdexId', 'Could not add this card right now. Please try again.');

            return null;
        }

        return redirect()->route('admin.collection.index');
    }

    public function render()
    {
        return view('livewire.admin.add-collection-item');
    }
}
