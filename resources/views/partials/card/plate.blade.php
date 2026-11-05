@use('App\Modules\Catalog\Support\Rarity')

{{-- The card on a measuring plate. Which view is showing (collector photo or
     official art) is client state (card.js), so Livewire never touches this
     column: a variant or range change leaves it as the visitor set it. Without
     JS the plate is a still picture of the first image. The crop marks and the
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
                <div class="crd-card">
                    @if (Rarity::tier($card->rarity) === 'chase')
                        <span class="crd-ring" aria-hidden="true"></span>
                    @endif
                    @if ($lead)
                        <img src="{{ $lead['url'] }}" alt="{{ $altOf($lead) }}" width="600" height="825" fetchpriority="high" decoding="async" @class(['is-photo' => $lead['kind'] === 'photo']) data-crd-img data-crd-kind="{{ $lead['kind'] }}" data-name="{{ $card->name }}">
                    @else
                        <div class="crd-noimg"><b>{{ $card->name }}</b><span>No image</span></div>
                    @endif
                </div>
            </div>
            <div class="crd-marks" data-crd-marks aria-hidden="true">
                <i class="crd-tick crd-tick--tl"></i><i class="crd-tick crd-tick--tr"></i><i class="crd-tick crd-tick--bl"></i><i class="crd-tick crd-tick--br"></i>
                <i class="crd-rule crd-rule--h"></i><i class="crd-rule crd-rule--v"></i>
                <span class="crd-dim crd-dim--h crd-mono">63 mm</span>
                <span class="crd-dim crd-dim--v crd-mono">88 mm</span>
            </div>
        </div>
    </div>

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
    <p class="sr-only" role="status" data-crd-status></p>
</section>
