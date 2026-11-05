@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')

@php
    $setUrl = route('gallery.show', ['username' => $targetUser->username, 'setTcgdexId' => $card->set->tcgdex_id]);
    $tier = Rarity::tier($card->rarity);
    $rarityLabel = Rarity::label($card->rarity);
    // Built here rather than with @if inside the elements: Livewire's morph markers
    // would land inside the markup a test (and the page) reads as one string.
    $setNumber = '#'.$card->local_id.($card->set->card_count ? '/'.$card->set->card_count : '');
    $seriesHtml = $card->set->series ? '<span class="crd-sep" aria-hidden="true">·</span>'.e($card->set->series) : '';
@endphp

<div class="crd-page">
    <nav class="crd-crumbs" aria-label="Breadcrumb">
        <ol>
            <li><a href="{{ route('gallery.index', ['username' => $targetUser->username]) }}">Collection</a></li>
            <li><a href="{{ $setUrl }}">{{ $card->set->name }}</a></li>
            <li aria-current="page"><span class="crd-mono">#{{ $card->local_id }}</span></li>
        </ol>
    </nav>

    <div class="crd-lot">
        @include('partials.card.plate')

        <section class="crd-info" aria-labelledby="crd-name">
            <div class="crd-badges">
                @if ($owned)
                    <span class="crd-badge crd-badge--own"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>In the collection</span>
                    @foreach ($gradedItems->filter(fn ($i) => $i->grade_company && $i->grade_value)->unique(fn ($i) => $i->grade_company.$i->grade_value) as $graded)
                        <span class="crd-badge crd-badge--slab">{{ $graded->grade_company }} {{ $graded->grade_value }}</span>
                    @endforeach
                @else
                    <span class="crd-badge crd-badge--out">Not in the collection</span>
                @endif
                @if ($rarityLabel !== '')
                    <span class="crd-badge{{ $tier !== 'standard' ? ' crd-badge--'.$tier : '' }}">{{ $rarityLabel }}</span>
                @endif
            </div>

            <h1 id="crd-name" class="crd-name">{{ $card->name }}</h1>
            <p class="crd-setline"><a href="{{ $setUrl }}">{{ $card->set->name }}</a><span class="crd-sep" aria-hidden="true">·</span><span class="crd-mono">{{ $setNumber }}</span>{!! $seriesHtml !!}</p>

            <div class="crd-share">
                <button type="button" class="crd-copy" data-crd-copy wire:ignore>
                    <svg class="crd-i-link" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.5 1.5"></path><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.5-1.5"></path></svg>
                    <svg class="crd-i-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>
                    <span class="crd-copy__label">Copy link</span>
                </button>
                <span class="sr-only" role="status" data-crd-copy-status wire:ignore></span>
            </div>

            @if ($switcher)
                <div class="crd-variants" role="group" aria-label="Variants of {{ $card->name }}">
                    @foreach ($pills as $pill)
                        @php $isOn = $pill['key'] === $selected['key']; @endphp
                        <button type="button" wire:key="variant-{{ $pill['key'] }}" class="crd-vpill" aria-pressed="{{ $isOn ? 'true' : 'false' }}"
                            wire:click="selectVariant(@js($pill['key']))">
                            <span class="crd-vl">{{ $pill['label'] }}</span>
                            <span class="crd-vp">{{ $pill['snapshot']?->market_minor !== null ? Money::format($pill['snapshot']->market_minor, $pill['snapshot']->currency) : '—' }}</span>
                            @if ($pill['count'] > 0)
                                <span class="crd-vo" aria-hidden="true">×{{ $pill['count'] }} owned</span>
                                <span class="sr-only">{{ $pill['count'] }} owned</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif

            @include('partials.card.market')
            @include('partials.card.history')
        </section>
    </div>

    @include('partials.card.lower')
    @include('partials.card.related')
</div>

@push('head')
    @vite(['resources/css/collection.css', 'resources/css/card.css'])
@endpush
