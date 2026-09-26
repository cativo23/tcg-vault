<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Models\ModerationAction;
use App\Models\User;
use App\Support\AccountDeleter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Where staff act on the terms' "I may suspend or delete accounts":
 * suspending is reversible (the member can't sign in and their page is
 * hidden); deleting removes the account and everything it owns, and has to
 * be confirmed by typing the member's username. Nobody can act on their own
 * account here or on a super-admin, and only a super-admin can act on
 * another staff member (anyone with a permission beyond using a collection).
 */
#[Layout('layouts.app')]
final class MemberManager extends Component
{
    use WithPagination;

    private const PER_PAGE = 24;

    #[Locked]
    public ?int $deletingUserId = null;

    public string $deleteConfirmation = '';

    public function mount(): void
    {
        Gate::authorize('manage-members');
    }

    public function suspend(int $userId): void
    {
        $member = $this->actionableMemberOrNull($userId);

        if ($member !== null) {
            $member->forceFill(['suspended_at' => now()])->save();
            $this->recordModeration('suspended', $member);
        }
    }

    public function unsuspend(int $userId): void
    {
        $member = $this->actionableMemberOrNull($userId);

        if ($member !== null) {
            $member->forceFill(['suspended_at' => null])->save();
            $this->recordModeration('unsuspended', $member);
        }
    }

    public function confirmDelete(int $userId): void
    {
        Gate::authorize('manage-members');

        $this->resetValidation();
        $this->deletingUserId = $userId;
        $this->deleteConfirmation = '';
    }

    public function cancelDelete(): void
    {
        $this->reset('deletingUserId', 'deleteConfirmation');
    }

    public function deleteMember(AccountDeleter $deleter): void
    {
        $member = $this->deletingUserId !== null ? $this->actionableMemberOrNull($this->deletingUserId) : null;

        if ($member === null) {
            return;
        }

        if ($this->deleteConfirmation !== self::confirmationWord($member)) {
            $this->addError('deleteConfirmation', 'That doesn’t match. Type it exactly to confirm.');

            return;
        }

        $this->recordModeration('deleted', $member);
        $deleter->delete($member);

        $this->reset('deletingUserId', 'deleteConfirmation');
    }

    /** What staff type to confirm a delete: the username, or the email when there is none. */
    public static function confirmationWord(User $member): string
    {
        return $member->username ?? $member->email;
    }

    /**
     * Why the signed-in staff member may not suspend or delete this
     * member, or null when they may. Shared by the action guard and the
     * view, so the buttons shown always match what the server allows.
     */
    public static function refusalReason(User $member): ?string
    {
        $actor = auth()->user();

        return match (true) {
            $member->is($actor) => 'You can’t suspend or delete your own account here.',
            $member->hasRole('super-admin') => 'A super-admin can’t be suspended or deleted here.',
            self::isStaff($member) && ! $actor?->hasRole('super-admin') => 'Only a super-admin can suspend or delete another staff member.',
            default => null,
        };
    }

    /** Holds any permission beyond using a collection, directly or through a role. */
    private static function isStaff(User $member): bool
    {
        return $member->getAllPermissions()->pluck('name')->contains(fn (string $name) => $name !== 'use-collection');
    }

    /**
     * Authorizes the caller, then returns the member unless they may not act
     * on it, in which case it records the reason and returns null.
     */
    private function actionableMemberOrNull(int $userId): ?User
    {
        Gate::authorize('manage-members');

        // Found rather than failed: another tab may have deleted them.
        $member = User::find($userId);
        if ($member === null) {
            $this->addError('members', 'That member no longer exists.');

            return null;
        }

        $reason = self::refusalReason($member);

        if ($reason !== null) {
            $this->addError('members', $reason);

            return null;
        }

        return $member;
    }

    /** The only trace of a moderation action, since a delete leaves nothing behind. */
    private function recordModeration(string $action, User $member): void
    {
        ModerationAction::create([
            'actor_id' => auth()->id(),
            'member_id' => $member->id,
            'action' => $action,
        ]);
    }

    public function render(): View
    {
        return view('livewire.staff.member-manager', [
            // Loaded up front: every row checks the member's roles and permissions.
            'members' => User::query()->with(['roles.permissions', 'permissions'])->latest()->orderByDesc('id')->paginate(self::PER_PAGE),
            'deleting' => $this->deletingUserId !== null ? User::find($this->deletingUserId) : null,
        ]);
    }
}
