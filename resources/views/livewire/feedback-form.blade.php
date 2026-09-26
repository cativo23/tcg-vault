<div
    {{-- The page the member was on when they opened the modal, read from
         the browser since this component is rendered once per layout. --}}
    x-data
    x-on:open-modal.window="if ($event.detail === 'feedback') { $wire.pageUrl = window.location.href }"
>
    <x-modal name="feedback" maxWidth="lg" focusable>
        @if ($sent)
            <div class="p-6" role="status">
                <h2 class="text-lg font-medium" style="color: var(--ink)">{{ __('Thanks — it’s on its way.') }}</h2>
                <p class="mt-1 text-sm" style="color: var(--muted)">{{ __('Replies come to your account email.') }}</p>

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button type="button" wire:click="startOver">{{ __('Send another') }}</x-secondary-button>
                    <x-primary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-primary-button>
                </div>
            </div>
        @else
            <form wire:submit="send" class="p-6">
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
        @endif
    </x-modal>
</div>
