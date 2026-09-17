<div>
    <div class="max-w-2xl">
        <form wire:submit="createInvite" class="flex items-end gap-3">
            <div class="flex-1">
                <x-input-label for="email" :value="__('Invite email')" />
                <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <x-primary-button>{{ __('Send invite') }}</x-primary-button>
        </form>
    </div>

    <table class="mt-8 w-full text-left" style="color: var(--ink)">
        <thead>
            <tr>
                <th class="pb-2">{{ __('Email') }}</th>
                <th class="pb-2">{{ __('Status') }}</th>
                <th class="pb-2">{{ __('Expires') }}</th>
                <th class="pb-2">{{ __('Link') }}</th>
                <th class="pb-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invites as $invite)
                <tr>
                    <td class="py-1">{{ $invite->email }}</td>
                    <td class="py-1">
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
                    <td class="py-1">{{ $invite->expires_at->diffForHumans() }}</td>
                    <td class="py-1 max-w-xs truncate">
                        @if ($invite->isUsable())
                            {{-- Deterministic from the invite's own id/email/expiry — recomputed
                                 on every render rather than stored, so it's always the real,
                                 currently-valid link, never a stale one from creation time. --}}
                            <input type="text" readonly value="{{ $invite->signedUrl() }}"
                                   onclick="this.select()" class="w-full text-xs bg-transparent border-0 p-0"
                                   style="color: var(--muted)">
                        @endif
                    </td>
                    <td class="py-1">
                        @if ($invite->isUsable())
                            <button type="button" wire:click="revokeInvite({{ $invite->id }})" class="nw-link underline text-sm">
                                {{ __('Revoke') }}
                            </button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
