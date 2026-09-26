{{-- Neutral age screen (App\Livewire\Concerns\ScreensAge): nothing here
     names the cutoff, and the date is only checked, never stored. --}}
<fieldset class="mt-4">
    <legend class="block font-medium text-sm" style="color: var(--muted)">{{ __('Date of birth') }}</legend>
    <div class="mt-1 flex gap-3">
        <div class="flex-1">
            <label for="birth_month" class="sr-only">{{ __('Birth month') }}</label>
            <select wire:model="birth_month" id="birth_month" name="birth_month" required autocomplete="bday-month" class="nw-input block w-full">
                <option value="">{{ __('Birth month') }}</option>
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}">{{ \Illuminate\Support\Carbon::create(2000, $m, 1)->translatedFormat('F') }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex-1">
            <label for="birth_year" class="sr-only">{{ __('Birth year') }}</label>
            <x-text-input wire:model="birth_year" id="birth_year" name="birth_year" class="block w-full" type="text" inputmode="numeric" maxlength="4" placeholder="{{ __('Year') }}" required autocomplete="bday-year" />
        </div>
    </div>
    <x-input-error :messages="$errors->get('birth_month')" class="mt-2" />
    <x-input-error :messages="$errors->get('birth_year')" class="mt-2" />
</fieldset>

<div class="mt-4">
    <label for="guardian_consent" class="inline-flex items-start gap-2 text-sm" style="color: var(--ink)">
        <input wire:model="guardian_consent" id="guardian_consent" type="checkbox" class="mt-1 rounded shadow-sm" style="color: var(--chrome-bg); border-color: var(--hair)">
        <span>{{ __('If I’m under 18, a parent or guardian agrees to me using tcg-vault.') }}</span>
    </label>
    <x-input-error :messages="$errors->get('guardian_consent')" class="mt-2" />
</div>
