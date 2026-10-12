<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The owner's sets: every set they hold at least one card from, with how
 * many distinct cards they own. Reaches the owner's collections only
 * through Collection's TenantScope, so a private collection works and
 * another user's sets never appear.
 */
#[Layout('layouts.app')]
final class Sets extends Component
{
    public function render(): View
    {
        $collectionIds = Collection::query()->pluck('id');

        $sets = Set::query()
            ->whereHas('cards.collectionItems', fn (Builder $q) => $q->whereIn('collection_id', $collectionIds))
            ->withCount([
                'cards as owned_card_count' => function (Builder $q) use ($collectionIds) {
                    $q->whereHas('collectionItems', fn (Builder $i) => $i->whereIn('collection_id', $collectionIds));
                },
                'cards as real_card_count',
            ])
            // Postgres sorts NULL first on DESC; undated sets belong last.
            ->orderByRaw('released_on desc nulls last')
            ->orderBy('name')
            ->get();

        return view('livewire.admin.sets', ['sets' => $sets]);
    }
}
