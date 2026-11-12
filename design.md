# Design — tcg-vault

Locked design system. Design runs read this file first; pages defer to it.
Amend it explicitly — never work around it.

It covers the public pages (landing, collection, sets, set, activity, card),
the "collector's binder" system. The owner, auth, legal and error pages still
run an older system, described only in **Legacy** at the end; do not extend it.

Rejected directions, not to be reopened: nightwire/cyberpunk, luxury-vault
gold, auction-ledger layouts without images, per-card-type accent colours as a
blanket rule, a hue per rarity string, a price-driven accent, a busy
rainbow-holo ring, top-loaders and penny sleeves, other font pairings.

## System
- Genre · modern-minimal (restrained, data-forward, one accent)
- Macrostructure · collector's binder: one summary line, set divider tabs, and cards on a stock sheet with hang tags
- Theme · custom (vibe: "bone-paper trading floor, one signal green, cards do the talking")
- Axes · paper-band: light (bone, not stark white) / display-style: grotesk-variable-width (Anybody display + Hanken Grotesk body + Martian Mono numerals) / accent-hue: warm-neutral base + one cool-green signal

## Tokens
The current tokens live in `resources/css/revamp.css` (`:root`, plus the dark
blocks). Per-page sheets (`collection.css`, `card.css`, …) add only
page-scoped values.

```css
:root {
  --color-paper:      oklch(94% 0.012 85);   /* page: bone */
  --color-paper-2:    oklch(91% 0.014 85);
  --color-paper-3:    oklch(87% 0.014 85);
  --color-ink:        oklch(18% 0.006 85);
  --color-ink-2:      oklch(24% 0.006 85);
  --color-muted:      oklch(46% 0.010 85);
  --color-stock:      oklch(98.2% 0.007 90); /* raised card-stock: sheets, tags, slips */
  --color-flat:       oklch(52% 0.008 85);   /* neutral price text (down/flat); dark enough for 11px type to pass AA on stock */
  --color-rule:       oklch(18% 0.006 85 / .14); /* hairline dividers only */
  --color-rule-2:     oklch(18% 0.006 85 / .32); /* stronger rule: tabs, tag outlines */
  --color-accent:     oklch(70% 0.16 155);   /* the ONE accent: price up (fills, dots) */
  --color-accent-deep: oklch(50% 0.13 155);  /* the same green as TEXT on paper/stock */
  --color-focus:      oklch(50% 0.13 155);   /* = accent-deep */

  --font-display: "Anybody", "Archivo", system-ui, sans-serif;  /* wdth axis, set via font-variation-settings */
  --font-body:    "Hanken Grotesk", system-ui, sans-serif;
  --font-mono:    "Martian Mono", ui-monospace, monospace;      /* wdth 87.5 — numbers ONLY: prices, card #, dates */

  --ease-out: cubic-bezier(.2,.8,.2,1);
  --ease-settle: cubic-bezier(.22,1,.36,1);
  --dur-fast: 120ms;  --dur-base: 240ms;  --dur-slow: 520ms;

  /* Plastic, paper and shadow materials */
  --color-sleeve-fill / -edge / -seam, --color-sheen, --color-highlight(-hi), --color-glint(-hi),
  --color-binder-page, --color-grain, --color-string
  --shadow-contact, --shadow-ambient, --shadow-rest, --shadow-lift, --shadow-sheet   /* ink-tinted, never coloured */

  --radius-pill: 999px;  --radius-tag: 3px;  --radius-tab: 8px 8px 0 0;
  --space-2xs..2xl: 4 / 8 / 12 / 16 / 24 / 40 / 64px;  --gutter: clamp(16px, 4vw, 56px);  --bar-h: 56px;
  --text-2xs..lg: .6875 / .75 / .875 / 1.0625rem / min(1.333rem, .9rem + 1.2svh);
  --text-display / -h2 / -close: fluid, capped by viewport height
}

/* Dark theme: surfaces near-black, never pure black. Cards stay the colour. */
:root[data-theme='dark'] {   /* also applied by prefers-color-scheme when nothing is stored */
  --color-paper: oklch(18.5% .004 85);  --color-paper-2: oklch(21.5% .005 85);  --color-paper-3: oklch(25.5% .006 85);
  --color-stock: oklch(27% .006 85);
  --color-ink: oklch(95% .012 85);  --color-ink-2: oklch(88% .012 85);
  --color-muted: oklch(65% .012 85);  --color-flat: oklch(74% .012 85);
  --color-rule: oklch(95% .012 85 / .14);  --color-rule-2: oklch(95% .012 85 / .30);
  --color-accent-deep: oklch(82% .17 155);  --color-focus: oklch(82% .17 155);
  --color-binder-page: oklch(23.5% .006 85);   /* plus the plastic and shadow tokens, retuned for dark */
}
```

Page-scoped values (not global tokens): `--col-who` (`clamp(4.2rem, 1.6rem + 11vw, 11rem)`,
on `.col-page`) sizes the collector's name on the collection page and the set's
name on the set page; the Sets heading (`--sts-who`) and Activity heading
(`--act-who`) use the same value. `--col-foil-1..4`, `--col-silver(-hi)`,
`--col-chip-ink` (collection) and `--crd-foil-1..4` (card page) tune the rarity
chips; `--set-pencil` (set page) is the pencilled number on a missing pocket, a
cool grey (oklch 42% L light / 76% dark, chroma .01, hue 260).

## Rules
**Non-negotiable, not just taste. A few carry narrow, explicit exceptions
(named below); never loosen one wholesale.**

### Type
- **Two families plus mono.** Anybody is display only (headings, brand, card names), driven by its *width* axis (`font-variation-settings: 'wdth' N`) for condensed-vs-normal voices; Hanken Grotesk is body and UI text. `font-stretch` does **not** map this axis in Chrome — always set width via `font-variation-settings`, never `font-stretch`. Archivo is only the display fallback.
- **Martian Mono is reserved for numerals** (prices, card IDs, dates) — never body text, never headings. That is the whole "outlier" budget. The one exception is a few words on the card page (`.crd-tsub`, `.crd-vo` "owned", `.crd-ch-read`).

### Colour meaning
- **One accent, for price semantics.** `--color-accent` (green) means "price trending up" — fills and dots; `--color-accent-deep` is the same green as text (the pure accent fails contrast as type). A price that falls or stays flat renders in `--color-flat`, no arrow colour, never a second colour: direction is the only thing colour says about a price. No comparable price → show nothing.
- **Bounded non-price uses of green**, and no others: the focus ring (`--color-focus`), the underline on the current nav item (`--color-accent`), the 2px hairline under the wordmark in emails, and the dot in the brand mark.
- **Red (danger) is a separate, narrow exception:** destructive actions (delete) and validation errors only — never price direction, never decorative, never a second accent. "This number went down" is `--color-flat`, not red. The binder sheet defines no red token yet; owner pages use legacy `--danger`.
- **Amber (warning) is a third, equally narrow exception:** "this needs a look" states only (the needs-variant-review flag). It is not "destructive" or "failed" — sharing red with Delete blurred that difference. Never for destructive actions, price direction, or per-category colour (that reintroduces the rejected per-type accents). Owner pages use legacy `--warning`.

### Rarity
- A tile shows the collector shorthand (`Rarity::abbreviate`: SIR, MHR, RR…); the full name lives on the detail page. tcgdex's literal `"None"` renders nothing, not "N".
- **`Rarity::tier()` gives a 3-tier accent, the one carve-out from "one accent".** It follows fixed *printed* rarity, never fluctuating market price, with a neutral → silver → foil escalation; it is not a hue per rarity string.
  - `standard` (Common/Uncommon/Rare) — no accent.
  - `silver` (Rare Holo, Double Rare, Ultra Rare, Promo, Trainer Gallery Rare Holo) — chip fill `linear-gradient(oklch(96% .006 250) → oklch(88% .008 250))` (dark: 88% → 78%).
  - `chase` (Illustration/Special Illustration/Hyper/Mega Hyper/Ace Spec/Shiny(Ultra) Rare, Secret Rare) — chip fill of pastel foil (`--col-foil-1..4`, oklch 84–90% L, chroma .07, hues 200/300/20/95), plus a thin foil `.ring` around the card. A restrained foil, not a rainbow ring.
  - Both chip fills carry ink text `oklch(22% .006 85)`. An unrecognised rarity string tiers as `standard`, never guessed into `chase`: this is a visual accent, not a value judgment.
- A graded copy replaces the rarity chip with the slab label (`PSA 10`) and gets no tier accent; on the card page its stub reads `PSA 10 graded`.

### Images
- **Card images are the content.** The page is a quiet sheet — paper, a hairline, no ornament competing with the artwork.
- **Image is optional, data is not.** Every card renders name, number or set, and price with no image: a hatched face carrying the card name and "No image" (`.col-pic--empty`; `.crd-noimg` on the card page).
- The collector's own photo is always the primary image when it exists; official art is the alternate view.
- A failed load retries `low.webp` once as `high.webp`, then shows the "No image" face; a decorative logo disappears; an Activity thumbnail is dropped and its paper frame stays. Never a broken frame. Collection and set tiles (`collection.js`) and the card page (`card.js`) share this through `dom-helpers.js`; Activity uses its own `degradeThumb`.

### Money
Totals render a currency → amount map (`<x-value-totals>`): first entry big, the rest as `<small>` after "·". **Never sum EUR and USD into one number.**

### Motion
- Anything that moves uses `transform`/`opacity`. Controls and the theme switch may also transition `background-color`, `color`, `border-color` and `box-shadow`; never layout properties.
- App pages: `--dur-fast`/`--dur-base` for hover, focus and state changes; a staggered card entrance (30–55 ms per item, capped) with `--ease-out` or `--ease-settle`. A one-off page entrance (the collection, Sets and Activity heads, Activity day sheets and chart cover, the card page's plate, name, price tags and chart cover) may use `--ease-settle`, never longer than `--dur-slow`.
- Landing only: the scroll choreography may run entrances of 460–900 ms with `--ease-settle`. It is the one place longer timings are allowed.
- **One hover primitive**: the tile lifts (`translateY(-7px)` on hover-capable pointers; keyboard focus lifts it too) while a deeper shadow fades in, plus one one-shot sleeve-reflection sweep per hover.
- **Entrance/hover cascade split.** The outer wrapper (`.col-slot`) owns the entrance animation (`opacity`/`transform`, `animation-fill-mode: forwards`); the inner `.col-card` owns the hover/focus transform. Never both on one element: a finished `forwards` animation pins `transform` above `:hover` in the cascade and silently kills the hover.
- **Reduced motion** (`prefers-reduced-motion: reduce`, or the `still` class): all durations → 0, entrances and sweeps off, hover collapses to a 2px ink ring.
- **No count-up.** A headline value renders as its real value; no page animates a number.

## Interaction
- Primary action: the whole card is the link target.
- Controls are pills (`--radius-pill`); a segmented control's active state is a filled ink pill with stock text (`aria-pressed`).
- Tabs are divider tabs (`--radius-tab`, `--color-rule-2` edge): real `tablist`/`tab` controls with manual activation (arrows move focus, Enter or Space selects).
- Focus: `outline: 2px solid var(--color-focus)`, offset 3px by default (6px on cards, inside on tabs) — the same treatment as hover, so keyboard users get full parity.

## Binder primitives (public pages)
Materials, not boxes: every primitive is a physical thing a collector already
knows, built only from the tokens above. Ownership is the default state;
absence is what gets the treatment (an empty pocket).
- **Stock sheet** — `--color-stock` surface with `--shadow-sheet` and paper grain; the cards sit on it.
- **Collection tile** (`.col-*`, shared by the collection page, the set page and the card page's related strip) — `.col-slot` (entrance) › `.col-card` (hover) › the card frame, with a hang tag under it. Meta: name, `#N` (set abbreviation + number outside a set), rarity chip or slab, `×N` for several copies, stamp text for a stamped print (Prize Pack).
- **Divider tabs** — on the collection page they are the set filter (`setsQuery()` + `$setFilter`; no chip rail, no dropdown); the selected set links to its page.
- **Hang tag** — a `--radius-tag` stock tag under a card carrying its price. The move reads `+$2.40 · 7d` / `−$1.00 · 7d` / `±$0.00 · 7d` in `.col-mono` (`Money::move()`), against the same print seven days earlier (`CardPriceResolver::weekDeltaFor`); green dot only for a rise; nothing when there is no comparable price a week old. No price reads "No price".
- **Binder page + pockets** — `--color-binder-page` with clear pockets (`--color-sleeve-*`); a missing card is an empty pocket with its number pencilled in. On the set page missing cards are opt-in (`Show the N missing`, N = imported cards minus owned, off by default, hidden when none is missing); each `<x-revamp.missing-pocket>` links to the card page, shows the card's art greyed behind the plastic, its number in `--set-pencil` at `--text-lg`, and its price on a stock slip; no hang tag, no move.
- **Printed strip** — set progress as a one-line strip with hollow slots for missing cards; it replaces a progress bar on public set screens. One slot rule everywhere (`PrintedStrip::slots()`): a card whose number is plain digits within the set total sits at its number; lettered numbers (TG, GG, SV…) and numbers outside the total are grouped after the numbered run; a number the catalogue has not imported is a grey, unnumbered slot; an imported card you do not own is hollow (numbered on the set page). On the set page (`.set-strip`) it carries the never-round-to-zero % mark and scale marks (`StripScale`) at the first slot, every 50th and the last. On the Sets list the slots compress to a 10 px track: owned runs filled, missing runs hollow, unimported slots left as the grey track.
- **Sets list** — each set is a card under its own divider tab (set code, year): logo tray, name, series, the printed strip, "Completed N / M %" and "Owned value".

### Price charts (Activity, card page)
A stock sheet behind divider tabs `1M · 3M · 6M · 1Y`, selection in `?range=` (absent = 1M). The range move and end dot are green only when the range ended higher (a fall is `--color-flat`). A muted "History since 15 Sep" sits under the chart when the data starts after the range start. Days read `4 Nov`, with the year only when it is not this year (`4 Nov 2025`; `DayLabel::of()`), on every date. The chart is a keyboard `slider` (←/→/Home/End/Esc; value = the day read, value text = the readout); a pointer reading never reaches the screen reader.

### Activity page
- The collection value sits on a stock sheet whose head reads "Collection value · 3 months", the last value and the range move (`+$12.40 · 3 months`). The series has a value on every sampled day, so the chart is one continuous line, nothing dotted. The heading is the collection's name size (`--act-who`).
- The feed is one stock sheet per calendar day (UTC) under a divider tab (`4 Nov` · `N updates`). In a row a rise is `--color-accent-deep` with a green dot, a fall is `--color-flat`, an addition's price is ink, and a price move under 1.00 of its own currency is not listed.

### Card page (`/{user}/{set}/{number}`)
Classes `.crd-*` in `resources/css/card.css`, behaviour in `resources/js/card.js` (pure parts in `card-helpers.js`, covered by `npm run test:js`).
- **Plate with crop marks** — the card on a `--color-binder-page` sheet with a 10 mm grid, crop ticks and `63 mm` / `88 mm`. *Fitted* scales it into the plate; *Actual size* draws it at 63 x 88 mm times the calibration factor (`localStorage['tcg-vault-cal']`, 0.6 to 1.6, default 1; "Match a real card" opens the slider, Reset removes the key). The **loupe** shows one image pixel per device pixel (`naturalWidth / devicePixelRatio`) and is offered only when that is at least 1.15x what the plate shows (capped at scan resolution); arrows move it (Shift for big steps), Esc puts it away. A collector's photo is shown whole (`object-fit: contain`) with no marks, no ring and no Actual size, under "Your photo of this copy. The printed size applies to the official art."; the loupe works on it. The column is `wire:ignore`. A fine pointer tilts the card a few degrees; reduced motion turns that off. The card moves between sizes in `--dur-slow`.
- **Variant pills** `.crd-vpill` — little tickets: label, price in `.crd-mono` (`—` when unpriced), muted `×N owned` when held; `aria-pressed`; selection in `?variant=` (`unspecified` for copies with no variant); opens on the collector's priciest copy's variant (owned but unpriced: the variant the set tile prices; unowned: the priciest). Hidden for one variant or none.
- **Hang tags** `.crd-htag` — one per market read, the headline read edged in ink. The headline shows two moves, `+$8.00 · 1d   +$32.00 · 7d` (`1d` is the real gap in days to the previous price day; `7d` comes from the week-baseline read model), then its low; others show low and trend; `entered D` (manual) and `as of D` (a source that stopped updating) stand alone. Only a rise is green, on the move, never the amount. One short settle on entry.
- **Price history** — the chart above, drawn server-side from `PriceChart` with x by date: a market day with no price breaks the line and is bridged by a dotted segment, a lone day is a dot; a manual price is one continuous step line from its first to its last entry in the range (it holds until changed; it is not carried out to the range edges). The slider also takes ↑/↓ and rests on the newest day; the draw-in plays on the first load only. Day labels on the chart, "History since", tag notes and move titles all follow the day-label rule.
- **Ticket stubs** — one per owned copy: quantity on the stub; condition name and code, or `PSA 10 graded`; variant, `Added D`, `own photo`, notes. **Catalogue label** — the card's facts (set, number, rarity, artist, HP, Pokédex, Catalog ID) set as a library label with dotted leaders; HP, Pokédex and Catalog ID in mono.
- **More from {set}** — a divider tab over a stock sheet with up to eight owned cards in number order, each the collection tile (own-print price, `· 7d`, stamps, slab, `×N`), hover sweep included.
- **Copy link** — copies the page URL with its `variant=` pair only (never range or display switches).

## Theming — dark mode
Three-state precedence, as in every browser-native dark mode: no stored preference → the OS's `prefers-color-scheme` decides; a stored preference (the toggle, top-right) → it wins outright. `app.js` writes the choice to `localStorage` (`tcg-vault-theme`) and sets `data-theme` on `<html>`; an inline `<head>` script before `@vite` applies the stored value before first paint, so there is no flash of the wrong theme. A `?theme=` query overrides the stored choice on first load (captures).

The themed tokens are the light/dark pairs in the Tokens block above; rules that reference them re-theme with no rule-level changes. Any theme-switch transition merges into each element's own `transition` list — never a blanket `*` selector, which would fight the hover/entrance cascade and force a property diff across every tile on each toggle.

## Email
Every email renders through `resources/views/mail/layouts/branded.blade.php` (plus its `-text` twin). Email clients drop `<style>` blocks and CSS variables, so the layout inlines hex values: paper `#f2efe6` background, stock `#fbf9f3` panel with a `#ded9c9` border, ink `#141412` text, muted `#6d6c62` secondary text, and one 2px `#37d17f` hairline under the wordmark as the only green. The button is an ink pill with paper text (green text on paper fails contrast).
- **Light only.** `color-scheme: light`; the site's dark theme is not mirrored, because clients that force dark mode invert colours themselves and a second palette would fight that.
- **Nothing remote.** The wordmark is text, the font is the system stack, and there are no images, so nothing loads from another server when an email is opened.
- **No markdown mail.** Laravel's markdown mail would run member-written text (feedback) through a markdown parser; plain Blade keeps it escaped.

## Information architecture
- `/` — the landing page.
- `/{u}` — the collection: every owned card, set divider tabs, search, sort.
- `/{u}/sets` — the Sets list. `/{u}/activity` — the history.
- `/{u}/{set}` — the set page: owned cards, plus opt-in missing pockets. `/{u}/{set}/{number}` — the card page.
- Two permanent redirects (301): `/{u}/gallery` → `/{u}`, and `/{u}/movimientos` → `/{u}/activity`.
- Nav is Collection · Sets · Activity, always all three linked on a collector's pages; the landing has the bare bar. The owner also sees "Manage collection" (`/admin`).
- Owner and platform screens (`/admin…`, `/staff…`, `/profile`), auth and the legal pages (`/privacy`, `/terms`) are the Legacy surface.

## Language
UI copy is English.

## Legacy — owner, auth, legal and error pages (until redesigned)
These pages load `resources/css/app.css` (not `revamp.css`) and use the older
`.nw-*` system. Do not extend it; the redesign moves them to the binder system
and deletes this section.

**Token names.** Both naming schemes are live. Legacy names are defined in
`app.css` (`:root`, plus the `prefers-color-scheme`/`[data-theme]` dark blocks).

| Legacy | Light | Dark | Binder equivalent |
|---|---|---|---|
| `--bone` / `-2` / `-3` | #f2efe6 / #e9e5d8 / #ded9c9 | #141412 / #1b1b18 / #242420 | `--color-paper` / `-2` / `-3` |
| `--paper` (raised tile/panel) | #fbf9f3 | #292925 | `--color-stock` (not `--color-paper`, which is bone) |
| `--ink` / `--ink-2` | #141412 / #23231f | #f2efe6 / outside the dark block | `--color-ink` / `-2` |
| `--muted` | #6d6c62 | #8f8d80 | `--color-muted` |
| `--hair` | rgba(20,20,18,.14) | rgba(242,239,230,.14) | `--color-rule` |
| `--signal` | #37d17f | same | `--color-accent` |
| `--signal-deep` (accent text) | #12a45f | #4ee496 | `--color-accent-deep` |
| `--flat` | #9a988c | #b0ada0 | `--color-flat` (darker, for 11px type) |
| `--danger` / `--warning` | #c23b2e / #df911a | #e2564a / #f0a83c | none yet |

Also legacy: `--rarity-silver` #9aa0a6, `--radius-card` 7px, `--ease`, and a
narrower `--gutter` (`clamp(16px, 4vw, 34px)`).

**Chrome tokens.** `--chrome-bg` #141412 / `--chrome-fg` #f2efe6 are literal and identical in both themes: a fixed dark band that must not ride `--ink`/`--bone`, or dark mode would turn the tile head and ticker the same near-black as the tile body. `--ink-2` and `--rarity-silver` stay outside the dark block because their only consumers sit inside that frozen set. Real consumers: `.nw-topbar` (the owner nav bar and the legal pages' bar), `.nw-footer` (legal pages), the owner tile's `.chead` and `.ticker`, and inline colours in the owner nav, the login and sign-up checkboxes and the platform-settings checkbox. The guest and error pages do not use the bar, but `.nw-brand`/`.nw-mark` are styled for it (the guest page overrides them). Deleting the tokens needs a plan for the legal pages, owner nav and those checkboxes, not only the admin screens. No new code may reference them.

**Type.** Archivo is the body font (`--font-sans`) with Martian Mono numerals; Anybody and Hanken Grotesk are not loaded here.

**Colour and focus.** Green appears as `.nw-link.is-active` (the current nav item), `.nw-input:focus` borders and the focus ring (`outline: 2px solid var(--signal)`, offset 3px). The theme-switch transition is a short `background-color`/`color`/`border-color` transition on themed elements; frozen chrome carries none, since its colours never change.

**Owner tile and its motion.** `.nw-slot` (entrance, 480 ms, 55 ms stagger capped at 12) › `.nw-tile` (hover: `translateY(-9px) scale(1.05)`, `z-index: 30`; resting `0 0 0 1px ink` ring, 7px radius) › `.chead` (#number · rarity shorthand) › `.imgwrap` › `.cbody` (`.cname`, `.cset` with `×qty`) › `.ticker` (price). `.ghost` = a card in the set the collector does not own: dimmed frame, bone header, neutral ticker. `.imgwrap.empty` is the old no-image face (diagonal hatch, icon, "No image"). The old rarity chip: `silver` = chip border and text in `--rarity-silver`; `chase` = gradient text with a thin gradient hairline around the tile.

**`.nw-*` primitives still used, and where**
- Masthead `.nw-masthead` + `.nw-eyebrow` + `.nw-h1` (`--md`/`--sm`, `.nw-display`): masthead on `/admin/sets` and `/admin/sets/{set}`; eyebrow and h1 also on the legal, staff, profile, invite-registration and error pages. The h1 is the subject (the set name, the page's own name), never a generic word.
- Stat band `.nw-stats > .nw-stat > .k/.v` (4-up, 2-up under 720px; `.accent` = the one green value; `.v.text` for a name): `/admin/sets/{set}` only.
- Toolbar `.nw-toolbar` = `.nw-count` + `.nw-pill-input` / `.nw-pill-select` + `.nw-seg` (`aria-pressed` drives the filled state): `/admin` and `/admin/sets/{set}`; the pill input also in the set picker, the pill select also on error pages.
- Set card `.nw-setcard` (logo band, completion row + `.nw-bar`, owned value), via `<x-set-card>`: `/admin/sets` only. `.nw-bar` is also the progress bar on `/admin/sets/{set}`.
- `.nw-link` — navigation-style link: ink text, no underline, a 2px transparent bottom border that turns `--signal` with `.is-active`; fades on hover; recoloured to the chrome foreground inside `.nw-topbar`. Used in the owner nav and the auth and profile forms. `.nw-inline-link` — ink, always underlined (3px offset): use it for a link inside running text or an empty state; used on `/admin`, `/admin/sets` and the delete-account form.
- `.nw-footer` — tcgdex attribution and the non-affiliation notice: the legal pages only (`layouts/public`).
- Form and layout: `.nw-card`, `.nw-input`, `.nw-label`, `.nw-btn-*`, `.nw-row-btn`, `.nw-empty`, `.nw-wrap`, across owner, staff, profile and auth screens.

### Owner set grid (`/admin/sets/{set}`)
Reuses `.nw-tile` (`.ghost` = missing) and `.nw-bar`, and adds:
- **Variant chip** `.nw-chip-add` (in a `.nw-chips` row under each tile) — dashed outline = zero copies; `.owned` = filled ink with the count in `.mono` (`×2`). A save that did not go through shows `.failed` (`--danger`, failure only) and "Not saved — retry"; a slow save reads "Still saving…", then "Still saving — check your connection". The count never moves until the server confirms. A busy chip is `aria-busy` and ignores taps; on a coarse pointer it is at least 44px.
- **Toast stack** `.nw-toasts > .nw-toast` — bone text on an ink surface, 6 s each, one Undo per toast, at most 4 at once (the oldest unheld one is dropped). Hovering or focusing a toast holds it open; leaving restarts the full time. Reduced motion removes the entrance animation. The stack sits at the bottom centre, moves left of the drawer while it is open, and sits at the top on a phone (2 visible, 1 while the sheet is open, none in a short landscape phone with the sheet open).
- **Drawer** `.nw-drawer-backdrop > .nw-drawer` — the card editor docked to the right edge (460px), a bottom sheet (max 85vh) at 720px and under. Motion is transform/opacity only; reduced motion removes it.
- **Import banner** `.nw-import-banner` — bone-2 strip with a hairline border: "Loading cards… 87/165" (count in `.mono`, polled every 5 s), or "N cards missing" with a Retry once no card has arrived for ten minutes. The stalled state (`.stalled`) swaps the border to `--warning` and slows the poll to 60 s, since a slow queue can still recover. "All cards loaded" shows when an import finishes.
- **Set picker** `.nw-drawer-backdrop > .nw-drawer.nw-pick` on `/admin/sets` — the drawer reused as an "Add a set" dialog (`role="dialog"`, `aria-modal`, labelled by its title). Opening focuses the filter; Tab stays inside, Escape or the close button closes it, and focus returns to the control that opened it (header trigger or the empty-state button). The trap is the shared `focusTrap` Alpine component (`resources/js/app.js`), also used by the card editor drawer and the modal component. A status line reads "N of M sets" (`role="status"`) and a live region announces "Adding the set…". Rows `.nw-pick-row` are logo, name, `ID · N cards` (count in `.mono`) and, for a set the owner already has, a muted "In My sets" tag; they stay selectable. While a pick is in flight rows dim (`.is-busy`), are marked `aria-disabled`, and ignore pointer and keyboard activation, but are never `disabled`, so focus is not dropped. A set with no logo shows its id as text. A failed list shows an `.nw-empty` block with a Retry (and "Try again in a minute" during the short failure window); a failed pick shows `--danger` text in the dialog and does not navigate. The drawer close button (`.nw-dialog-close`) grows to 44px on coarse pointers, as do the rows (64px).

### Dead code to delete (no references in views, `app/`, JS or tests)
- `.nw-panel`, `.nw-feed`, `.nw-section-head` in `app.css`.
- `.nw-condensed`, `.nw-tag`, `.nw-type-dot` in `app.css` (a test only asserts `nw-type-dot` is absent from the collection page).
- `--color-loader-edge` and `--color-loader-fill` in `revamp.css`, and the "top-loaders" mention in the plastic comment beside them.
