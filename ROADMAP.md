# Roadmap

The path from v0.5.0 to a v1.0 beta. Scope and ordering follow one rule:
**irreversible risk first, then correctness, then the things a stranger
sees.** A bug can be fixed after the fact; a lost database cannot.

Versions follow [SemVer](https://semver.org/); every shipped item lands in
`CHANGELOG.md` under its release.

## Beta staging

The beta opens in two steps rather than one, so that the first real users
arrive while the failure signal is still small enough to read.

| Stage | Registration | What it unlocks |
| --- | --- | --- |
| **v1.0 — wide private beta** | Invite-only, via the existing switch in `Staff/PlatformSettings` | Invites go out in waves; error tracking accumulates real traffic; bugs get reported by people who can be asked a follow-up question |
| **v1.1 — public beta** | Open to anyone | Mandatory email verification, signup abuse controls, uploaded-photo moderation, a terms-of-service with real teeth |

Invite-only does not exempt v1.0 from a privacy policy, a feedback
channel, or a live example gallery — the first two because real people's
emails, passwords and photos are already being stored, the third because
an invited collector who lands on an empty vault has seen nothing worth
coming back to.

## v0.6.0 — Durability

Nothing here is user-visible. All of it is what makes the rest of the
roadmap safe to attempt.

- **Off-host database backups**, with one restore actually performed and
  documented. `pgdata` is a local Docker volume on a single VPS; a
  restore that has never been run is not a backup. Covers the
  `collection-photos-data` volume too.
- **Redis persistence** (`--appendonly yes`, or scheduled RDB saves).
  Confirmed live: recreating the `redis` container to fix its memory
  limit silently dropped every queued job that hadn't been picked up
  yet — a `catalog:refresh-prices` dispatch of 2,952 jobs vanished
  mid-flight with no error, because nothing had told Redis to write any
  of it to disk first. The same container restart that a crash, an OOM
  kill, or a routine `docker compose up -d` triggers today loses
  whatever is in the queue at that instant.
- **Persist `storage/logs`** on a named volume in
  `docker/prod/compose.prod.yml`, and set `LOG_CHANNEL=daily` with an
  explicit retention window. Today the logs live in the container's
  writable layer and go away with it on every deploy — the incident and
  the evidence for it are lost in the same motion.
- **Error tracking** (Sentry or equivalent). Without it, and with the
  log lifetime above, a production exception leaves no trace at all.
- ~~**Queue failure alerting**~~ — shipped in v0.5.0, ahead of this
  milestone, prompted by the incident that motivated this roadmap in
  the first place: a Discord alert (`App\Support\DiscordAlerter`, no
  Slack workspace to route to) now fires if `catalog:refresh-prices`
  itself errors, or if `catalog:check-pricing-freshness` finds the
  newest price snapshot older than 26h. `DISCORD_ALERT_WEBHOOK_URL` is
  already set on polaris2 (reusing the same webhook `alertmanager-discord`
  posts host-level infra alerts to) and confirmed delivering.
- **Clear the `failed_jobs` backlog from the 2026-09-24/25 incident**
  (in progress). ~5,341 rows, mostly `SyncCardPricingJob` failures from
  the redis crash loop — being classified into retryable-now-that-redis-
  is-stable vs. genuinely permanent, with a full backup taken before
  anything is pruned. One-time cleanup, not durable roadmap work; listed
  here only until it's actually done.
- **Schedule `telescope:prune`** in `routes/console.php`. Telescope's
  driver is `database` with no retention job; the table grows until the
  disk does not.
- **Verify the production environment**: `APP_DEBUG=false`,
  `SESSION_SECURE_COOKIE=true`. Both are correct in
  `docker/prod/.env.production.example`; neither is currently an explicit
  line in `deploy/README.md`'s acceptance checklist. Add them.

## v0.7.0 — Correctness and accessibility

- **Duplicate collection items on concurrent add.**
  `CollectionService::addItemForCard()` (`app/Modules/Collection/Services/CollectionService.php:56`)
  is check-then-act with no lock, and the migration backs
  `[collection_id, card_id]` with a plain index rather than a unique
  constraint. Two near-simultaneous adds of the same card identity both
  miss the existing row and both insert, silently splitting quantity.
  Fix both layers: a unique constraint on the identity tuple plus
  `lockForUpdate()` inside a transaction — the same shape
  `InviteRegistration::register()` already uses correctly.
- **Double-submittable save button.**
  `resources/views/livewire/admin/add-collection-item.blade.php:86` has no
  `wire:loading.attr="disabled" wire:target="save"`, unlike every other
  write action in that file. This is the front-end half of the bug above;
  guarding the button without the constraint leaves the hole open, and
  the constraint without the guard leaves a confusing error in its place.
- **Modal dialog semantics.** The variant editor
  (`resources/views/livewire/admin/collection-items.blade.php:129`)
  handles Esc and click-outside but has no `role="dialog"`,
  `aria-modal="true"`, focus move on open, or Tab trap.
- **`aria-live` on Livewire search results.** Gallery search, collection
  search and card search all swap their result grid with the loading
  spinner marked `aria-hidden`, so assistive tech is told nothing
  happened. Announce the result count.
- **Accessible names on icon-only buttons** in
  `resources/views/livewire/admin/partials/variant-row.blade.php` — a
  `title` attribute is not a reliable accessible name, and is nothing at
  all on touch.
- **The 404 page sends anonymous visitors to "Back to login."** A stale
  or mistyped gallery link is a normal, expected 404 on a public site,
  and the visitor may not have an account to log in to. Send guests home.
- **N+1 in the TCGplayer import preview.**
  `TcgplayerImportParser.php:88` runs a card lookup and a variant count
  per parsed line. Batch both.

## v0.8.0 — Ready for someone else's eyes

- **A live example gallery**, linked from the landing page. Today the
  product's actual value — daily pricing, set completion, activity
  history — is invisible until after someone has registered *and*
  manually entered cards. This is the single largest retention risk and
  the cheapest to fix; the mechanism already exists.
- **Privacy policy and terms.**
- **A feedback / bug-report channel.** A beta with no way to reach the
  author produces silent churn instead of reports.
- **Surface the public/private toggle.** It lives in `/admin`
  ("Manage collection"); the landing page's own FAQ tells users to look
  for it on their profile. Move it, mirror it, or fix the copy.
- **Say what account deletion does.** The cascade to the user's
  collection is real and currently unstated next to the button.
- **CSV export.** The TCGplayer import parser already establishes the
  column semantics; the reverse direction is bounded work, and a
  collection tracker with no way out of it is a hard sell.

## v0.9.0 — Pipeline hardening

- **Static analysis** (larastan) in `ci.yml`. Pint and Pest both pass
  today; neither catches a type or logic error.
- **Dependency audit** — `composer audit` and `npm audit`.
- **Build the production image on pull requests.** `deploy.yml` only
  builds on `release: published`, so a broken Dockerfile surfaces during
  a deploy rather than during review. It has done exactly that before.

## v1.0.0 — Wide private beta

Invites go out in waves. No new features; the release is the go/no-go on
everything above.

## Beyond v1

Ordered by how often a collector is likely to ask for it, not by how
interesting it is to build.

- Wishlist / want-list
- Multiple collections per user (binders, a for-sale pile)
- Deck lists
- Trade and sale tracking
- Usage analytics
- **Move `CACHE_STORE` off Postgres onto a dedicated Redis instance**,
  separate from the Horizon queue Redis — they need conflicting
  `maxmemory-policy` values (`noeviction` for queue data, `allkeys-lru`
  for cache), so they can't share a process regardless of memory sizing.
  Deliberately last: evaluate alongside centralizing Redis across other
  projects on the same host rather than standing up a second one-off
  instance here.

## Not on the roadmap

- A public write API. The app is server-rendered Livewire with no API
  surface, and adding one before the data model settles would freeze it
  early.
- Native mobile apps. The gallery is responsive; a wrapper would add a
  release process without adding a capability.
