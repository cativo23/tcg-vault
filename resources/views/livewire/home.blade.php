<div class="nw-home">
    <section class="nw-home-hero" data-hero-spotlight>
        <div class="nw-home-spotlight" aria-hidden="true"></div>

        <div class="nw-wrap">
            <div class="nw-home-copy">
                <p class="nw-home-eyebrow">tcg-vault</p>
                <h1 class="nw-home-h1">Track every <span class="accent">card</span>.</h1>
                <p class="nw-home-sub">Real-time pricing, value history, every card with its own photo. Not a spreadsheet.</p>
                <span class="nw-home-cta">Create your account — coming soon</span>
            </div>

            <div class="nw-home-fan">
                <img src="https://assets.tcgdex.net/en/base/base1/4/low.webp" alt="Charizard, Base Set">
                <img src="https://assets.tcgdex.net/en/swsh/cel25/7/low.webp" alt="Flying Pikachu VMAX">
                <img src="https://assets.tcgdex.net/en/sv/sv03.5/006/low.webp" alt="Charizard ex">
                <img src="https://assets.tcgdex.net/en/swsh/cel25/9/low.webp" alt="Surfing Pikachu VMAX">
                <img src="https://assets.tcgdex.net/en/sm/sm115/9/low.webp" alt="Charizard GX">
            </div>
        </div>
    </section>

    <section class="nw-wrap">
        <div class="nw-home-feature">
            <div>
                <p class="nw-home-feature-eyebrow">tcg-vault</p>
                <h2>Real-time pricing</h2>
                <ul>
                    <li>Synced with tcgdex — tcgplayer (USD) and cardmarket (EUR)</li>
                    <li>Watch how your collection's value changes over time</li>
                    <li>Never update anything by hand</li>
                </ul>
            </div>
            <div class="nw-home-feature-visual">
                {{-- A real price, not a placeholder — Mega Darkrai ex (me05-116), pulled from
                     tcgdex on 2026-09-15. Ties directly to the real photo in the feature below,
                     and stays honest: this is a real, sourced snapshot, not an invented figure. --}}
                <div class="chrome">Mega Darkrai ex</div>
                <div class="nw-home-fv-price">
                    <div class="big">$193.30</div>
                    <div class="row"><span>tcgplayer</span><span class="plain">USD</span></div>
                    <div class="row"><span>cardmarket</span><span class="plain">€248.35</span></div>
                </div>
            </div>
        </div>

        <div class="nw-home-feature nw-home-feature--reverse">
            <div>
                <p class="nw-home-feature-eyebrow">tcg-vault</p>
                <h2>Your own photo, not a placeholder</h2>
                <ul>
                    <li>Upload the real photo of your card when you add it</li>
                    <li>tcgdex's official art is the backup, not the other way around</li>
                    <li>Works the same with or without a photo — the data is never missing</li>
                </ul>
            </div>
            <div class="nw-home-feature-visual">
                <div class="chrome">your card</div>
                <div class="nw-home-fv-photo">
                    {{-- A real collector's own photo (uploaded by Carlos, cropped from his physical
                         card in its toploader) next to tcgdex's official art of the SAME card
                         (Mega Darkrai ex, me05-116) — an honest, like-for-like comparison, not two
                         unrelated cards. --}}
                    <img src="{{ asset('images/home/carlos-mega-darkrai.webp') }}" alt="Mega Darkrai ex, photographed by its owner">
                    <img src="https://assets.tcgdex.net/en/me/me05/116/high.webp" alt="Mega Darkrai ex, official art">
                    <div class="label">your photo · official art</div>
                </div>
            </div>
        </div>

        <div class="nw-home-feature">
            <div>
                <p class="nw-home-feature-eyebrow">tcg-vault</p>
                <h2>Complete sets, at a glance</h2>
                <ul>
                    <li>See what you're missing from every set you follow</li>
                    <li>Cards you don't own stay marked, not hidden</li>
                    <li>Progress per set, not just one total number</li>
                </ul>
            </div>
            <div class="nw-home-feature-visual">
                <div class="chrome">sets</div>
                <div class="nw-home-fv-sets">
                    <div class="set-row"><span class="name">Prismatic Evolutions</span><div class="bar"><div style="width:70%"></div></div><span class="pct">70%</span></div>
                    <div class="set-row"><span class="name">Journey Together</span><div class="bar"><div style="width:35%"></div></div><span class="pct">35%</span></div>
                    <div class="set-row"><span class="name">Surging Sparks</span><div class="bar"><div style="width:52%"></div></div><span class="pct">52%</span></div>
                </div>
            </div>
        </div>
    </section>

    <section class="nw-home-coverage">
        <div class="nw-wrap">
            <p class="label">Prices synced with</p>
            <div class="sources">
                <span class="src">tcgdex.dev</span>
                <span class="src">tcgplayer</span>
                <span class="src">cardmarket</span>
            </div>
        </div>
    </section>

    <section class="nw-home-faq">
        <div class="nw-wrap">
            <h2>Frequently asked questions</h2>
            <div class="item">
                <p class="q">Is my data private?</p>
                <p class="a">Yes — your collection is yours. You control exactly what's public and what isn't from your profile.</p>
            </div>
            <div class="item">
                <p class="q">When does registration open?</p>
                <p class="a">Coming soon. Right now tcg-vault runs on a single account — open registration is on the way.</p>
            </div>
            <div class="item">
                <p class="q">Where do the prices come from?</p>
                <p class="a">From tcgdex.dev, which aggregates tcgplayer (USD) and cardmarket (EUR).</p>
            </div>
            <div class="item">
                <p class="q">Does it cost anything?</p>
                <p class="a">No.</p>
            </div>
        </div>
    </section>

    <section class="nw-home-close">
        <h2>Track every <span class="accent">card</span>.</h2>
        <p>Create your account — coming soon.</p>
        <span class="cta">Notify me when it opens</span>
    </section>
</div>
