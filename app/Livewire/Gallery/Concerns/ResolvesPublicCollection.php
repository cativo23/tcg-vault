<?php

declare(strict_types=1);

namespace App\Livewire\Gallery\Concerns;

use App\Models\User;
use App\Modules\Collection\Services\PublicCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every public gallery screen starts the same way: the `{username}`
 * segment either names a real user or the page does not exist. The
 * resolved read model is rebuilt per request (it is cheap: one pluck)
 * rather than stored on the component, so it never rides along in
 * Livewire's snapshot.
 */
trait ResolvesPublicCollection
{
    public User $targetUser;

    protected function resolveTargetUser(string $username): void
    {
        $user = User::where('username', $username)->first();

        // A suspended member's page is hidden, indistinguishable from none.
        if ($user === null || $user->isSuspended()) {
            throw new NotFoundHttpException;
        }

        $this->targetUser = $user;
    }

    protected function publicCollection(): PublicCollection
    {
        return PublicCollection::for($this->targetUser);
    }

    /** The collector's display name for mastheads and titles. */
    protected function collectorName(): string
    {
        // The page was resolved by username, so it is never null here.
        return $this->targetUser->name ?: (string) $this->targetUser->username;
    }

    /**
     * Whether the person currently looking at this page IS the collector
     * it belongs to, not just any logged-in user — every empty-state and
     * "manage this" affordance on a public gallery screen must gate on
     * this, not on auth()->check() alone, or a logged-in visitor viewing
     * someone else's page would get shown controls for their OWN
     * collection instead.
     */
    protected function isOwnerViewing(): bool
    {
        return auth()->check() && auth()->id() === $this->targetUser->id;
    }
}
