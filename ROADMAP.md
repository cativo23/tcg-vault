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

- ~~**Redis persistence**~~ — done. `--appendonly yes --appendfsync
  everysec` caps the loss window at ~1s instead of losing everything
  since the last restart, the same failure that dropped a 2,952-job
  `catalog:refresh-prices` dispatch mid-flight with no error. Memory
  limit raised 256M → 384M for `BGREWRITEAOF`'s fork/copy-on-write
  headroom on top of Horizon's own ~160M telemetry floor; confirmed
  against live polaris2 usage (3.7GB free host RAM) before sizing it.
- ~~**Persist `storage/logs`**~~ — done. `app-logs` named volume shared
  across `app`/`horizon`/`scheduler` (they all write to the same
  `storage_path('logs/laravel.log')`), `LOG_CHANNEL=daily` with 14-day
  retention. Previously the logs lived in the container's writable layer
  and went away with it on every deploy — the incident and the evidence
  for it lost in the same motion.
- ~~**Error tracking**~~ — done. Self-hosted Bugsink (Sentry-protocol-
  compatible) on `space-server`, at `errors.cativo.dev` (VPN-gated
  dashboard). `app`/`horizon`/`scheduler` all reach it internally as
  `http://bugsink:8000` over `space-server_web` — an exception never
  needs to leave the host network to get reported, and never needs to
  clear `internal-only`'s VPN gate either.
- ~~**Queue failure alerting**~~ — shipped in v0.5.0, ahead of this
  milestone, prompted by the incident that motivated this roadmap in
  the first place: a Discord alert (`App\Support\DiscordAlerter`, no
  Slack workspace to route to) now fires if `catalog:refresh-prices`
  itself errors, or if `catalog:check-pricing-freshness` finds the
  newest price snapshot older than 26h. `DISCORD_ALERT_WEBHOOK_URL` is
  already set on polaris2 (reusing the same webhook `alertmanager-discord`
  posts host-level infra alerts to) and confirmed delivering.
- ~~**Clear the `failed_jobs` backlog**~~ — done. 3,098 rows, all
  `MaxAttemptsExceededException` (mostly `SyncCardPricingJob`, a handful
  of Telescope's `ProcessPendingUpdates`), none of the job's own
  "permanent" business exceptions — those are caught and logged inside
  `handle()` and never reach `failed_jobs`. Root cause turned out to be
  broader than the 2026-09-24/25 Redis incident: Horizon's
  `balance: auto` was starving the `default` queue to a single worker
  every night regardless of Redis health, so a remainder of the nightly
  `catalog:refresh-prices` fan-out routinely missed `retryUntil()` —
  fixed separately (`balance: 'off'`, `retryUntil()` 1h → 3h, horizon
  container memory 192M → 256M). Backed up in full before pruning;
  `catalog:refresh-prices` re-dispatches every card daily regardless, so
  no manual re-sync was needed.
- ~~**Schedule `telescope:prune`**~~ — done. Daily, `--hours=48`, matching
  this project's other short-lived operational data retention.
- ~~**Verify the production environment**~~ — done. `APP_DEBUG=false` and
  `SESSION_SECURE_COOKIE=true` confirmed live on polaris2's actual `.env`
  (not just the template), and added as an explicit line in
  `deploy/README.md`'s acceptance checklist.
- **Off-host database backups**, with one restore actually performed and
  documented. `pgdata` is a local Docker volume on a single VPS; a
  restore that has never been run is not a backup. Covers the
  `collection-photos-data` volume too. Deliberately last in this
  milestone: the free tier (Cloudflare R2, 10GB) needs billing enabled on
  the Cloudflare account first, on hold until that card is added.

## v0.7.0 — Correctness and accessibility

- ~~**Duplicate collection items on concurrent add.**~~ — done. A
  Postgres unique index on the identity tuple (`collection_id, card_id,
  COALESCE(variant,''), condition, COALESCE(grade_company,''),
  COALESCE(grade_value,'')` — plain unique would've let two
  both-ungraded rows past, since Postgres treats each NULL as distinct)
  closes what `addItemForCard()`'s check-then-act alone couldn't.
  Deviated from the roadmap's original `lockForUpdate()` plan: this
  codebase already has a closer precedent for exactly this
  shape — `InviteManager::createInvite()` catches the unique-violation
  and merges into the winning row, rather than locking a parent row
  that doesn't exist yet to lock at check time.
- ~~**Double-submittable save button.**~~ — done. Added
  `wire:loading.attr="disabled" wire:target="save"`, matching every
  other write action in that file.
- ~~**Modal dialog semantics.**~~ — done. `role="dialog"`,
  `aria-modal="true"`, `aria-labelledby` pointing at the card name, focus
  moves to the first focusable element on open, and Tab/Shift+Tab are
  trapped inside — the same Alpine focus-trap shape as
  `components/modal.blade.php`, duplicated rather than shared since this
  modal is a Livewire property re-rendering the whole subtree, not that
  component's `show`/`x-show` toggle. Trap listens on `window`, not the
  panel, so it recovers even if a Livewire re-render inside the modal
  (e.g. the remove-row confirm swap) drops focus to `<body>`. Closing
  also dispatches an event to return focus to the row's own Edit button
  — the modal's whole subtree, including whatever held focus, is removed
  from the DOM on close, not just hidden.
- ~~**`aria-live` on Livewire search results.**~~ — done. Added
  `role="status"` (implies `aria-live="polite" aria-atomic="true"`) to
  the result-count text in the public gallery, the per-set gallery, the
  collection index, and the card-add search — the fourth spot (card
  search) had no persistent count at all before this, only a conditional
  "first N matches" note, so it also gained an always-rendered (if
  empty) result-count line for a live region to actually announce.
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
