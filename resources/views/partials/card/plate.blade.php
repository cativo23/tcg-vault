@use('App\Modules\Catalog\Support\Rarity')

{{-- The card on a measuring plate. Which view is showing (collector photo or
     official art) is client state (card.js), and so are the size, the loupe
     and the calibration (plate.js), so Livewire never touches this column: a
     variant or range change leaves it as the visitor set it. Without JS the
     plate is a still picture of the first image. The crop marks and the
     63 mm / 88 mm labels are drawn by CSS around the card. A photo is not
     printed at 63 mm wide, so its view hides the marks and the ring. --}}
@php
    $lead = $images[0] ?? null;
    $nameLine = $card->name.', '.$card->set->name.' #'.$card->local_id;
    $altOf = fn (array $image): string => $image['kind'] === 'photo' ? 'Your photo of '.$nameLine : $nameLine;
    $hasPhoto = collect($images)->contains(fn (array $image): bool => $image['kind'] === 'photo');
@endphp
<section class="crd-plate-col" aria-label="Card image" wire:ignore>
    <div class="crd-plate" data-crd-plate data-view="{{ $lead['kind'] ?? 'official' }}">
        <div class="crd-pos" data-crd-pos>
            <div class="crd-drop">
                <div class="crd-card" data-crd-tilt>
                    @if (Rarity::tier($card->rarity) === 'chase')
                        <span class="crd-ring" aria-hidden="true"></span>
                    @endif
                    @if ($lead)
                        <img src="{{ $lead['url'] }}" alt="{{ $altOf($lead) }}" width="600" height="825" fetchpriority="high" decoding="async" @class(['is-photo' => $lead['kind'] === 'photo']) data-crd-img data-crd-kind="{{ $lead['kind'] }}" data-name="{{ $card->name }}">
                    @else
                        <div class="crd-noimg"><b>{{ $card->name }}</b><span>No image</span></div>
                    @endif
                    <span class="crd-glare" aria-hidden="true"></span>
                </div>
            </div>
            <div class="crd-marks" data-crd-marks aria-hidden="true">
                <i class="crd-tick crd-tick--tl"></i><i class="crd-tick crd-tick--tr"></i><i class="crd-tick crd-tick--bl"></i><i class="crd-tick crd-tick--br"></i>
                <i class="crd-rule crd-rule--h"></i><i class="crd-rule crd-rule--v"></i>
                <span class="crd-dim crd-dim--h crd-mono">63 mm</span>
                <span class="crd-dim crd-dim--v crd-mono">88 mm</span>
            </div>
        </div>
        <div class="crd-loupe" data-crd-loupe aria-hidden="true"></div>
    </div>

    @if ($lead)
        <div class="crd-tools">
            <div class="crd-sizes" role="group" aria-label="Image size" data-crd-size-group>
                <button type="button" data-crd-size="fit" aria-pressed="true">Fitted</button>
                <button type="button" data-crd-size="actual" aria-pressed="false">Actual size</button>
            </div>
            <button type="button" class="crd-tool" data-crd-loupe-toggle aria-pressed="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"></circle><path d="M15.5 15.5l5 5"></path></svg>
                Loupe
            </button>
        </div>
    @endif

    @if ($lead && count($images) > 1)
        <div class="crd-views" role="group" aria-label="Views of this card">
            @foreach ($images as $i => $image)
                <button type="button" class="crd-view" data-crd-view="{{ $image['url'] }}" data-crd-kind="{{ $image['kind'] }}" data-crd-alt="{{ $altOf($image) }}" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}">
                    <img src="{{ $image['url'] }}" alt="" width="30" height="41" loading="lazy" decoding="async">
                    <span>{{ $image['label'] }}</span>
                </button>
            @endforeach
        </div>
    @endif
    @if ($hasPhoto)
        {{-- Shown while the photo is on the plate; card.js hides it on the official art. --}}
        <p class="crd-note" data-crd-note>Your photo of this copy. The printed size applies to the official art.</p>
    @endif
    @if ($lead)
        <p class="crd-tool-note" data-crd-tool-note></p>
        <div class="crd-calib" id="crd-calib" data-crd-calib hidden>
            <label for="crd-cal">Hold a real card against the screen and drag until the marks match its edges.</label>
            <div class="crd-cal-row">
                <input id="crd-cal" type="range" min="0.6" max="1.6" step="0.005" value="1" data-crd-cal>
                <span class="crd-mono" data-crd-cal-value>100%</span>
                <button type="button" class="crd-inline" data-crd-cal-reset>Reset</button>
            </div>
        </div>
    @endif
    <p class="sr-only" role="status" data-crd-status></p>
</section>
