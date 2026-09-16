# Design — tcg-vault

Locked design system. Future Hallmark runs read this file first; pages defer
to it. Amend intentionally — the file is the rule.

Reference implementation: `.superpowers/brainstorm/2045737-1789365953/content/vault-final.html`
(the "Colección" screen, approved by Carlos after a long comparative brainstorm —
see history in that session for everything explicitly rejected along the way:
nightwire/cyberpunk, luxury-vault gold, auction-ledger no-image layouts,
rainbow-holo rings, per-type accent colors, and ~6 rejected font pairings).

## System
- Genre · modern-minimal (restrained, data-forward, one accent)
- Macrostructure · Catalogue-derived dashboard: masthead + stat band + toolbar + uniform card grid
- Theme · custom (vibe: "bone-paper trading floor, one signal green, cards do the talking")
- Axes · paper-band: light (bone, not stark white) / display-style: grotesk-condensed (single family, variable width axis) / accent-hue: warm-neutral base + one cool-green signal

## Tokens (canonical · mirrors `:root` in vault-final.html)
```css
:root {
  --color-paper:      oklch(94% 0.012 85);   /* --bone   #f2efe6 */
  --color-paper-2:    oklch(91% 0.014 85);   /* --bone-2 #e9e5d8 */
  --color-paper-3:    oklch(87% 0.014 85);   /* --bone-3 #ded9c9 */
  --color-ink:        oklch(18% 0.006 85);   /* --ink    #141412 */
  --color-ink-2:      oklch(24% 0.006 85);   /* --ink-2  #23231f */
  --color-muted:      oklch(46% 0.010 85);   /* --muted  #6d6c62 */
  --color-flat:       oklch(64% 0.006 85);   /* --flat   #9a988c — neutral ticker text (down/flat) */
  --color-rule:       rgba(20,20,18,.14);    /* --hair — hairline dividers only */
  --color-accent:     oklch(70% 0.16 155);   /* --signal #37d17f — the ONE accent, means "price up" */
  --color-focus:      var(--color-accent);
  --color-danger:     oklch(52% 0.18 25);    /* --danger #c23b2e — error/destructive UI only, see rule below */
  --color-warning:    oklch(72% 0.15 70);    /* --warning #df911a — "needs attention" UI only, see rule below */

  --font-display: "Archivo", system-ui, -apple-system, "Segoe UI", sans-serif; /* wdth axis: 62 (masthead h1) / 66 (brand, card names) / 70 (card head) / 100 (body) */
  --font-body:    "Archivo", system-ui, -apple-system, "Segoe UI", sans-serif;
  --font-mono:    "Martian Mono", "SFMono-Regular", ui-monospace, monospace;   /* wdth 87.5 — numbers ONLY: prices, card #, dates */

  --ease: cubic-bezier(.2,.8,.2,1);
  --dur-fast: 90ms;   --dur-base: 200ms;  --dur-slow: 700ms;  /* 700ms only for the sleeve-sweep */

  --radius-card: 7px;  --radius-pill: 999px;
}
```

**Non-negotiable rules that came out of the brainstorm, not just taste:**
- **One family does display + body**, using Archivo's variable *width* axis (`font-variation-settings: 'wdth' N`) to get condensed-vs-normal voices instead of pairing a second typeface. `font-stretch` does **not** map this axis in Chrome — always set width via `font-variation-settings`, never `font-stretch`.
- **Martian Mono is reserved for numerals only** (prices, card IDs, dates) — never body text, never headings. That's the entire "outlier" budget.
- **One accent color, period — for price semantics.** `--color-accent` (green) means exactly one thing: "price trending up." Falling/flat prices render in `--color-flat` (neutral), never a second color standing in for price direction. Don't introduce holo/rainbow treatments or per-card-type accent colors — both were explicitly tried and rejected.
- **`--color-danger` (red) is a separate, narrow exception**, added after Carlos flagged the original rule as ambiguous when Phase 2 needed a delete/error color: reserved *exclusively* for destructive actions (delete buttons) and validation errors — never for price direction, never decorative, never introduced as a second "accent" competing with green. If a screen needs to show "this failed" or "this is irreversible," `--color-danger` is correct; if it needs to show "this number went down," that's still `--color-flat`, not red.
- **`--color-warning` (amber) is a third, equally narrow exception**, added after the UX audit found "Review" (a flag meaning *something needs the admin's attention, not that anything failed or will be destroyed*) rendered in the same red as the Delete action right next to it — reusing `--color-danger` for that would blur a real semantic difference: "needs attention" is not "destructive" or "this failed." Reserved *exclusively* for "this needs a look" states (the needs-variant-review flag today). Never for destructive actions (stays `--color-danger`), never for price direction (stays `--color-flat`/`--color-accent`), never applied per-category (e.g. per-rarity) — that would reintroduce the rejected "per-type accent colors" pattern this file already rules out elsewhere.
- **Card images are the content.** Chrome (masthead, stat band, ticker) carries the visual weight so the grid itself can stay plain — bone background, one hairline border, no ornament competing with the artwork.
- **Card hover/interaction cascade split**: the outer wrapper (`.slot`) owns the entrance animation (`opacity`/`transform`, `animation-fill-mode: forwards`); the inner `.card` owns the hover/focus transform (`translateY(-9px) scale(1.05)`, `z-index: 30`). Never put both on the same element — a finished `forwards` animation permanently pins `transform` at higher cascade priority than `:hover`, silently killing the hover effect. This bit us once; don't reintroduce it.
- Every card must render **name/set/price with no image** (`.imgwrap.empty` diagonal-hatch + icon + "Sin imagen") — image is optional, data is not.
- Motion is `transform`/`opacity` only. Full `prefers-reduced-motion: reduce` fallback kills all animation/transition durations and the count-up script.

## CTA / interactive voice
- Primary action (card itself) · full card is the clickable target · `border-radius: 7px` · `box-shadow: 0 0 0 1px ink` resting state
- Toolbar controls · pill segmented control (`--radius-pill`), active state = filled ink pill on bone
- Focus state · `outline: 2px solid var(--color-accent)` offset 3px — same visual treatment as hover, so keyboard users get full parity

## Motion stance
- Staggered entrance (8 cards, ~55ms stagger, 480ms ease each)
- 1 hover primitive (lift + scale) + 1 one-shot sleeve-reflection sweep (700ms, plays once per hover)
- Reduced-motion fallback · all durations → 0, no count-up, hover collapses to a 2px ink ring only

## Exports
The tokens live in `resources/css/app.css` (`:root`), ported verbatim from
`vault-final.html`; that stylesheet is now the source of truth. Two tokens
were added there on top of the mockup's set, both derived from it rather
than new colors: `--paper` (#fbf9f3, the raised tile/panel surface the
mockup hardcoded) and `--signal-deep` (#12a45f, the mockup's darker green
for accent *text* on bone — pure `--signal` fails contrast as type; it stays
the color for fills, dots, rings and the ticker on ink).

## Primitives (2026-09-14 redesign — all `.nw-*` in `app.css`)
The approved mockups are now real Blade, one class family, reused across
every public screen instead of re-styled per page:
- **Masthead** `.nw-masthead` + `.nw-eyebrow` (label · hairline) + `.nw-h1`
  (`.nw-display`, sizes `--md`/`--sm`). The h1 is the *subject* — the
  collector's name on the collection home, the set name on a set, the card
  name on a card. Never a generic word like "Gallery".
- **Stat band** `.nw-stats > .nw-stat > .k/.v` (4-up, 2-up under 720px;
  `.accent` = the one green value; `.v.text` for a name instead of a number).
- **Toolbar** `.nw-toolbar` = `.nw-count` + `.nw-pill-input` / `.nw-pill-select`
  + `.nw-seg` (pill segmented control, `aria-pressed` drives the filled state).
- **Card tile** `<x-card-tile>` = `.nw-slot` (entrance) › `.nw-tile` (hover)
  › `.chead` (#number · rarity shorthand OR slab label) › `.imgwrap` ›
  `.cbody` (`.cname`, `.cset` with `×qty`) › `.ticker` (price + direction).
  `.ghost` = a card in the set the collector does NOT own: dimmed frame,
  bone header, neutral ticker. **Ownership is the default state; absence is
  what gets the treatment** — the old green ring on owned cards is gone.
- **Set card** `.nw-setcard` (logo band, completion row + `.nw-bar`, owned
  value) and the compact `.nw-chip` rail on the collection home.
- **Panel / feed** `.nw-panel` (+ `<x-sparkline>`, inline SVG, no library),
  `.nw-section-head`, `.nw-feed`.
- **Detail** `.nw-detail` (5/7 grid, sticky `.nw-hero`, `.nw-thumbs` for
  photo ↔ official art), `.nw-prices > .nw-price`, `.nw-kv`, `.nw-copy`, `.nw-badge`.
- **Footer** `.nw-footer` — tcgdex attribution + the non-affiliation notice.
- `<x-value-totals>` renders a currency → amount map: first entry big, the
  rest as `<small>`. **Never sum EUR and USD into one number.**

Rules that came out of building it:
- Rarity on a tile is the collector shorthand (`Rarity::abbreviate`: SIR,
  MHR, RR…); the full name lives on the detail page. tcgdex's literal
  `"None"` renders nothing, not "N".
- **Rarity gets a 3-tier visual accent (`Rarity::tier()`), amending the
  "one accent, period" rule above** — added 2026-09-16 after Carlos
  explicitly reconsidered the original "per-type accent colors: tried
  and rejected" call for this specific case (undifferentiated tiles are
  a real usability problem for anyone not already fluent in Pokémon
  rarity abbreviations), and researched how MTG, Hearthstone, and
  Pokémon TCG Pocket itself solve the same problem before deciding —
  all three tie the accent to fixed PRINTED rarity, never fluctuating
  market price, and all follow the same neutral→silver→gold/apex
  escalation. tcg-vault's version:
  - `standard` (Common/Uncommon/Rare) — no accent, unchanged from the
    original "one accent" look.
  - `silver` (Rare Holo, Double Rare, Ultra Rare, Promo, Trainer
    Gallery Rare Holo) — `--rarity-silver` on the chip only.
  - `chase` (Illustration/Special Illustration/Hyper/Mega Hyper/Ace
    Spec/Shiny(Ultra) Rare, Secret Rare) — a restrained holo-foil
    gradient (chip text + a thin gradient hairline around the whole
    tile), reviewed live as a mockup and approved before implementing.
    This is deliberately NOT the "rainbow-holo rings" direction
    rejected during the original brainstorm — Carlos clarified that
    rejection was about that specific execution (a busier, heavier
    treatment), not the concept of a holo/rainbow accent itself.
  - An unrecognized rarity string tiers as `standard` — never guessed
    into `chase`; this is a visual accent, not a value judgment.
  - This still does NOT reopen the door to per-rarity-STRING colors
    (16+ distinct hues) or to a price-driven accent — both were
    considered and explicitly ruled out in the same conversation.
- A graded copy replaces the rarity chip with the slab label (`PSA 10`) in
  `--signal` on ink; on the detail page it is an ink-filled `.nw-badge.slab`.
- The collector's own photo is always the primary image when it exists;
  official art is the alternate view, never the other way round.
- An image that fails to load degrades to the empty-image state (or, for a
  decorative logo, disappears) — `app.js` handles both. Never a broken frame.
- `--signal` in the ticker/arrow means one thing: the resolved price went
  UP versus the previous comparable snapshot (`CardPriceResolver::resolveDelta`).
  Down is `--flat`; no history is a neutral dot.
- Headline numbers count up (`data-countup`, `app.js`), digits only — the
  server-rendered value is the resting DOM, and reduced motion skips it.

## Information architecture (public gallery)
`/` → the owner's gallery. `/{u}/gallery` is **the collection** (every owned
card, stat band, set rail, filters, sort) — the front door. `/{u}/gallery/sets`
slices it by set; `/{u}/gallery/{set}` shows every synced card in one set with
owned first and gaps as ghosts; `/{u}/gallery/{set}/{number}` is the card page;
`/{u}/gallery/activity` is the history (old `/movimientos` 301s there). Nav is
Collection · Sets · Activity, always all three linked.

## Language
- **UI copy: English first**, Spanish is not the default (this reverses the
  Spanish-language mockups used during brainstorming — those were faster to
  iterate on with Carlos, not a product decision). Translate all screens to
  English once the visual design is locked, in a single pass — do not
  translate incrementally mid-brainstorm.
