# Design — tcg-vault

Locked design system. Future Hallmark runs read this file first; pages defer
to it. Amend intentionally — the file is the rule.

The tokens and rules below came out of a long comparative brainstorm (Carlos
approved the "Colección" screen after rejecting several other directions
along the way: nightwire/cyberpunk, luxury-vault gold, auction-ledger
no-image layouts, per-type accent colors as a blanket rule, and ~6 font
pairings) — that working history has since been cleaned up; this file is
the current, standalone source of truth.

## System
- Genre · modern-minimal (restrained, data-forward, one accent)
- Macrostructure · Collector's binder: one summary line + set divider tabs + cards on a stock sheet with hang tags (2026-11 revamp; replaces the masthead + stat band + toolbar dashboard)
- Theme · custom (vibe: "bone-paper trading floor, one signal green, cards do the talking")
- Axes · paper-band: light (bone, not stark white) / display-style: grotesk-variable-width (Anybody display + Hanken Grotesk body + Martian Mono numerals) / accent-hue: warm-neutral base + one cool-green signal

## Tokens (canonical · mirrors `:root` in `resources/css/app.css`)
```css
:root {
  --color-paper:      oklch(94% 0.012 85);   /* --bone   #f2efe6 */
  --color-paper-2:    oklch(91% 0.014 85);   /* --bone-2 #e9e5d8 */
  --color-paper-3:    oklch(87% 0.014 85);   /* --bone-3 #ded9c9 */
  --color-ink:        oklch(18% 0.006 85);   /* --ink    #141412 */
  --color-ink-2:      oklch(24% 0.006 85);   /* --ink-2  #23231f */
  --color-muted:      oklch(46% 0.010 85);   /* --muted  #6d6c62 */
  --color-stock:      oklch(98.2% 0.007 90); /* raised card-stock: sheets, tags, slips (replaces --paper) */
  --color-flat:       oklch(52% 0.008 85);   /* neutral price text (down/flat); dark enough for 11px type to pass AA on stock */
  --color-rule:       oklch(18% 0.006 85 / .14); /* hairline dividers only */
  --color-rule-2:     oklch(18% 0.006 85 / .32); /* stronger rule: tabs, tag outlines */
  --color-accent:     oklch(70% 0.16 155);   /* --signal #37d17f — the ONE accent, means "price up" (fills, dots) */
  --color-accent-deep: oklch(50% 0.13 155);  /* the same green as TEXT on bone/stock */
  --color-focus:      var(--color-accent-deep);
  --color-danger:     oklch(52% 0.18 25);    /* --danger #c23b2e — error/destructive UI only, see rule below */
  --color-warning:    oklch(72% 0.15 70);    /* --warning #df911a — "needs attention" UI only, see rule below */

  --font-display: "Anybody", "Archivo", system-ui, sans-serif;  /* wdth axis, set via font-variation-settings */
  --font-body:    "Hanken Grotesk", system-ui, sans-serif;
  --font-mono:    "Martian Mono", ui-monospace, monospace;      /* wdth 87.5 — numbers ONLY: prices, card #, dates */

  --ease-out: cubic-bezier(.2,.8,.2,1);
  --ease-settle: cubic-bezier(.22,1,.36,1);
  --dur-fast: 120ms;  --dur-base: 240ms;  --dur-slow: 520ms;

  /* Plastic, paper and shadow materials (colour values live in tokens.css of the revamp mockups and in app.css) */
  --color-sleeve-fill / -edge / -seam, --color-sheen, --color-highlight(-hi), --color-glint(-hi),
  --color-binder-page, --color-grain, --color-string
  --shadow-rest, --shadow-lift, --shadow-sheet   /* ink-tinted contact + ambient, never coloured */

  --radius-pill: 999px;  --radius-tag: 3px;  --radius-tab: 8px 8px 0 0;
  --space-2xs..2xl: 4 / 8 / 12 / 16 / 24 / 40 / 64px;  --gutter: clamp(16px, 4vw, 56px);
  --text-2xs..md: .6875 / .75 / .875 / 1.0625rem;  --text-display / -h2 / -close: fluid, capped by viewport height
}

/* Dark theme: surfaces near-black, never pure black. Cards stay the colour. */
:root[data-theme='dark'] {   /* also applied by prefers-color-scheme when nothing is stored */
  --color-paper: oklch(18.5% .004 85);  --color-paper-2: oklch(21.5% .005 85);  --color-paper-3: oklch(25.5% .006 85);
  --color-stock: oklch(27% .006 85);
  --color-ink: oklch(95% .012 85);  --color-ink-2: oklch(88% .012 85);
  --color-muted: oklch(65% .012 85);  --color-flat: oklch(74% .012 85);
  --color-rule: oklch(95% .012 85 / .14);  --color-rule-2: oklch(95% .012 85 / .30);
  --color-accent-deep: oklch(82% .17 155);  --color-focus: oklch(82% .17 155);
  --color-binder-page: oklch(23.5% .006 85);
}
```

**Non-negotiable rules, not just taste — a few have since been amended
with narrow, explicit exceptions (marked below), never loosened wholesale:**
- **Two families plus mono.** Anybody is display only (headings, brand, card names), driven by its *width* axis (`font-variation-settings: 'wdth' N`) for condensed-vs-normal voices; Hanken Grotesk is body and UI text. `font-stretch` does **not** map this axis in Chrome — always set width via `font-variation-settings`, never `font-stretch`. (Archivo, the original single family, is retained only as the display fallback.)
- **Martian Mono is reserved for numerals only** (prices, card IDs, dates) — never body text, never headings. That's the entire "outlier" budget.
- **One accent color, period — for price semantics.** `--color-accent` (green) means exactly one thing: "price trending up." Falling/flat prices render in `--color-flat` (neutral), never a second color standing in for price direction. A blanket, unbounded "holo/rainbow treatments and per-card-type accent colors" rule was tried and rejected during the original brainstorm — but see the Rarity accent below for the one narrow, bounded exception since carved out of it (tied to fixed printed rarity, 3 tiers only, never price-driven).
- **`--color-danger` (red) is a separate, narrow exception**, added after Carlos flagged the original rule as ambiguous when Phase 2 needed a delete/error color: reserved *exclusively* for destructive actions (delete buttons) and validation errors — never for price direction, never decorative, never introduced as a second "accent" competing with green. If a screen needs to show "this failed" or "this is irreversible," `--color-danger` is correct; if it needs to show "this number went down," that's still `--color-flat`, not red.
- **`--color-warning` (amber) is a third, equally narrow exception**, added after the UX audit found "Review" (a flag meaning *something needs the admin's attention, not that anything failed or will be destroyed*) rendered in the same red as the Delete action right next to it — reusing `--color-danger` for that would blur a real semantic difference: "needs attention" is not "destructive" or "this failed." Reserved *exclusively* for "this needs a look" states (the needs-variant-review flag today). Never for destructive actions (stays `--color-danger`), never for price direction (stays `--color-flat`/`--color-accent`), never applied per-category (e.g. per-rarity) — that would reintroduce the rejected "per-type accent colors" pattern this file already rules out elsewhere.
- **Card images are the content.** Chrome (masthead, stat band, ticker) carries the visual weight so the grid itself can stay plain — bone background, one hairline border, no ornament competing with the artwork.
- **Card hover/interaction cascade split**: the outer wrapper (`.slot`) owns the entrance animation (`opacity`/`transform`, `animation-fill-mode: forwards`); the inner `.card` owns the hover/focus transform (`translateY(-9px) scale(1.05)`, `z-index: 30`). Never put both on the same element — a finished `forwards` animation permanently pins `transform` at higher cascade priority than `:hover`, silently killing the hover effect. This bit us once; don't reintroduce it.
- Every card must render **name/set/price with no image** (`.imgwrap.empty` diagonal-hatch + icon + "Sin imagen") — image is optional, data is not.
- Motion is `transform`/`opacity` only. Full `prefers-reduced-motion: reduce` fallback kills all animation/transition durations.
- **Down is neutral everywhere.** A falling price, a down move in Activity, a down arrow: all `--color-flat`, no arrow colour. Green means "price up" and nothing else.

## CTA / interactive voice
- Primary action (card itself) · full card is the clickable target · `border-radius: 7px` · `box-shadow: 0 0 0 1px ink` resting state
- Toolbar controls · pill segmented control (`--radius-pill`), active state = filled ink pill on bone
- Focus state · `outline: 2px solid var(--color-accent)` offset 3px — same visual treatment as hover, so keyboard users get full parity

## Motion stance
- App pages: `--dur-fast`/`--dur-base` for hover, focus and state changes; staggered card entrance (~55ms stagger, `--dur-slow` max) with `--ease-out`; a one-off page entrance (the collection and Activity heads, the Activity day sheets and chart cover) may use `--ease-settle`, never longer than `--dur-slow`.
- Landing only: the scroll choreography may run entrances of 460–900 ms with `--ease-settle`. It is the one place longer timings are allowed.
- 1 hover primitive (lift + scale) + 1 one-shot sleeve-reflection sweep (plays once per hover)
- Reduced-motion fallback · all durations → 0, hover collapses to a 2px ink ring only

## Exports
The tokens live in `resources/css/app.css` (`:root`), which is the source of
truth — this file documents intent and rules, `app.css` is the exact values.
Two tokens exist there beyond the set above, both derived from it rather
than new colors: `--paper` (#fbf9f3, the raised tile/panel surface) and
`--signal-deep` (#12a45f, a darker green for accent *text* on bone — pure
`--signal` fails contrast as type; it stays the color for fills, dots,
rings and the ticker on ink).

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
- **Link** `.nw-link` — navigation-style link or button: ink text, no underline,
  a 2px transparent bottom border that turns `--signal` with `.is-active`
  (the current nav item). Fades on hover; recolored to the chrome foreground
  inside `.nw-topbar`.
- **Inline link** `.nw-inline-link` — a link inside running text or an empty
  state: ink text, always underlined (3px offset, same as the legal pages).
  Use it instead of `.nw-link` whenever the link must read as a link
  without nav chrome.
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
  decorative logo, disappears). The collection page's tiles use
  `resources/js/collection.js` (retry `low.webp` once as `high.webp`, then the
  "No image" face); old-layout pages keep `app.js`. Never a broken frame.
- `--signal` in the ticker/arrow means one thing: the resolved price went
  UP versus the previous comparable snapshot (`CardPriceResolver::resolveDelta`).
  Down is `--flat`; no history is a neutral dot. On the revamp collection page the hang tag's move is against the same print seven days earlier (`CardPriceResolver::weekDeltaFor`), not the previous snapshot; the rule is otherwise the same.
- Headline numbers render as their real value; no count-up (retired, see Revamp primitives).

## Revamp primitives (2026-11, public pages)
Materials, not boxes: the page is a collector's binder and every primitive is a
physical thing a collector already knows. Built only from the tokens above.
- **Stock sheet** — `--color-stock` surface with `--shadow-sheet` and paper grain; the cards sit on it.
- **Divider tabs** — `--radius-tab` tabs with a `--color-rule-2` edge; on the collection page they are the set filter (same `setsQuery()` + `$setFilter`, no chip rail, no dropdown). Tabs are real `tablist`/`tab` controls with manual activation; the selected set links to its page.
- **Hang tag move** — `+$2.40 · 7d` / `−$1.00 · 7d` / `±$0.00 · 7d` under the price in `.col-mono`; green dot only for a rise; nothing when there is no comparable price a week old.
- **Activity range tabs and day sheets** — on `/{user}/activity` the collection value sits on a stock sheet behind divider tabs `1M · 3M · 6M · 1Y`: a `tablist`/`tab` with manual activation like the collection's set tabs (arrows move focus, Enter or Space selects), selection in `?range=` (absent = 1M); the card page's `.nw-range-pills` are not used here. The sheet head reads "Collection value · 3 months", the last value and the range move (`+$12.40 · 3 months`: green only for a rise, a fall `−$659.38` in `--color-flat`), and a muted "History since 15 Sep" sits under the chart when the data starts after the range start. The chart is one continuous line: the series has a value on every sampled day, so nothing is drawn dotted. It is a keyboard `slider` (←/→/Home/End/Esc; value = the day read, value text = the readout), and a pointer reading never reaches the screen reader. Days read `4 Nov`, with the year only when it is not this year (`4 Nov 2025`), on every Activity date. The heading is the collection's name size (`--act-who` = `--col-who`). The feed is one stock sheet per calendar day (UTC) under a divider tab (`4 Nov` · `N updates`); in a row a rise is `--color-accent-deep` with a green dot, a fall is `--color-flat`, an addition's price is ink, and a price move under 1.00 of its own currency is not listed.
- **Hang tags** — `--radius-tag` stock tags under a card carrying its price; green only when the price is up.
- **Binder page + pockets** — `--color-binder-page` with clear pockets (`--color-sleeve-*`); a missing card is an empty pocket with its number pencilled in.
- **Printed strip** — a one-line set progress strip with hollow slots for missing cards (replaces the `.nw-bar` progress bar on set screens).
- **Mini printed strip** — the sets list draws each set with the same slot rule as the set page's printed strip (`PrintedStrip::slots()`): a card whose number is plain digits within the set total sits at its number; lettered numbers (TG, GG, SV…) and numbers outside the total are grouped after the numbered run; a number the catalogue has not imported is a grey, unnumbered slot. On the list the slots are compressed to a 10 px track: owned runs filled, missing runs hollow, unimported slots left as the grey track.
- **Ticket stub** — one stub per owned copy on the card page.
- **Catalogue label** — the facts block (set, number, rarity, artist) set as a library label.
- **Plate with crop marks** — the card image at 63×88 mm marks, with Actual size, loupe (capped at scan resolution) and calibration.
- No top-loaders and no penny sleeves, anywhere.

**Rarity chips on stock** (amends the Rarity accent in the next section): `silver` is a fill, `linear-gradient(oklch(96% .006 250) → oklch(88% .008 250))` (dark: 88% → 78%), and `chase` is a pastel foil fill (`--foil-1..4`, oklch 84–90% L, chroma .07, hues 200/300/20/95); both carry ink text `oklch(22% .006 85)`. The chase tile keeps a thin foil `.ring` around the card. Printed rarity only, never price-driven. The older text-gradient chase and silver-text chip are retired.

**Count-up is retired.** None of the approved revamp pages animates a number (the anti-slop rule bans it); a headline value renders as its real value.

**Chrome is retired.** The frozen-dark band (`--chrome-bg`/`--chrome-fg`: topbar, footer, tile head and ticker, landing hero/close) is gone from the design: topbar and footer are `--color-paper` with a hairline, and the tile head and ticker live on stock. The tokens stay in `app.css` only until the admin screens are redesigned (next after the public pages); the admin PR deletes them. No new code may reference them.

## Set grid primitives (owner screen)
`/admin/sets/{set}` reuses `.nw-tile` (`.ghost` = missing), `.nw-setcard` and
`.nw-bar`, and adds primitives built only from the tokens above:
- **Variant chip** `.nw-chip-add` (in a `.nw-chips` row under each tile) —
  dashed outline = zero copies; `.owned` = filled ink with the count in
  `.mono` (`×2`). A save that did not go through shows `.failed` (`--danger`,
  failure only) and "Not saved — retry"; a slow save reads "Still saving…",
  then "Still saving — check your connection". The count never moves until
  the server confirms. A busy chip is `aria-busy` and ignores taps; on a
  coarse pointer it is at least 44px.
- **Toast stack** `.nw-toasts > .nw-toast` — bone text on an ink surface,
  6 s each, one Undo per toast, at most 4 at once (the oldest unheld one is
  dropped). Hovering or focusing a toast holds it open; leaving restarts the
  full time. Reduced motion removes the entrance animation. The stack sits at
  the bottom centre, moves left of the drawer while it is open, and sits at
  the top on a phone (2 visible, 1 while the sheet is open, none in a short
  landscape phone with the sheet open).
- **Drawer** `.nw-drawer-backdrop > .nw-drawer` — the card editor docked to
  the right edge (460px), a bottom sheet (max 85vh) at 720px and under.
  Motion is transform/opacity only; reduced motion removes it.
- **Import banner** `.nw-import-banner` — bone-2 strip with a hairline
  border: "Loading cards… 87/165" (count in `.mono`, polled every 5 s), or
  "N cards missing" with a Retry once no card has arrived for ten minutes.
  The stalled state (`.stalled`) swaps the border to `--warning` and slows
  the poll to 60 s, since a slow queue can still recover. "All cards loaded"
  shows when an import finishes.
- **Set picker** `.nw-drawer-backdrop > .nw-drawer.nw-pick` on `/admin/sets` —
  the drawer primitive reused as an "Add a set" dialog (`role="dialog"`,
  `aria-modal`, labelled by its title). Opening focuses the filter; Tab
  stays inside, Escape or the close button closes it, and focus returns to
  the control that opened it (header trigger or the empty-state button).
  The trap is the shared `focusTrap` Alpine component
  (`resources/js/app.js`), also used by the card editor drawer and the
  modal component. A status line reads "N of M sets" (`role="status"`) and
  a live region announces "Adding the set…". Rows `.nw-pick-row` are logo,
  name, `ID · N cards` (count in `.mono`) and, for a set the owner already
  has, a muted "In My sets" tag; they stay selectable. While a pick is in
  flight rows dim (`.is-busy`), are marked `aria-disabled`, and ignore
  pointer and keyboard activation, but are never `disabled`, so focus is
  not dropped. A set with no logo shows its id as
  text. A failed list shows an `.nw-empty` block with a Retry (and "Try
  again in a minute" during the short failure window); a failed pick shows
  `--danger` text in the dialog and does not navigate. The drawer close
  button (`.nw-dialog-close`) grows to 44px on coarse pointers, as do the
  rows (64px).

## Card page primitives (public)
- **Variant pills** `.nw-variant-pills > .nw-variant-pill` on `/{user}/{set}/{number}` —
  the toolbar's segmented-pill voice: hairline outline at rest, `.on` = filled
  ink on bone with `aria-pressed="true"`. Each pill is the variant label, its
  price in `.mono` (`—` when unpriced) and, when the collector holds it, a
  muted `×N` in `.mono` — never `--signal`, which means "price up" only. The
  selection lives in `?variant=` (`unspecified` for copies with no variant);
  the page opens on the collector's priciest copy's variant (when they own
  copies but none is priced, on the variant the set tile prices; an unowned
  card opens on its priciest variant). Wraps on narrow
  screens; 44px on coarse pointers. Hidden when the card has one variant or none.
- **Range pills** `.nw-range-pills > .nw-variant-pill` in the card page's price history
  panel head — the variant pill's voice, tighter padding: `1M · 3M · 6M · 1Y`,
  `.on` = filled ink with `aria-pressed="true"`. The selection lives in `?range=`
  (absent = 1M). The panel head says the range ("Price history · 3 months"); when
  the data starts after the range start, a muted "History since 15 Sep" sits under
  the chart (date in `.mono`). The buttons carry no `aria-label` of their own (the
  visible text is the name); the group carries one.

## Theming — dark mode (2026-09-16)
Three-state precedence, same as every browser-native dark mode: no
stored preference → the OS's `prefers-color-scheme` decides; a stored
preference (the toggle, top-right of both topbars) → it wins outright,
regardless of the OS. `app.js` writes the choice to `localStorage` and
sets `data-theme` on `<html>`; an inline `<head>` script (before
`@vite`) applies a stored value before first paint, so there's no flash
of the wrong theme.

**Themed tokens** — these flip between the light values in the Tokens
table above and their dark counterparts in `app.css`'s
`prefers-color-scheme`/`[data-theme]` blocks, and every existing rule
that already referenced them re-themes with no rule-level changes:
`--bone`/`-2`/`-3`, `--paper`, `--ink`, `--muted`, `--flat`, `--hair`,
`--danger`, `--warning`, `--signal-deep`.

**Frozen tokens — `--chrome-bg` / `--chrome-fg`** — *retired for public pages, see "Chrome is retired" above; this paragraph describes the legacy admin behaviour until it is redesigned.* The literal
always-dark values, identical in both themes. The topbar, footer, and
the card tile's `.chead`/`.ticker` are what design.md's own Primitives
section already calls "chrome": a fixed dark band that carries visual
weight regardless of what surrounds it. If `.chead`/`.ticker` rode
`--ink`/`--bone` like the rest of the page, dark mode would make them
the same near-black as the (now dark) tile body behind them, erasing
the accent stripe the design depends on — so those four element
families, plus `.nw-home-hero`/`.nw-home-close` (already documented
above as a permanently-dark band, not a new global dark mode), use the
frozen tokens instead. `--ink-2` and `--rarity-silver` stay defined
once, outside the dark override block, for the same reason: their only
consumers already live inside this frozen set.

**Theme-switch transition** — a short `background-color`/`color`/
`border-color`/`box-shadow` transition merged into each themed
element's own existing `transition` (never a blanket `*` selector,
which would fight the card hover/entrance cascade above and force a
property-diff across every tile in a full grid on every toggle).
Frozen-chrome elements carry no such transition — their colors never
change, so there's nothing to animate.

## Email
Every email renders through `resources/views/mail/layouts/branded.blade.php`
(plus its `-text` twin). Email clients drop `<style>` blocks and CSS
variables, so the layout inlines the hex values from the Tokens above: bone
background, `--paper` panel, ink text, `--muted` secondary text, and one
2px `--signal` hairline under the wordmark as the only green. The button is
an ink pill with bone text (green text on bone fails contrast).

- **Light only.** `color-scheme: light`; the site's dark theme is not
  mirrored, because clients that force dark mode invert colours on their
  own and a second palette would fight that.
- **Nothing remote.** The wordmark is text, the font is the system stack,
  and there are no images, so nothing loads from another server when an
  email is opened.
- **No markdown mail.** Laravel's markdown mail would run member-written
  text (feedback) through a markdown parser; plain Blade keeps it escaped.

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
