# Home Page — Design

## Purpose

`/` currently redirects unconditionally — to the configured admin's public
gallery if one exists, otherwise to `/login`. There is no page an anonymous
visitor actually lands on. This spec adds a real home/landing page for
anonymous visitors, speaking about tcg-vault as a product in general
(never leaning on the owner's personal collection numbers as if they were
platform-wide metrics — an early draft did this and was explicitly
rejected: "recuerda que es para general, no solo mi colección").

Registration is still off (`TCGVAULT_ALLOW_REGISTRATION=false`) — that is
its own, separately-scoped brainstorm per the project's standing note.
This page's job is to make the case for the product and set expectations
("crear tu cuenta — muy pronto"), not to open signup.

## Routing behavior

- **Anonymous visitor** → sees the new home page. (Previously: redirected
  to `/login`.)
- **Authenticated owner** → still redirected straight to their own gallery
  (`gallery.index`), unchanged from today. There's no reason to show a
  logged-in visitor marketing copy for a product they already use — the
  existing shortcut stays.
- **No owner configured at all** (fresh install, no user has a username
  yet) → also shows the home page rather than forcing `/login`. The page
  doesn't depend on an existing collection to render.

`routes/web.php`'s `/` closure changes from an unconditional redirect to:
redirect authenticated users to their gallery as today; render the new
home view for everyone else.

## Visual system

Everything here operates inside the already-locked `design.md` system
(bone/ink, one green accent, Archivo + Martian Mono, `transform`/`opacity`
motion only) — this spec adds page-specific composition on top, it does
not introduce a second design system.

**One clarification that came out of brainstorming**: the site's
`.nw-topbar` is *already* dark (`background: var(--ink)`) on every page
today, and the brand's `.nw-dot` already uses the accent green
decoratively (a pulsing dot, not a price signal). So the home page's dark
hero is not a new deviation from the locked system — it's a natural
extension of a dark band that already exists at the top of every page,
just taller. The two spots where this page does make a **deliberate,
scoped exception** to the "accent = price semantics only" rule:

1. The hero headline's word "**card**" renders in `--color-accent-deep` —
   brand/decorative use, same category as the existing `.nw-dot`, not a
   new kind of exception.
2. The hero combines two Hallmark "hero polish patterns" at once
   (HP2 marquee-overflow sizing + HP3 cursor-spotlight) — Hallmark's own
   rule says never combine two on one hero. Approved anyway, after seeing
   it live in the visual companion — it reads as one composition, not two
   competing devices, in this specific execution.

## Page structure (top to bottom)

### 1. Hero (dark band, extends the existing dark topbar)

- Layout: narrow copy column left (`grid-template-columns: 0.82fr
  1.18fr`), a "fan" of 5 real card images arced upward on the right,
  overlapping with slight rotation (-16° to 19° across the 5), staggered
  entrance + a slow continuous float (`drift` keyframe, `transform` only).
- A soft white cursor-tracked radial glow (`rgba(255,255,255,.13)`)
  scoped to the hero, following the pointer.
- Headline: "**Track every card.**" — condensed weight 800,
  `white-space: nowrap`, the word "card" in the accent green. Subhead:
  "Precio en tiempo real, historial de valor, cada carta con su foto. No
  un spreadsheet."
- CTA: "Crear tu cuenta — muy pronto" (bone pill button, disabled-style —
  registration isn't live, so the button doesn't need to actually submit
  anywhere yet).
- Card images: real tcgdex CDN URLs (`https://assets.tcgdex.net/...`),
  picked for being iconic/eye-catching rather than being anyone's real
  collection:
  - `en/base/base1/4/high.webp` — Charizard, Base Set
  - `en/sv/sv03.5/006/high.webp` — Charizard ex
  - `en/swsh/cel25/7/high.webp` — Flying Pikachu VMAX
  - `en/swsh/cel25/9/high.webp` — Surfing Pikachu VMAX
  - `en/sm/sm115/9/high.webp` — Charizard GX
  The desktop fan shows all 5; the mobile collapse (below) drops to 3 —
  keep Base Set Charizard, Charizard ex, and one Pikachu VMAX (either),
  dropping the other Pikachu VMAX and the Charizard GX.
- **Mobile**: flagged during brainstorming (a dispatched design-review
  agent caught this as the hero's real open risk) — the 5-card rotated
  fan has no defined collapse behavior below ~480px yet. This spec
  resolves it as: **below 768px, the grid collapses to a single column**
  (copy first, then a smaller, less-overlapped 3-card fan directly under
  it, dropping 2 of the 5 cards rather than shrinking all 5 illegibly
  small). The cursor-spotlight becomes inert on touch devices (no
  `pointermove` on touch — it simply never activates, no separate code
  path needed) and is skipped entirely under
  `prefers-reduced-motion: reduce`, which also collapses the `drift` and
  entrance animations to a single instant fade per the system's existing
  reduced-motion rule.

### 2. Features (bone, back to the page's normal background)

Structural DNA borrowed from studying `pkmn.gg` (a real, comparable
product — public site, not a template marketplace): **one full-width
section per feature** (own heading, 2-3 bullets, own small visual, own
link), alternating left/right — not compressed into a 3-column card grid.
Several compressed-card drafts were tried first and rejected as "muy meh."

Three features, in order:

1. **Precio en tiempo real** — bullets: synced with tcgdex (tcgplayer
   USD + cardmarket EUR); collection value changes over time; nothing
   updated by hand. Visual: a small price panel, `$--.--` placeholder (no
   invented number) plus the two source labels.
2. **Tu foto, no un placeholder** — bullets: upload the real photo when
   adding a card; tcgdex's official art is the fallback, never the
   primary; works identically with or without a photo. Visual: two
   card images side by side, labelled "foto propia · arte oficial".
3. **Sets completos, de un vistazo** — bullets: see what's missing from
   any set you're tracking; missing cards stay marked, not hidden;
   per-set progress, not just one flat total. Visual: 3 progress bars
   with set names and percentages.

Every visual on this page that would otherwise need a real number (price,
totals) uses `$--.--` / `—` placeholders — never a fabricated figure,
per the same rule that got the personal-stats draft rejected earlier.

### 3. Data coverage strip (bone)

One line: "Precios sincronizados con **tcgdex.dev** · **tcgplayer** ·
**cardmarket**" — attribution of a real data source, explicitly not
framed as "trusted by" or client logos (there are no clients; this is a
personal project with one real user right now).

### 4. FAQ (bone)

Four questions, all truthfully answerable today:
- ¿Mis datos son privados? — yes, collection privacy is per-profile
  already (existing `is_public` column on `collections`).
- ¿Cuándo abre el registro? — "muy pronto", matches the hero CTA's
  framing, doesn't overpromise a date.
- ¿De dónde salen los precios? — tcgdex.dev, aggregating tcgplayer (USD)
  and cardmarket (EUR).
- ¿Tiene costo? — No.

### 5. Closing CTA + footer (dark, bookends the hero)

- Repeats the hero's headline treatment ("Track every **card**.") and the
  same CTA copy, on the app's real `--ink` token — **not** a separate
  dark tone invented for this page. This was a live correction during
  brainstorming: a first draft used the CTA's dark box as one-off styling,
  Carlos flagged it should be bone (matching the "dark is hero-only" read
  of the rule), then corrected again — the CTA is *meant* to be dark, and
  it should share the exact same dark as the footer, because **the
  footer is always dark, site-wide** — confirmed against the existing
  `.nw-footer` rule already in `resources/css/app.css` (`background:
  var(--ink)`), true on every page today, not something new for this one.
- The footer itself is the site's **existing** `layouts/public.blade.php`
  footer — this page does not get its own footer copy. It reuses the
  layout as-is (tcgdex attribution + non-affiliation notice), with the
  new closing-CTA block placed immediately above it inside the page's own
  content, sharing the same `--ink` background so the two read as one
  continuous dark block with no visible seam.

## Component boundaries

- **New**: a `Home` view (plain Blade, no Livewire component needed — the
  page is static content, no interactivity beyond CSS `:hover` /
  `pointermove` for the spotlight, which is a small inline `<script>`
  scoped to the hero, same pattern as the visual companion's mockups).
  Rendered from the `layouts/public.blade.php` layout Carlos's app
  already uses for every other public page — reuses the existing topbar
  and footer, contributes only the `$slot` content (hero through closing
  CTA).
- **Modified**: `routes/web.php`'s `/` route closure (auth branch
  unchanged, anonymous branch now renders the home view instead of
  redirecting to `/login`).
- Card images: hardcoded real tcgdex CDN URLs in the Blade view (not
  fetched at request time — these are fixed, curated picks, not dynamic
  content pulled from the Catalog module). No new Catalog/Collection
  code needed.

## Testing

- A Feature test asserting `/` renders 200 (not a redirect) for a guest,
  and asserting `/` still redirects an authenticated user to their
  gallery (existing behavior, must not regress).
- A Feature test asserting the home view's `<title>`/meta description are
  present (the layout already provides `$pageDescription`'s fallback,
  which is appropriate for this page — no `$title` override needed since
  `$siteName` alone is the right `<title>` for the root landing page).
- No test asserts on the specific card image URLs beyond "the hero
  contains at least N `<img>` tags with a `tcgdex.net` src" — pinning
  exact card IDs in a test would make a future curation change (swapping
  which iconic cards are shown) fail a test for no functional reason.

## Out of scope

- Registration itself (own future brainstorm, per the project's standing
  note — this page's CTA is intentionally inert/"coming soon").
- A real waitlist/email-capture backend for the CTA (the Design Director
  review this session floated it; Carlos's brainstorm did not ask for
  it, and it would need its own data model + spam handling — deferred).
- Any A/B testing or analytics instrumentation on this page.
- Mobile behavior beyond the single breakpoint collapse described above
  (no tablet-specific intermediate layout — the two-state collapse
  matches how the rest of the app's own responsive rules already work).
