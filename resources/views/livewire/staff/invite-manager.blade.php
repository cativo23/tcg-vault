<div class="nw-wrap py-10">
    <h1 class="nw-display nw-h1 nw-h1--sm mb-4">Invites</h1>

    <div class="nw-card p-5 mb-6 max-w-2xl">
        <form wire:submit="createInvite" class="flex items-end gap-3">
            <div class="flex-1">
                <x-input-label for="email" :value="__('Invite email')" />
                <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <x-primary-button>{{ __('Send invite') }}</x-primary-button>
        </form>
    </div>

    <div class="nw-card overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="nw-topbar text-left">
                    <th class="p-3">{{ __('Email') }}</th>
                    <th class="p-3">{{ __('Status') }}</th>
                    <th class="p-3">{{ __('Expires') }}</th>
                    <th class="p-3">{{ __('Link') }}</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invites as $invite)
                    <tr wire:key="invite-{{ $invite->id }}" class="border-t" style="border-color: var(--hair)">
                        <td class="p-3 font-medium">{{ $invite->email }}</td>
                        <td class="p-3">
                            @if ($invite->revoked_at)
                                {{ __('Revoked') }}
                            @elseif ($invite->used_at)
                                {{ __('Accepted') }}
                            @elseif ($invite->isUsable())
                                {{ __('Pending') }}
                            @else
                                {{ __('Expired') }}
                            @endif
                        </td>
                        <td class="p-3" style="color: var(--muted)">{{ $invite->expires_at->diffForHumans() }}</td>
                        <td class="p-3 max-w-xs truncate">
                            @if ($invite->isUsable())
                                {{-- Deterministic from the invite's own id/email/expiry — recomputed
                                     on every render rather than stored, so it's always the real,
                                     currently-valid link, never a stale one from creation time. --}}
                                <input type="text" readonly value="{{ $invite->signedUrl() }}"
                                       onclick="this.select()" class="w-full text-xs bg-transparent border-0 p-0"
                                       style="color: var(--muted)">
                            @else
                                <span style="color: var(--muted)">—</span>
                            @endif
                        </td>
                        <td class="p-3 text-right">
                            @if ($invite->isUsable())
                                <button type="button" wire:click="revokeInvite({{ $invite->id }})" class="text-xs" style="color: var(--danger)">
                                    {{ __('Revoke') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-center" style="color: var(--muted)">No invites yet — send your first one above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $invites->links() }}
    </div>
</div>
