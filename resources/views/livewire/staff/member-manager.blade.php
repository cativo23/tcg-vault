<div class="nw-wrap py-10">
    <h1 class="nw-display nw-h1 nw-h1--sm mb-4">Members</h1>

    <p class="mb-6 max-w-2xl text-sm" style="color: var(--muted)">
        {{ __('Suspending blocks sign-in and hides the member’s page until you lift it. Deleting removes the account, its whole collection and its photos, and can’t be undone. The terms promise an email first, except for serious or illegal abuse.') }}
    </p>

    @error('members') <p class="text-sm mb-4" style="color: var(--danger)" role="alert">{{ $message }}</p> @enderror

    @if ($deleting)
        <div class="nw-card p-5 mb-6 max-w-2xl" role="region" aria-labelledby="delete-member-title">
            <h2 id="delete-member-title" class="font-medium mb-2">
                {{ __('Delete :name’s account?', ['name' => \App\Livewire\Staff\MemberManager::confirmationWord($deleting)]) }}
            </h2>
            <p class="text-sm mb-4" style="color: var(--muted)">
                {!! __('This permanently deletes their account, collection, notes and photos. Type :word to confirm.', [
                    'word' => '<strong style="color: var(--ink)">'.e(\App\Livewire\Staff\MemberManager::confirmationWord($deleting)).'</strong>',
                ]) !!}
            </p>
            <form wire:submit="deleteMember" class="flex items-end gap-3">
                <div class="flex-1">
                    <x-input-label for="deleteConfirmation" :value="__('Confirmation')" />
                    <x-text-input wire:model="deleteConfirmation" id="deleteConfirmation" class="block mt-1 w-full" type="text" autocomplete="off" autofocus required />
                    <x-input-error :messages="$errors->get('deleteConfirmation')" class="mt-2" />
                </div>
                <button type="submit" class="nw-row-btn nw-row-btn--danger">{{ __('Delete account') }}</button>
                <button type="button" wire:click="cancelDelete" class="nw-row-btn">{{ __('Cancel') }}</button>
            </form>
        </div>
    @endif

    <div class="nw-card overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="nw-topbar text-left">
                    <th class="p-3">{{ __('Member') }}</th>
                    <th class="p-3">{{ __('Email') }}</th>
                    <th class="p-3">{{ __('Joined') }}</th>
                    <th class="p-3">{{ __('Status') }}</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($members as $member)
                    <tr wire:key="member-{{ $member->id }}" class="border-t" style="border-color: var(--hair)">
                        <td class="p-3 font-medium">{{ $member->username ?? $member->name }}</td>
                        <td class="p-3" style="color: var(--muted)">{{ $member->email }}</td>
                        <td class="p-3" style="color: var(--muted)">{{ $member->created_at?->diffForHumans() }}</td>
                        <td class="p-3">{{ $member->isSuspended() ? __('Suspended') : __('Active') }}</td>
                        <td class="p-3 text-right whitespace-nowrap">
                            @if (\App\Livewire\Staff\MemberManager::refusalReason($member) === null)
                                @if ($member->isSuspended())
                                    <button type="button" wire:click="unsuspend({{ $member->id }})" class="nw-row-btn">{{ __('Lift suspension') }}</button>
                                @else
                                    <button type="button" wire:click="suspend({{ $member->id }})" class="nw-row-btn">{{ __('Suspend') }}</button>
                                @endif
                                <button type="button" wire:click="confirmDelete({{ $member->id }})" class="nw-row-btn nw-row-btn--danger">{{ __('Delete') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-center" style="color: var(--muted)">{{ __('No members on this page.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $members->links() }}
    </div>
</div>
