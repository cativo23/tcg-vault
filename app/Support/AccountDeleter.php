<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Scopes\TenantScope;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes an account and everything it owns. The database cascade removes
 * the collection's rows and the accepted invite, but not the photo files
 * they point to, which would stay publicly reachable by URL.
 *
 * The photo paths are found through the user's own collections with
 * TenantScope removed: the scope filters on whoever is signed in, which is
 * a staff member (or nobody) when this isn't a self-delete. They are read
 * before the delete and the files removed after it, so a failed delete
 * never leaves rows pointing at missing photos.
 */
final class AccountDeleter
{
    public function delete(User $user): void
    {
        $collectionIds = Collection::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $user->id)
            ->pluck('id');

        $photoPaths = CollectionItem::query()
            ->whereIn('collection_id', $collectionIds)
            ->whereNotNull('photo_path')
            ->pluck('photo_path')
            ->all();

        $user->delete();

        Storage::disk('collection-photos')->delete($photoPaths);
    }
}
