<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardSummaryData;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Support\CardVariants;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\CollectionService;
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

    /** @var array<int, CardSummaryData> */
    public array $results = [];

    /**
     * Human set names for the current $results, keyed by setTcgdexId —
     * searchCardsByName() only returns the raw tcgdex set id (e.g.
     * "sv02"), which isn't enough to tell apart the many cards with the
     * same name across different sets (Carlos, live: "por que hay varios
     * pikachus" — several results, no readable way to tell which was
     * "sv02-062" apart from the others). Resolved from the local Catalog
     * only — no extra tcgdex round-trip per result — so a set that was
     * never synced locally just falls back to showing its raw code.
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

    #[Validate('nullable|in:normal,holofoil,reverse-holofoil')]
    public ?string $variant = null;

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

    #[Validate('required|string|max:16')]
    public string $condition = 'NM';

    #[Validate('nullable|string|max:32')]
    public ?string $gradeCompany = null;

    #[Validate('nullable|string|max:16')]
    public ?string $gradeValue = null;

    #[Validate('required|integer|min:1')]
    public int $quantity = 1;

    #[Validate('nullable|string|max:2000')]
    public ?string $notes = null;

    #[Validate('nullable|image|mimes:jpeg,png,webp|max:5120')]
    public $photo = null;

    public function mount(): void
    {
        $this->availableSets = Set::orderBy('name')->pluck('name', 'tcgdex_id')->all();
    }

    public function runSearch(CardCatalogProvider $provider): void
    {
        $this->resetErrorBag('search');

        if ($this->search === '') {
            $this->results = [];
            $this->resultSetNames = [];

            return;
        }

        try {
            $this->results = $provider->searchCardsByName($this->search, $this->setFilter ?: null);
            $this->resultSetNames = Set::whereIn(
                'tcgdex_id',
                array_unique(array_map(fn (CardSummaryData $r) => $r->setTcgdexId, $this->results)),
            )->pluck('name', 'tcgdex_id')->all();
        } catch (Throwable $e) {
            report($e);

            $this->addError('search', 'Could not search right now. Please try again.');

            $this->results = [];
            $this->resultSetNames = [];
        }
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

        // Only one real variant for this card and nothing chosen yet —
        // default to it instead of making the user pick from a single
        // option. Never overrides an existing value.
        if ($this->variant === null && count($this->availableVariants) === 1) {
            $this->variant = $this->availableVariants[0];
        }
    }

    public function save(CollectionService $service): mixed
    {
        $this->validate();

        if ($this->selectedTcgdexId === null) {
            $this->addError('selectedTcgdexId', 'Choose a card from the search results first.');

            return null;
        }

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

            $photoPath = null;
            if ($this->photo) {
                $storedPath = $this->photo->store('/', 'collection-photos');
                $photoPath = basename($storedPath);
            }

            $service->addItem($collection, $this->selectedTcgdexId, [
                'variant' => $this->variant,
                'condition' => $this->condition,
                'grade_company' => $this->gradeCompany,
                'grade_value' => $this->gradeValue,
                'quantity' => $this->quantity,
                'notes' => $this->notes,
                'photo_path' => $photoPath,
            ]);
        } catch (Throwable $e) {
            report($e);

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
