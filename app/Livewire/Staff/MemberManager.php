<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Models\User;
use App\Support\AccountDeleter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Where staff act on the terms' "I may suspend or delete accounts":
 * suspending is reversible (the member can't sign in and their page is
 * hidden); deleting removes the account and everything it owns, and has to
 * be confirmed by typing the member's username. Nobody can act on their own
 * account here or on a super-admin, and only a super-admin can act on
 * another staff member.
 */
#[Layout('layouts.app')]
final class MemberManager extends Component
{
    use WithPagination;

    private const PER_PAGE = 24;

    public ?int $deletingUserId = null;

    public string $deleteConfirmation = '';

    public function mount(): void
    {
        Gate::authorize('manage-members');
    }

    public function suspend(int $userId): void
    {
        $member = $this->actionableMemberOrNull($userId);

        $member?->forceFill(['suspended_at' => now()])->save();
    }

    public function unsuspend(int $userId): void
    {
        $member = $this->actionableMemberOrNull($userId);

        $member?->forceFill(['suspended_at' => null])->save();
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
            $member->can('manage-members') && ! $actor?->hasRole('super-admin') => 'Only a super-admin can suspend or delete another staff member.',
            default => null,
        };
    }

    /**
     * Authorizes the caller, then returns the member unless they may not act
     * on it, in which case it records the reason and returns null.
     */
    private function actionableMemberOrNull(int $userId): ?User
    {
        Gate::authorize('manage-members');

        $member = User::findOrFail($userId);
        $reason = self::refusalReason($member);

        if ($reason !== null) {
            $this->addError('members', $reason);

            return null;
        }

        return $member;
    }

    public function render(): View
    {
        return view('livewire.staff.member-manager', [
            // Roles are loaded up front: every row checks hasRole('super-admin').
            'members' => User::query()->with('roles')->latest()->paginate(self::PER_PAGE),
            'deleting' => $this->deletingUserId !== null ? User::find($this->deletingUserId) : null,
        ]);
    }
}
