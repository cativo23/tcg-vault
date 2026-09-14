<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardSummaryData;
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

    public ?string $selectedTcgdexId = null;

    public ?string $selectedName = null;

    #[Validate('nullable|string|max:64')]
    public ?string $variant = null;

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

    public function runSearch(CardCatalogProvider $provider): void
    {
        if ($this->search === '') {
            $this->results = [];

            return;
        }

        try {
            $this->results = $provider->searchCardsByName($this->search);
        } catch (Throwable $e) {
            report($e);

            $this->addError('search', 'Could not search right now. Please try again.');

            $this->results = [];
        }
    }

    public function selectCard(string $tcgdexId): void
    {
        $this->selectedTcgdexId = $tcgdexId;
        $match = collect($this->results)->first(fn (CardSummaryData $c) => $c->tcgdexId === $tcgdexId);
        $this->selectedName = $match?->name;
    }

    public function save(CollectionService $service): mixed
    {
        $this->validate();

        if ($this->selectedTcgdexId === null) {
            $this->addError('selectedTcgdexId', 'Choose a card from the search results first.');

            return null;
        }

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

        try {
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
