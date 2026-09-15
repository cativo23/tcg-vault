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

        if ($user === null) {
            throw new NotFoundHttpException();
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
        return $this->targetUser->name ?: $this->targetUser->username;
    }
}
