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

### Found during the audit

Recorded in `docs/STATUS.json`, not fixed in this milestone. Verified against source:

- `server/workers.php` GET, `server/inbox.php` and `server/field.php` POST call `require_auth()`
  with no `require_role()`, so any authenticated account — including an external client portal
  login — can read worker pay and DBS/SIA data, read and send from the company mailbox, and
  post panic alerts against any shift
- `server/api.php` POST is an unauthenticated, unthrottled write to the production database
- `server/dashboard.php:29` counts training sessions rather than open shifts, so that tile has
  always been wrong
- `server/gocardless.php` marks an invoice fully paid without comparing the collected amount
- `tokens.last_seen_at` is written at issue and never updated

### Notes

This milestone ships **no functional change**. Its purpose is to make every later phase
executable against a written contract, and to record honestly what the audit found — including
that development currently runs against the production database, that there are no tests, and
that a person can exist three times in this schema.
