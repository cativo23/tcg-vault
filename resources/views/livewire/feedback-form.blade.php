<div
    {{-- The page the member was on when they opened the modal, read from
         the browser since this component is rendered once per layout.
         `sent` lives here, client-side: resetting it through the server on
         reopen re-renders the modal and closes it again. --}}
    x-data="{ sent: false }"
    x-on:open-modal.window="if ($event.detail === 'feedback') { $wire.pageUrl = window.location.href; sent = false }"
    {{-- The heading takes focus so screen readers read the "Thanks". The
         x-if panel exists by the time a macrotask runs. --}}
    x-on:feedback-sent.window="sent = true; setTimeout(() => $el.querySelector('#feedback-thanks')?.focus())"
>
    <x-modal name="feedback" maxWidth="lg" focusable>
        {{-- x-if, not x-show: the modal focuses the first focusable element
             in its DOM on open, so this panel must not exist until a send.
             It holds only Alpine markup, never wire: directives. --}}
        <div wire:ignore>{{-- Livewire's morph has no x-if awareness; never let it touch this. --}}
        <template x-if="sent">
        <div class="p-6" role="status">
            <h2 id="feedback-thanks" tabindex="-1" class="text-lg font-medium focus:outline-none" style="color: var(--ink)">{{ __('Thanks — it’s on its way.') }}</h2>
            <p class="mt-1 text-sm" style="color: var(--muted)">{{ __('Replies come to your account email.') }}</p>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="sent = false">{{ __('Send another') }}</x-secondary-button>
                <x-primary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-primary-button>
            </div>
        </div>
        </template>
        </div>

        <form x-show="! sent" wire:submit="send" class="p-6">
            <h2 class="text-lg font-medium" style="color: var(--ink)">{{ __('Send feedback') }}</h2>
            <p class="mt-1 text-sm" style="color: var(--muted)">
                {{ __('Goes straight to the person building tcg-vault. Your username and the page you’re on are included, so you don’t need to describe where you were.') }}
            </p>

            <div class="mt-5">
                <x-input-label for="feedback-type" :value="__('What is it?')" />
                <select id="feedback-type" wire:model="type" class="nw-input w-full mt-1">
                    @foreach (\App\Mail\FeedbackSubmitted::TYPES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('type')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="feedback-message" :value="__('Message')" />
                <textarea id="feedback-message" wire:model="message" rows="5" maxlength="5000" required class="nw-input w-full mt-1"></textarea>
                <x-input-error :messages="$errors->get('message')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="send">{{ __('Send') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
