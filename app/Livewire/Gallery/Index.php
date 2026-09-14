<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Models\User;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Scopes\TenantScope;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('layouts.public')]
final class Index extends Component
{
    public User $targetUser;

    public function mount(string $username): void
    {
        $user = User::where('username', $username)->first();

        if ($user === null) {
            throw new NotFoundHttpException();
        }

        $this->targetUser = $user;
    }

    public function render()
    {
        // Explicit opt-out of TenantScope: this is a PUBLIC route with no
        // authenticated user, so the scope's own auth()->id() would
        // resolve to null and fail-closed to zero rows — the correct
        // behavior for every OTHER query in this app, but wrong here,
        // where we deliberately want $this->targetUser's rows regardless
        // of who (if anyone) is logged in. This is the same explicit,
        // auditable pattern DatabaseSeeder already uses for its own
        // legitimate need to bypass the scope.
        $publicCollectionIds = Collection::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $this->targetUser->id)
            ->where('is_public', true)
            ->pluck('id');

        $sets = Set::query()
            ->whereHas('cards.collectionItems', function ($query) use ($publicCollectionIds) {
                $query->whereIn('collection_id', $publicCollectionIds);
            })
            ->withCount([
                'cards as owned_card_count' => function ($query) use ($publicCollectionIds) {
                    $query->whereHas('collectionItems', function ($q) use ($publicCollectionIds) {
                        $q->whereIn('collection_id', $publicCollectionIds);
                    });
                },
            ])
            ->get();

        return view('livewire.gallery.index', ['sets' => $sets]);
    }
}
