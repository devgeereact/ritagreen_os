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

### Added — the compliance engine, wired to real data

`server/compliance-lib.php` is the boundary between the tested decision logic and the database:
`rg_load_rules()` reads the configured rule set for a service and role, `rg_load_evidence()`
gathers what is on file for a person, `rg_assess_person()` evaluates and records the verdict in
`person_services`, and `rg_deployment_check()` is the single gate every assignment path will
call. Exposed as `compliance.php?action=eligibility` and `?action=eligibility_all`.

Verified against real data, and it is the blueprint's central claim made concrete:

```
Esi Mensah
   care      ELIGIBLE      71%
   cleaning  ELIGIBLE      89%
   security  NOT ELIGIBLE  75%  <- SIA licence: expired 3 days ago

Grace Okafor
   care      NOT ELIGIBLE  57%  <- DBS check: no evidence on file
   cleaning  ELIGIBLE     100%
   security  NOT ELIGIBLE  67%  <- SIA licence: no evidence on file
```

One person, a different answer per service, and one lapsed credential blocking exactly one
service. That could not be expressed at all while `workers.division` was a single ENUM.

### Changed — right to work is provisionally non-expiring

Migration 005. The catalogue declared `right_to_work` as expiring, which is correct, but the
evidence behind it is still `workers.right_to_work` — a `TINYINT(1)` with no date. The engine
therefore classified every worker who *has* right to work as `undated`, meaning incomplete
evidence, and blocked them from every service.

That was the engine being right about a model that is not ready. The requirement is marked
non-expiring until Milestone 3 replaces the boolean with a check record carrying a method, a
date, a verifier and an expiry. It is a one-row `UPDATE` rather than a code change, which is
the whole reason rules live in the database.

### Added — the deployment gate

`server/scheduling.php` no longer carries its own hard-coded DBS-for-care and SIA-for-security
pair. It calls `rg_deployment_check()`, which evaluates the rule set configured for the service
and refuses the assignment when anything mandatory is unmet.

The refusal names **every** blocking requirement at once, rather than surfacing them one 409 at
a time, and a warning does not block — a credential inside its expiry window is still valid
today, and standing someone down early is its own failure. A blocked attempt writes a
`shift.blocked` audit row with the reason, so what is actually preventing deployments becomes
visible rather than merely felt.

Verified:

```
POST scheduling.php  worker: Esi Mensah, security shift
409  Esi Mensah cannot be deployed. SIA licence: expired 3 days ago
     blocking: [ SIA licence — expired 3 days ago ]

POST scheduling.php  worker: Dmitri Volkov, same shift
201  shift created
```

### Added — right to work becomes evidence

It was `workers.right_to_work TINYINT(1)`: no method, no date, no verifier, no evidence, no
expiry, no audit trail. The one field that most needed a trail had none, and it was the weakest
point in the system in legal terms.

- **Migration 006** adds `right_to_work_checks` — method, method note, reference, check date,
  outcome, follow-up flag, recheck date, evidence file, verifier, verified-at, notes. The old
  boolean is kept and synchronised, so nothing that still reads it regresses
- **`server/right-to-work.php`** records a check, lists a person's history, reports what needs
  attention, and verifies a check. **Recording and verifying are separate actions**, because
  the person who collects a document is often not the person who signs it off, and "verified"
  with nobody's name against it is not verification. A check whose outcome is still `pending`
  cannot be verified — signing that off would be signing off nothing
- The engine now reads the check record rather than the boolean: the most recent check wins,
  its `recheck_due` is the expiry, and `verified` reflects whether a named person signed it

**The legacy flag was carried over honestly.** Migration 006 imports each existing
`right_to_work = 1` as an *unverified* historical assertion, with a note recording that no
method, verifier or date was captured at the time. Presenting it as a completed check would
have been inventing evidence.

**Migration 007 stages the consequence.** With verification enforced, every migrated check is
unverified, so the engine correctly blocks the entire workforce. Correct, and unusable. The
requirement is recorded but not enforced until the office has worked through the backlog;
turning it on is a one-row `UPDATE`, and the migration carries both that statement and the
query to count who it would stand down first.

### Added — templated, queued, logged email

`send_mail()` opened a socket and spoke SMTP **inside the web request**. A slow mail server
therefore slowed the application, and a failed send was lost: nothing recorded that it had been
attempted, so nobody found out an applicant never got their confirmation.

- **Migration 008** adds `email_templates` (7 seeded, with `{{placeholders}}`), `email_queue`
  and `email_log`
- **`queue_mail()` and `queue_templated_mail()`** in `server/lib.php`. The templated form
  **refuses to send a half-filled message** — an email that goes out reading "Hi {{first_name}},"
  has already reached the recipient by the time anyone notices
- **`server/send-queued-mail.php`** drains by cron. It claims a row before attempting it, so two
  overlapping cron runs cannot send the same message twice; caps retries at five, so a message
  that will never succeed stops churning and stays visible as failed; and batches at 50, because
  pushing a thousand emails through one SMTP connection gets throttled
- **Nine senders converted.** Three stay synchronous *on purpose*, and now say why in the code:
  the **lone-worker panic alert** (an alert waiting for the next cron run is not an alert),
  **password reset** (somebody is sitting on the screen waiting for it), and interactive
  **mailbox replies**

**Queued regardless of whether mail is enabled.** The `mail_enabled()` gate was removed from the
call sites: the queue is the record of what the system intended to send, and the drainer decides
what can actually go out. Gating at the call site meant a disabled mailer left no trace that
anybody should have been contacted. Verified — with mail off locally, a public enquiry queues
two messages and the drainer records both as `skipped` with the reason, rather than nothing
happening at all.

`rg_render_template()` leaves an unknown placeholder **in place** rather than emptying it. "Hi ,"
looks like a mistake to the recipient; "Hi {{first_name}}," looks like a mistake to whoever sent
it, and they are the person who can fix it.

### Added — an audit log that can answer a question

`audit_log` held a user, an action, a 255-character string and a timestamp. That records *that*
something happened, but not what changed, to which record, or from where. "Who marked this SIA
licence verified, and what did it say before?" was unanswerable.

Migration 009 adds `entity_type`, `entity_id`, `before_json`, `after_json`, `reason`, `ip` and
`user_agent`, plus indexes on entity, user and time — finding everything that happened to one
record is the commonest audit question, and there was no index for it.

`audit()` keeps its existing signature, so all ~90 call sites carry on working unchanged and
pick up IP and user agent automatically. The high-value ones now pass the rest:

```
action      entity    id  before                     after                      ip
ats.stage   applicant 1   {"stage":"applied"}        {"stage":"rejected"}       127.0.0.1
worker.pay  worker    1   {"pay_rate_pence":1350}    {"pay_rate_pence":1450}    127.0.0.1
```

`audit_diff()` records **only what changed** — `pay_type` is absent above because it did not.
An entry holding a copy of every field is a second copy of the data, which is its own retention
problem.

`audit.php` gains filters: `?entity_type=worker&entity_id=12` for one record's whole history,
`?action=` for one kind of event, `?user_id=` for one person's activity. Reading the log **with
a filter** is itself audited; the routine full listing is not, or the log fills with entries
about reading the log.

### Added — eligibility, visible

`src/lib/types.ts` did not know about any of the last few milestones. It now carries `Person`,
`EligibilityVerdict`, `RequirementState`, `AssessedRequirement`, `RightToWorkCheck`,
`QueuedEmail` and a fuller `AuditEntry` — a narrower duplicate `AuditEntry` was **merged**
rather than left alongside it, since two definitions of one thing is the drift this work exists
to remove.

`src/components/app/eligibility-dialog.tsx` shows one person's answer for all four services,
opened from the status column on the Workers page. It lists **every** blocking reason, not just
the first, and it is read-only by construction — there is no endpoint that would accept a
compliance status if it tried.

Written so it does not add to the lint baseline: `loading` is *derived* from a single state
object carrying the worker it belongs to, rather than set at the top of the effect. That also
makes a stale response impossible to render — a reply for a worker who is no longer selected
simply never matches.

### Added — the Action Centre

`server/action-centre.php` answers one question: what needs attention today? The office should
not have to visit fifteen screens to find out, and an owner should not have to know which
screen a problem would appear on.

Grouped by **urgency, not category** — a list sorted by which module a problem belongs to
answers a question nobody asked. Everything is **derived**: there is no action table to fall
out of step with reality, so an item cannot linger after the thing it describes is fixed.

```
counts: urgent 5, today 4, soon 2

[urgent] 7 people cannot be deployed
         Audit Probe (cleaning): Signed contract: no evidence on file; Identity: ...
[urgent] 1 SIA licence has expired
[urgent] 2 unfilled shifts start within 3 days
         Nobody is assigned. These are the ones that become a phone call at 6am.
[today ] 8 right-to-work checks need verifying
[soon  ] 1 credential expires within 30 days
         Still valid today. Chase now and nobody is stood down later.
```

It also watches **itself**: failed emails, and a queue not drained for over two hours. If the
mail cron stops, the first symptom is otherwise somebody saying they never heard from us.

Staff-only. A worker account gets 403.

### Changed — eligibility is recalculated daily

Eligibility is a calculated value, so it goes stale the moment a credential lapses, not when
somebody next opens a page. Reassessment is folded into the **existing** `expiry-alerts` cron
rather than adding another one to configure, so the Action Centre and the dashboards are true
without anyone having looked.

Only the services a person is actually intended for are assessed. Scoring everyone against
everything would produce a wall of "not eligible for Security" for cleaners who were never
going to work security.

### Added — a PWA that actually works offline, and 37 MB less of it

`public/sw.js` was hand-written. It precached four fixed paths and knew nothing about hashed
asset names, so assets were cached only opportunistically after a first fetch and **offline
broke after every deploy** — the one moment it most needed to work.

- `vite-plugin-pwa` replaces it, with a **build-hash precache manifest**: a deploy invalidates
  exactly what changed
- Manifest generated from the config, so it cannot drift. 192, 512 and a separate **maskable**
  icon, drawn inset into the middle 80% because Android crops it to the launcher's shape
- `src/components/pwa-status.tsx` — an update prompt and an offline banner. `registerType:
  "prompt"`, never automatic: swapping the app out from under somebody part-way through
  clocking in is its own kind of failure. The banner says what still works, not just that
  something is wrong
- Read-only worker data (`me.php`, `notifications.php`) is `NetworkFirst` with a 5-second
  timeout. **The rest of the PHP API is never cached** — stale compliance data would mean
  showing an eligibility answer that is no longer true, which is worse than showing nothing

**37 MB of orphaned assets deleted.** `public/cards`, `public/products`,
`public/illustrations`, `public/images/carousels`, `public/images/countries` and
`public/images/cards` were Pulse-UI template leftovers with **zero references** anywhere in the
source. They were shipped on every deploy and precached into the installable app.

The office-only chunks are excluded from the install too: the app starts at `/m`, and a field
worker should not download a charts library, a calendar and a PDF engine they will never open.

```
public/    37 MB  ->  756 KB
precache   14.5 MB across 115 entries  ->  1.15 MB across 67
```

### Fixed — the app icon was still the old brand

`public/favicon.svg` was green `#15803D` / `#22C55E`, from the palette retired in the v2
rebrand. Redrawn in navy and mint, with PNGs generated from it for every size the manifest and
iOS need.

### Added — employment records: contracts, leave, document metadata, retention

Migration 010, closing three gaps at once.

**There was no employment contract.** The `contracts` table is a *commercial* contract with a
client — scope, rate, frequency, SLA. Nothing described the agreement with a worker.
`employment_contracts` adds draft → sent → viewed → accepted → signed, with `version` and
`supersedes_id`: a signed contract is evidence of what was agreed at a point in time, so it is
superseded rather than edited.

**The Acas particulars were not queryable.** They are now fields — job title, place of work,
pay and frequency, hours pattern, holiday entitlement, sick pay, other paid leave, notice both
ways, probation, training entitlement, pension, collective agreements. A PDF is the artefact;
these are what make them answerable without a human opening a document.

**There was nowhere to record holiday.** `leave_records` plus `server/leave.php` covers annual,
sickness, unpaid, parental, bereavement, compassionate, jury service and training — with
holiday pay, payments in lieu, carry-over, and return-to-work discussions after sickness.

Counted in **hours, not days**. Shift work is not made of whole days, and a balance in days is
wrong for anyone on variable hours.

Two decisions worth stating:

- **A request is not an agreement.** Requested leave is reported but *not* deducted from the
  balance. Showing a balance already reduced by an unapproved request misleads both sides.
- **No entitlement recorded is not an entitlement of zero.** The balance returns `null` rather
  than a large negative number, which would read as "overdrawn" when it means "nobody set this
  up" — the same distinction as `unknown` versus `expired` in compliance.

Overlapping leave is refused, naming the dates that clash, rather than quietly double-booking
somebody's holiday. A worker may request their own leave and see only their own; whatever
status they submit, it is recorded as a request.

**Document metadata:** `files` gained `issued_on`, `expires_on`, `verification_status`,
`verified_by`, `verified_at` and `person_id`. A document with no issue date, no expiry and no
verifier cannot be evidence of anything.

**Retention as configuration:** `retention_policies` is seeded with eight record types and
**NULL periods, deliberately**. The values belong to the business and its legal advice, not to
this schema, and NULL is distinct from zero so a dashboard can say "no policy set" rather than
implying immediate deletion.

### Added — Google Forms intake

The office already runs recruitment through Google Forms. The decision taken was to keep that
**and** the native `/apply` page, with Forms as an *integration* rather than the database — so
the CMS owns the record and Forms can be replaced later without rebuilding anything.

- **`server/forms-intake.php`** — a webhook for an Apps Script on the form's submit trigger.
  Authenticated by a shared secret compared with `hash_equals` (Apps Script has no login), rate
  limited because the endpoint is necessarily public, and **redelivery-safe** via the form's own
  response id
- **The mapping is configuration.** `integration_field_maps` holds question → CMS field, with
  optional value translation so the form can say "Security Officer" and the CMS store
  `security`. A form that gains a question, or has one reworded, must not need a code change
- **Matching is deliberately forgiving** — case, surrounding whitespace and the trailing
  asterisk Google adds to required questions. An exact-string join breaks *silently*:
  submissions arrive and map to nothing
- **The raw payload is stored first** and kept whether or not it maps. A submission that fails
  to map is a person who applied and would otherwise vanish; the payload is what makes them
  recoverable once the mapping is fixed. `?action=retry` re-maps one
- **Nothing is discarded.** Answers the mapping could not place are written onto the applicant
  as `[unmapped] <question>: <answer>`, so a new question on the form shows up as something to
  configure rather than disappearing

**Silence is the failure that matters.** A form that stops feeding the system looks exactly
like a quiet week. Each integration carries `expect_within_hours`, and the Action Centre reports
anything past its window — along with any submission that could not be mapped.

Verified end to end: a missing or wrong secret is refused with 401; a real submission creates
one person and one applicant and reports the unmapped question; the same response redelivered
returns `duplicate: true` without creating a second application; a submission missing a required
answer fails with the question named, and its payload is retained.

### Added — deletion approval, export logging, retention settings

Two gaps with the same shape: an action that removes or copies personal data left no trace of
who decided it was alright.

**Nobody deletes an employment record on their own say so.** `server/governance.php` gives
ordinary staff *request deletion*, not *delete*. A reason of at least 10 characters is
required — a request nobody can explain later is not one an approver can sensibly decide on —
and **the approver may not be the requester**. Verified: an admin attempting to approve their
own request is refused. Archive is the default disposition, and the label of what was removed
is captured at request time, because the record may be gone by the time anybody reads it.

**Every export of personal data is logged** — user, type, format, record count, scope, purpose
and IP. Exports are built in the browser from data already fetched, so a client call is the
only point at which the server can know one happened. Where a spreadsheet goes after it leaves
is outside this system's control, which is precisely the reason to record that it left.

**Retention periods are editable and start NULL**, deliberately. The values belong to the
business and its legal advice, and NULL is distinct from zero so a dashboard can say "no policy
set" rather than implying immediate deletion.

### Fixed — archiving now actually archives

The deletion workflow could not archive an applicant: `applicants` had no `deleted_at`, leaving
a hard `DELETE` as the only option, which is exactly backwards. Migration 013 adds `deleted_at`
to `applicants`, `invoices`, `incidents`, `timesheets` and `shifts` — five tables where a
removed row destroys a recruitment decision, a financial record, a statutory report, pay
evidence or a worked shift, and three of which cascade to child rows.

It also adds `updated_at` to `timesheets`, which had neither, so an approved timesheet could be
altered with no trace of when; and the index on `shifts.client_id`, which `scheduling.php` joins
on and which had neither a key nor a foreign key while `shift_templates.client_id` in the same
build pass had both.

Every list and count query for those tables now filters on `deleted_at`. An archive that leaves
the row visible is not an archive.

### Fixed — the dashboard binds its parameters

`server/dashboard.php` interpolated the division filter with `real_escape_string`. The value was
already checked against an allowlist so it was not injectable — but the guarantee depended on
remembering that check, and the next person adding a filter had a pattern to copy that did not
carry it. Every division filter is now bound. Verified across all four divisions.

### Fixed — the constraints the build passes could not add

Every column added by a later `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` in `schema.sql` is
unconstrained, because that syntax cannot carry a constraint. That is the mechanical cause of
the foreign-key gaps rather than an oversight by anyone. Migration 014 adds the six that were
missing: `users.client_id`, `users.worker_id`, `clients.account_manager_id`,
`shifts.client_id`, `service_requests.shift_id` and `audit_log.user_id` — the last of which was
joined in `audit.php` with neither a key nor an index, making every audit query a full scan.

`ON DELETE SET NULL` throughout rather than CASCADE. A deleted user must not take their audit
entries with them — that is the opposite of what an audit log is for.

**`clients.divisions` was a comma-separated string.** It cannot be joined, indexed or
constrained, and "care" matches "healthcare" under `LIKE`. `client_services` is the same pattern
the schema already contains as `worker_skills`, applied to the one place it was solved a second,
worse way. The CSV is kept in step by `clients.php?action=services` until nothing reads it —
two sources of one fact drift, so they are written in one place.

### Added — training records join the person spine

`academy.php?action=enrol` resolved nothing: `enrolments` carried a `learner_name` string, so a
person's training history was disconnected from the person.

It now resolves a person from an explicit id, or from an email or phone. With only a name it
records the enrolment **unlinked** rather than guessing — matching on a name would merge every
John Smith — and the Action Centre lists those to be linked by hand.

That completes the spine. Every entry point now resolves to one person: the public application,
self-registration, the Academy learner handoff, worker creation, user creation, the Google Forms
webhook, and training enrolment.

### Added — per-service scope and sensitive zones

Two problems with the same root: a permission that was recorded but never enforced.

**`users.division` was set and ignored.** A Care recruiter saw Security candidates, which is not
what "scoped to a division" means. `service_scope()` now filters the workforce and candidate
lists. It is **opt-in per account** via `users.scope_to_division`, defaulting off, so this
migration narrows nobody's access on the day it runs — silently narrowing access is how a Monday
morning becomes "the system is broken". `super_admin` and `admin` are never scoped: somebody has
to be able to see the whole picture.

**A worker profile was one undifferentiated record.** Health information, disciplinary matters
and payroll are not ordinary fields, and "can open the profile" must not mean "can see all of
it". `zone_permissions` is a role × zone matrix — general, employment, compliance, health,
payroll, disciplinary, legal — editable by a super admin without a deployment.

`can_view_zone()` replaces the hard-coded role list that gated pay on the workforce list. **No
row means no permission**, so a zone nobody has configured is not readable by default.

Two guards on the matrix itself: a super admin cannot be denied a zone, because the account that
fixes a mistake must not be able to lose the screen that fixes it; and edit-without-view is
refused as incoherent.

Verified: a manager scoped to Care sees 3 workers, all Care, with pay. A trainer sees all 10
workers across every division and **no pay at all**, because the trainer role has no payroll
zone. Both from the same endpoint, with no role check in it.

### Added — system health and staff workload

The owner should not learn that recruitment stopped importing three weeks ago from a candidate
ringing up.

`server/system-health.php` runs **live checks, not a stored status** — a health table goes stale
exactly when it matters. It covers the database, migrations shipped but never run, mail, the
queue drainer, integrations, eligibility freshness, document storage and backups. States are
`ok` / `warn` / `fail` / **`off`**, because a deliberately disabled mailer is a configuration
choice and must not read as a fault.

It earned its place immediately: on first run it reported `fail — the uploads directory does not
exist`, which would have meant every document upload failing.

Two checks worth calling out. **Unapplied migrations**: a deploy that ships the files without
running them looks completely normal until something fails oddly, so the directory is compared
against `schema_migrations`. And **restore testing**, which cannot be inferred — it is reported
as an explicit unknown rather than assumed fine, because a backup nobody has restored is a hope,
not a backup.

`?action=workload` shows what each staff member is carrying. Not a performance measure: it
exists so work can be moved before somebody quietly drowns in it, which is only possible if it
is visible.

### Added — worker status is derived, and training can come from anywhere

`workers.status` was active/onboarding/inactive, **set by hand**. A status somebody has to
remember to change is a status that is wrong, and this one decides whether a person appears in
the workforce the office schedules from.

`workers.derived_status` is now computed daily from the evidence: leave, onboarding tasks, a
signed contract, and how many services the person is actually eligible for. The manual column
stays as the **intent** — what the business means to do with this person — and the derived one
is what is true. Keeping them apart matters: "we intend to employ them and they are not yet
cleared" is a real and common state, and collapsing it into one field loses exactly the
information the office needs.

The fixtures make the point: **all ten workers read `active` manually; four genuinely are.** The
other six have no signed contract on file.

Order is the whole design, and most of the 16 tests cover which rule wins rather than any rule
alone. Having left outranks everything — a leaver is not a compliance problem. A suspension is a
decision, not a derivation. Approved leave outranks ineligibility, because somebody on holiday
has not *become* non-compliant.

**Training providers:** `programmes` were implicitly RitaGreen Academy's, so a worker who did
their first aid with St John Ambulance had training the system could not record. `enrolments`
now carries a provider, an external course name, and completion and expiry dates, and
`programme_id` is nullable because training done elsewhere has no programme of ours.

### Fixed — reassessment was skipped on quiet days

The eligibility and status recalculation sat at the end of `expiry-alerts.php`, **after an
`exit` taken when no reminder was due**. On a quiet day nothing was recalculated at all, so
eligibility went stale on exactly the days nobody was watching. It now runs first and
unconditionally, which also means the reminders are composed from a fresh assessment.

### Added — one verification lifecycle for everything

Verification was three different shapes. `worker_references` had a status. `right_to_work_checks`
had its own `verified_by`/`verified_at`. Everything else — an SIA licence, a DBS certificate, a
qualification, a document — had **nothing**: a number typed into a field, with no record of
whether anybody looked at the register, who, or when.

`verifications` gives them one shape: requested → received → under review → verified / rejected
/ expired. **Only legal transitions are permitted**, and a refusal names what *is* possible:

```
POST verify   (no method)   -> Record what you checked, so the entry means something later.
POST received (on verified) -> A verification cannot go from 'verified' to 'received'.
                               From here it can only become: expired.
```

`received` and `under_review` are separate on purpose. A document arriving and somebody actually
checking it are different events with different people behind them, and collapsing them is how
"we have it" quietly becomes "we checked it".

**Signing one off requires recording what was checked.** "Checked the SIA public register
against the licence number" and "saw a photocopy" are not the same assurance, and only that
field can tell them apart later.

**The existing data came across honestly.** An SIA number or DBS status against a worker means
somebody typed it in; it does not mean anybody checked a register. They import as `received`,
not `verified`, each carrying a note saying exactly that. Marking them verified would have been
inventing an assurance nobody gave.

`right_to_work_checks` is deliberately **not** folded in — it carries methods, share codes and
recheck rules that belong to it alone, and flattening those into a generic table would lose them.

The compliance engine reads a completed verification in preference to a typed field, but
**augments rather than replaces**: a check still at `received` does not downgrade the underlying
record, because every migrated entry starts there and downgrading them at once would block the
entire workforce on the day this shipped. Same trap right to work hit, same answer — the
evidence model improves now, enforcement follows once the backlog is worked through.

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
