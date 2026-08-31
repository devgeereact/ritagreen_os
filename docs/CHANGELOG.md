# Changelog

Notable changes to RitaGreen OS. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Per `docs/RULES.md` rule 21, completing a module means updating `docs/STATUS.json`,
regenerating `docs/PROGRESS.md`, and adding an entry here.

## [Unreleased]

### Added — Milestone 0, documentation pack

- `docs/PRD.md` — problem, users, outcomes and the explicit non-goals
- `docs/BLUEPRINT.md` — the target model, what is already built, and the audited gap analysis
- `docs/ARCHITECTURE.md` — the real data flow, authentication model and target identity model
- `docs/SCHEMA.md` — every table, generated from `server/schema.sql`
- `docs/API.md` — every endpoint and action, replacing a server README that documented 8 of 49
- `docs/DESIGN_SYSTEM.md` — navy and mint v2 as the single source of truth
- `docs/RULES.md` — non-negotiable engineering and product rules
- `docs/UK_GOVERNANCE.md` — the compliance framework, with policy values held as configuration
- `docs/ROADMAP.md` — milestones 0 to 9
- `docs/TRACEABILITY.md` — requirement to data to endpoint to interface to test
- `docs/STATUS.json` and generated `docs/PROGRESS.md`
- `scripts/docs_sync.py` — regenerates progress, validates traceability, `--check` for CI
- `.github/workflows/ci.yml` — typecheck, build, source-map assertion, `php -l` across every
  PHP file, a guard against committing `server/config.php`, and the documentation check. Lint
  runs but does not block: there are 33 pre-existing ESLint errors in application code, most of
  them React 19 hook rules whose fixes would change runtime behaviour. The baseline is tracked
  in `docs/STATUS.json` rather than hidden
- `typecheck` script in `package.json`

### Changed

- `README.md` rewritten to describe the system as built
- `CLAUDE.md` rewritten — it was a half-filled template describing React 18 in JavaScript and
  a single `api.php`, neither of which has been true for some time
- `public/manifest.webmanifest` theme colour corrected from a retired green to navy `#0C447C`

### Removed

- `DESIGN-SYSTEM.md` — marked "v1 (LOCKED)" while specifying a green palette and two fonts the
  application does not load. Superseded by `docs/DESIGN_SYSTEM.md`
- `RitaGreenOS.html` — a 46 KB standalone feature specification carrying a third brand palette.
  Superseded by `docs/PRD.md` and `docs/BLUEPRINT.md`

### Fixed — access control

Found during the Milestone 0 audit and fixed immediately, ahead of the rest of Milestone 5,
because `/portal` is externally facing. Each of these was reachable by **any** authenticated
account, including a client portal login.

- **`server/workers.php` GET** exposed every worker's pay rate, DBS status and expiry, and SIA
  number. The file's role checks were only on its write paths. Now staff-only, with pay fields
  returned solely to `super_admin`, `admin` and `manager`
- **`server/inbox.php`** exposed the entire shared company mailbox and allowed sending replies
  as the company. Now `super_admin`, `admin`, `manager`
- **`server/field.php` POST** allowed posting field events — clock-ins and **panic alerts** —
  against any shift id. The board is now staff-only, and a worker may post only against a shift
  assigned to them
- **`server/api.php`** was an unauthenticated, unthrottled write into the production database.
  Both verbs now require authentication; the write is throttled

Adds `is_staff()`, `require_staff()` and `linked_worker_id()` to `server/lib.php`. Role alone
could not express this boundary: a worker's login is an ordinary account with
`users.worker_id` set and may hold any role, so "internal staff" has to be decided by the
link, not the role. Privileged roles are treated as staff unconditionally, so a manager who
also holds a worker record does not lock themselves out.

### Fixed — correctness

- **`server/dashboard.php`** counted trainer-less *training sessions* as open shifts
  (`FROM sessions` rather than `FROM shifts WHERE status = 'open'`). That tile had always shown
  the wrong number. It now also respects the division filter, like its neighbours
- **`server/gocardless.php`** marked an invoice fully paid on any confirmed collection, setting
  `amount_paid_pence = total_pence` regardless of what was actually collected, so a part
  collection reported as paid in full. It now credits the amount collected, marks the invoice
  paid only once that covers the total, and reads the payment's prior status first so a
  redelivered webhook cannot credit twice. Signature verification was already sound
- **`tokens.last_seen_at`** was written once at issue and never updated, so the device list in
  Settings showed each session's creation time under a heading that reads as last activity. It
  is now refreshed per request, throttled to one write a minute per token

### Still open

Recorded in `docs/STATUS.json`, verified against source, not yet fixed: the GoCardless webhook
has no timestamp replay window (the Stripe one does); `server/dashboard.php` interpolates the
division filter with `real_escape_string` rather than binding it; `server/lib.php` returns raw
exception messages to callers.

### Notes

This milestone ships **no functional change**. Its purpose is to make every later phase
executable against a written contract, and to record honestly what the audit found — including
that development currently runs against the production database, that there are no tests, and
that a person can exist three times in this schema.
