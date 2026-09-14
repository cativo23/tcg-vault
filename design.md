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
- **One accent color, period.** `--color-accent` (green) means exactly one thing: "price trending up." Falling/flat prices render in `--color-flat` (neutral), never a second accent (e.g. red). Don't introduce holo/rainbow treatments or per-card-type accent colors — both were explicitly tried and rejected.
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
No `tokens.css` extracted yet — `vault-final.html`'s inline `:root` block is
the source of truth until the Laravel app scaffolds and these tokens move to
a real stylesheet. Port them verbatim; don't re-derive.

## Language
- **UI copy: English first**, Spanish is not the default (this reverses the
  Spanish-language mockups used during brainstorming — those were faster to
  iterate on with Carlos, not a product decision). Translate all screens to
  English once the visual design is locked, in a single pass — do not
  translate incrementally mid-brainstorm.
