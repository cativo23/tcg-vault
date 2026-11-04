@php($demoUrl = $exampleUsername ? route('gallery.index', ['username' => $exampleUsername]) : null)

<div class="story" id="story">

    {{-- One pinned stage. The same sleeved cards travel through every act. --}}
    <div class="stage" id="stage" aria-hidden="true"></div>

    <div class="acts" id="acts">
        <section class="act act--hero" data-act="hero" aria-labelledby="h-hero">
            <div class="copy">
                <h1 id="h-hero" class="display">Every card you&nbsp;own, on one page.</h1>
                <p class="lede">Make a list of your Pokémon cards, from the chase card to the bulk commons. tcg-vault shows each one with its picture and today's price, and gives your whole collection a link you can send to anyone.</p>
                <div class="cta">
                    @if ($registrationOpen)
                        <a class="btn" href="{{ route('register') }}">Create your account</a>
                        @if ($demoUrl)<a class="btn-2" href="{{ $demoUrl }}">See the demo collection</a>@endif
                    @else
                        @if ($demoUrl)<a class="btn" href="{{ $demoUrl }}">See the demo collection</a>@endif
                        <p class="cta-note">Accounts are by invitation for now.</p>
                    @endif
                </div>
            </div>
            <div class="still-fig" aria-hidden="true" data-pose="stack"></div>
        </section>

        <section class="act act--gallery" data-act="gallery" aria-labelledby="h-gallery">
            <div class="copy">
                <h2 id="h-gallery" class="h2">Send a link instead of a spreadsheet.</h2>
                <p>Your page lives at <span class="url">tcgvault.cativo.dev/yourname</span>. Anyone who opens it sees your cards set by set and what they're worth. They don't need an account.</p>
            </div>
            <div class="still-fig" aria-hidden="true" data-pose="sets"></div>
        </section>

        <section class="act act--prices" data-act="prices" aria-labelledby="h-prices">
            <div class="copy">
                <h2 id="h-prices" class="h2">Prices update every day.</h2>
                <p>Each card gets a tcgplayer price in dollars and a Cardmarket price in euros. The total at the top of your page is today's number, and a chart shows how it has moved.</p>
            </div>
            <div class="still-fig" aria-hidden="true" data-pose="prices"></div>
        </section>

        <section class="act act--set" data-act="set" aria-labelledby="h-set">
            <div class="copy">
                <h2 id="h-set" class="h2">Open a set to see what's missing.</h2>
                <p>The cards you have show in color. The ones you don't stay in place, greyed out, with their price, so you can see what you still need and what it costs.</p>
            </div>
            <div class="still-fig" aria-hidden="true" data-pose="set"></div>
        </section>

        <section class="act act--close" data-act="close" aria-labelledby="h-close">
            <div class="copy">
                <h2 id="h-close" class="h2">For now, it's a place for your collection.</h2>
                <p>Building decks from your cards or marking some for sale are ideas for later. Today it keeps your list priced and makes it look good when you share it.</p>
            </div>
            <div class="still-fig" aria-hidden="true" data-pose="close"></div>
            <div class="closing">
                <p class="closing-line">Free. Export your whole collection to CSV at any time.</p>
                <div class="cta">
                    @if ($registrationOpen)
                        <a class="btn" href="{{ route('register') }}">Create your account</a>
                        @if ($demoUrl)<a class="btn-2" href="{{ $demoUrl }}">See the demo collection</a>@endif
                    @else
                        @if ($demoUrl)<a class="btn" href="{{ $demoUrl }}">See the demo collection</a>@endif
                        <p class="cta-note">Accounts are by invitation for now.</p>
                    @endif
                </div>
            </div>
        </section>
    </div>
</div>

@push('scripts')
    @vite('resources/js/landing-stage.js')
@endpush
