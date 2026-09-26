<?php

use App\Livewire\Actions\Logout;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user = Auth::user();

        // The database cascade removes the collection's rows but not the
        // photo files they point to, which stay publicly reachable by URL.
        // Paths are read before the delete and the files removed after it,
        // so a failed delete never leaves rows pointing at missing photos.
        $photoPaths = CollectionItem::query()
            ->whereHas('collection', fn ($query) => $query->where('user_id', $user->id))
            ->whereNotNull('photo_path')
            ->pluck('photo_path')
            ->all();

        tap($user, $logout(...))->delete();

        Storage::disk('collection-photos')->delete($photoPaths);

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium" style="color: var(--ink)">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-1 text-sm" style="color: var(--muted)">
            @include('livewire.profile.partials.deletion-consequences')
        </p>
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >{{ __('Delete Account') }}</x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable>
        <form wire:submit="deleteUser" class="p-6">

            <h2 class="text-lg font-medium" style="color: var(--ink)">
                {{ __('Are you sure you want to delete your account?') }}
            </h2>

            <p class="mt-1 text-sm" style="color: var(--muted)">
                @include('livewire.profile.partials.deletion-consequences')
                {{ __('Enter your password to confirm.') }}
            </p>

            <div class="mt-6">
                <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />

                <x-text-input
                    wire:model="password"
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-3/4"
                    placeholder="{{ __('Password') }}"
                />

                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button class="ms-3">
                    {{ __('Delete Account') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
