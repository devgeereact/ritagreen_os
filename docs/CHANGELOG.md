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

### Fixed — client-side access control

- **`src/lib/access.ts`** is new: one path-prefix map of route to permitted roles, plus
  `isStaff()`, `isClientAccount()` and `isWorkerAccount()`. Both `RequireAuth` and the sidebar
  read it, so a link can never be shown to someone who would be bounced off the page behind it
- `RequireAuth` accepted a `roles` prop that **no route ever passed**, so `/app`, `/portal` and
  `/m` were gated identically on "is logged in" and a client account could load `/app/payroll`.
  It now consults the map, and redirects to the user's own home rather than a dead end
- `/portal` now requires a `client_id` and `/m` a `worker_id`, because those surfaces scope
  every query to that link and would otherwise render an empty or erroring page
- The sidebar filters its groups through the same map, dropping any group left empty

### Fixed — the typecheck was not running

- **`tsconfig.json` had no `references` array**, so `tsc -b` built a root config that lacks
  `noUnusedLocals` and `noUnusedParameters`, and `tsconfig.app.json` — where those settings
  live — was orphaned. The strict configuration had never been enforced. Wiring the references
  surfaced seven genuine errors, all now fixed
- The `typecheck` script is now `tsc -b --force`, so a warm incremental cache cannot make the
  gate a silent no-op
- **`tsconfig.tsbuildinfo` was tracked in git** despite `*.tsbuildinfo` being gitignored — a
  file added before the ignore rule stays tracked. Untracked, and both projects now write their
  build info under `node_modules/.tmp`
- Of the seven errors this exposed, one was a real bug: `SidebarMenuSub` in
  `src/components/ui/sidebar.tsx` declared `React.forwardRef` and destructured `ref`, but never
  applied it, so the forwarded ref was silently dropped. The rest were unused imports and a
  dead `STATUS_VARIANT` constant left behind when job status became an editable Select

### Changed — lint baseline

Down from 33 errors and 3 warnings to 27 errors and no warnings, by removing the unused
imports the typecheck surfaced and applying the auto-fixable `prefer-const`. What remains is
8 `react-hooks/set-state-in-effect`, 7 `react-refresh/only-export-components`, 4
`react-hooks/refs`, 3 `react-hooks/static-components`, 2 `no-explicit-any`, 2
`react-hooks/purity` and 1 `no-unused-expressions` — all of which change runtime behaviour to
fix. Everything mechanical is done.

### Added — local development, and with it the unblocking of all schema work

Development read and wrote the **live production database**: `vite.config.ts` proxied every
`*.php` request to `https://ritagreenservices.com`, and there was no local alternative. That
made every migration in the roadmap unsafe to attempt.

- **`server/config.local.php.example`** — a tracked template pointing at a local database with
  every outbound integration deliberately disabled: mail, SMS, WhatsApp, Stripe, GoCardless,
  Google SSO, OpenRouter, weather. A local run must not be able to email a real worker or
  charge a real card
- **`npm run dev:api`** — `php -S 127.0.0.1:8122 -t server`, serving the PHP tier exactly as
  the host does, with no router needed
- **The Vite proxy now defaults to local.** The target comes from `RG_API_TARGET`; overriding
  it prints a warning at startup, and `npm run dev:prod-api` makes the deliberate case explicit
- **`server/seed.php`** — fixtures covering staff logins for every role, a worker login, a
  client portal login, four clients with sites, and ten workers whose compliance is deliberately
  mixed: valid, inside the 30-day window, expired, and one with no right to work. Plus
  certifications, applicants across the pipeline, assigned and open shifts, programmes,
  enrolments, enquiries and an incident. It refuses to run outside the CLI, against a non-local
  `DB_HOST`, without `--confirm`, or on a populated database without `--reset`
- The "config.php is missing" error now tells a developer to copy the local template, rather
  than telling them to upload a file to a server they are not on

**Still needed on the developer's machine:** a local MySQL or MariaDB. Everything else ships in
the repository.

### Added — tests, and the separation that makes them possible

There was not one test file in this repository. There are now two suites, both
dependency-free and both running in CI, because the production host cannot install packages
and a suite that only runs on one machine stops being run.

- **`server/pure.php`** — domain logic with no database, no config, no output and no globals.
  Every function is a function of its arguments, and every date function takes an injectable
  clock so a test can pin time rather than hoping the suite never runs at midnight. It holds
  credential expiry classification and reminder banding, the staff/worker/client distinction,
  and field status derivation. Three of those were **duplicated definitions inside separate
  endpoint files** — `classify()` in `compliance.php`, `tier_for()` in `expiry-alerts.php` and
  `field_status()` in `field.php` — which is exactly how two places drift on what "expired"
  means. They now share one implementation
- **`server/tests/run.php`** — 74 assertions, a few dozen lines of assertion helper, no
  PHPUnit. Covers the boundary days specifically: a credential expiring *today* is expiring,
  not expired, and an off-by-one there stands someone down a day early every time. Also that
  `unknown` stays distinct from `expired`, since a missing date is a gap in the evidence rather
  than a lapsed credential
- **`src/lib/__tests__/access.test.ts`** — 24 tests over the permission map, including that a
  client or worker account cannot reach `/app` by any route, and that `/app` as the shortest
  prefix never shadows a more specific rule
- `npm test`, `npm run test:watch`, `npm run test:php`; both wired into CI

### Changed — the documentation checker consults git

`scripts/docs_sync.py` flagged `server/config.php` as a missing file. It is missing by design —
it holds the live credentials and is gitignored — so the checker now asks
`git check-ignore` before reporting, rather than carrying a hand-maintained allowlist that
would itself go stale. Verified both ways: a genuinely absent path still fails the check.

### Added — the compliance engine's decision logic

The question the whole system exists to answer — *may this person work in this service, and if
not, why not?* — is now a tested pure function, built ahead of its storage precisely because
being pure makes it verifiable without a database.

`rg_evaluate_eligibility()` in `server/pure.php` takes a rule set and a person's evidence and
returns a verdict, a score, every blocking reason and every warning. The behaviour it encodes:

- **A mandatory requirement that is unmet blocks**, and the refusal names it. Every blocker is
  reported, not just the first — fixing one at a time, with a fresh refusal on each attempt, is
  how an office loses a day
- **A mandatory credential inside its expiry window warns without blocking.** The person is
  still valid today, and standing them down early is its own failure
- **A recommended requirement never blocks**, and an optional one neither blocks nor warns
- **Missing, expired, undated and unverified are four different states.** No evidence on file
  is a gap; a lapsed credential is a lapse; a credential that should carry an expiry but does
  not is incomplete evidence, not valid evidence; and an unverified document is a claim
- **An empty rule set scores 0, not 100.** "Nothing configured yet" must never read as "fully
  compliant" on a dashboard
- **A refusal always carries a readable reason** — "SIA licence: expired 31 days ago", not a
  status code

48 assertions cover it, including the boundary days where an off-by-one becomes a person
wrongly stood down or wrongly deployed. One of them caught an arithmetic error in the test's
own expected score, which is the point of writing them.

Storage for the rules, and wiring the evaluator into the endpoints, remain — see
`docs/ROADMAP.md` Milestone 2.

### Fixed — exception messages no longer leak

`server/lib.php` returned `$e->getMessage()` to whoever made the request, regardless of
`GK_DEBUG`. Any input that provoked a fatal handed the caller table names, file paths and query
fragments. The full detail now goes to `error_log` with a short reference; the caller gets the
reference alone, so a user can quote it in a support request without ever having seen the
underlying error. `GK_DEBUG` still returns the detail locally.

### Added — the person spine, applied and verified locally

> Applied to a real MariaDB and verified end to end — schema, migrations, fixtures, backfill,
> and the API exercised against the result. **Not yet applied to production.**

Running it found three things that reading it did not:

1. **A foreign key does not force a row to be linked.** Migration 002 was written on the
   assumption that it would block on an unresolved match. It does not — a foreign key
   constrains non-NULL values only, and 002 applied cleanly with an unlinked row present. The
   claim was corrected in the migration, `ARCHITECTURE.md` and `SCHEMA.md`, and migration 004
   added: `person_id NOT NULL` is what actually requires the link.
2. **The backfill ignored links the database already recorded.** `users.worker_id` and
   `applicants.worker_id` are facts somebody entered, and matching on email and phone alone
   split a worker from their own login. `rg_group_people()` now unions explicit links with
   matched keys, which also handles the transitive case where a record joins one group by its
   link and another by its email.
3. **004 depended on the server's `sql_mode`.** Without `STRICT_TRANS_TABLES` MySQL coerces an
   unlinked `person_id` to 0 rather than failing, leaving the spine quietly wrong instead of
   loudly unfinished. It now sets `STRICT_ALL_TABLES` for its own session.

Verified: 22 people from 23 source records with no duplicates; the UNIQUE `match_key` refuses a
second person with the same identity (error 1062); 004's precondition blocks on an unlinked row
and reports the count; the API answers correctly against the migrated database, with all four
access restrictions returning 403 and 401 as intended.

- **`server/migrate.php`** — a migration runner. `server/schema.sql` stays the from-scratch
  baseline; everything after it is a numbered file applied in order and recorded with a
  checksum. Dry run is the default; applying takes `--go`; a non-local database additionally
  takes `--production` and a typed database name, because MySQL DDL is not transactional and a
  migration that fails halfway leaves the schema halfway. An applied migration that has since
  been edited is reported as drift, because re-running it would not reconcile anything
- **`migrations/001_people_spine.sql`** — `people`, `person_services`, nullable `person_id` on
  `workers`/`applicants`/`users`/`enrolments`, and `workers.payroll_no`. It only *adds*: no
  column is dropped or renamed, so the application keeps working unchanged while the endpoints
  move over one at a time. `people.match_key` is UNIQUE, so the database itself refuses a second
  person with the same identity — the guarantee the old copy-on-onboard behaviour could not make
- **`migrations/002_people_foreign_keys.sql`** — split from 001 on purpose. A foreign key fails
  if any row holds an unresolved value, so an ambiguous match left unresolved *blocks* this
  migration rather than being silently guessed at. `ON DELETE RESTRICT`, because removing a
  person goes through the deletion-approval workflow, never a cascade that takes their
  employment history with it
- **`migrations/003_compliance_rules.sql`** — storage for the engine already built: 13 seeded
  requirements and the rules per service, seeded to reproduce exactly what `scheduling.php`
  enforces in code today, so wiring the engine in changes no behaviour on day one. Everything
  beyond that starts as `recommended`, so the business raises a requirement to mandatory
  deliberately rather than discovering overnight that half the workforce is blocked
- **`server/backfill-people.php`** — dry run by default. Reports what will be created, what
  will collapse into one person, what has no email or phone, and what is ambiguous

### Added — person matching, and its refusals

The matching logic is pure and covered by 34 assertions, which is what makes an otherwise
unverifiable migration defensible:

- **Email first, then phone. Never a name.** Matching on names would collapse every John Smith
  into one worker
- **Synthetic addresses are not identities.** `ats.php` fabricated
  `<name>@learner.invalid` for learners with no email; matching on those would collide every
  such learner into one person
- **`07700 900123`, `+447700900123` and `00447700900123` are one key.** Anything under seven
  digits is an extension, not an identity
- **A group whose names disagree is not merged.** A shared landline is not proof of one person,
  so it is reported for a human to resolve. A wrong merge joins two employment histories, which
  is far harder to undo than an unmerged duplicate
- Records with neither email nor phone each become their own person — that can leave a
  duplicate, which is the safe direction to be wrong in

### Fixed — the identity defect itself

`ats.php` no longer copies. `find_or_create_person()` in `server/lib.php` now sits in front of
**every** path that can create a human record — the public application, self-registration, the
Academy learner handoff, direct worker creation and user creation — matching on the same rules
as the backfill. A login linked to a worker inherits that worker's person rather than resolving
its own, so the login and the employment record can never describe two different people.

`onboard` reads identity back from `people` instead of copying it off the applicant, and
assigns a payroll number: `RG-000001`, from `next_seq()`, unique and never reused. It also
records the person as intended for that service in `person_services`.

Verified end to end against a real database. Two applications for one human (the second with
the phone number written differently), then an onboarding, then a login creation:

```
person  full_name     match_key                        apps  workers  logins  payroll
23      Jordan Blake  e:jordan.blake@example.com       2     1        1       RG-000001
```

**One person.** Before this, that sequence produced three or four separate identities.

A second employment record created for the same person attaches to the same identity, which is
the multi-service capability `workers.division` could never express: one human, two roles, one
record of who they are.

### Fixed — failed queries no longer pass silently

`server/lib.php` set `mysqli_report(MYSQLI_REPORT_OFF)`, and almost no call site checks the
return value of `execute()`. A failed INSERT was therefore ignored and the endpoint went on to
report success — `workers.php` returned HTTP 200 with a complete worker object, `id: 0`, for a
row that was never written. Found because the new NOT NULL constraint made an insert fail.

Reporting is now `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`, set *after* the connection is
established so `config.php` keeps its own readable connection-failure message. Anything thrown
reaches the exception handler, which logs the detail and returns a reference. That single
change protects every one of the 47 endpoints, not just the ones touched here.

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
