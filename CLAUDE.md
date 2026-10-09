# CLAUDE.md — RitaGreen OS

Persistent context for Claude Code in this repository. Read it fully before doing any work.

**This file is a pointer, not a duplicate.** The detail lives in [`docs/`](docs/) and this file
links to it. Duplicated facts drift, and the stale copy is the one that gets trusted — see
`docs/RULES.md` rule 20.

| Read this | Before |
|---|---|
| [`docs/RULES.md`](docs/RULES.md) | Changing anything |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Touching the API or auth |
| [`docs/BLUEPRINT.md`](docs/BLUEPRINT.md) | Proposing a feature |
| [`docs/SCHEMA.md`](docs/SCHEMA.md) | Touching the database |
| [`docs/API.md`](docs/API.md) | Adding or changing an endpoint |
| [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md) | Writing UI |
| [`docs/PRIVACY.md`](docs/PRIVACY.md) | Touching anything that holds personal data |
| [`docs/COOKIES.md`](docs/COOKIES.md) | Adding browser storage or a third-party request |
| [`docs/RETENTION.md`](docs/RETENTION.md) | Deleting or expiring anything |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | Deciding what to do next |

---

## 1. Project

| Item | Value |
|---|---|
| Repository | `devgeereact/ritagreen_os` |
| Domain | `ritagreenservices.com` |
| Deploy branch | `main` — **pushing to `main` deploys to production** |
| Dev port | 5122, `strictPort` |
| Database | `ritagreen_os` (cPanel may prefix with the account name) |
| Language | TypeScript, strict |

One merged Vite application: the public corporate site at the root, the internal CMS behind
`/login` at `/app/*`, a client portal at `/portal`, a worker app at `/m`.

## 2. Architecture in one paragraph

React 19 SPA (static `dist/`) → 54 flat PHP endpoint files in `server/` → MySQL on cPanel.
The browser never touches the database; it calls PHP over HTTP with an
`Authorization: Bearer` token and receives JSON. Every endpoint begins
`require __DIR__ . '/lib.php'`, which is the shared kernel. There is no framework, no router
and no Composer — the host cannot install packages. Full detail in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## 3. Standing traps

Things that have already caused problems here, or will.

**Development is local, and must stay that way.** `npm run dev:api` serves the PHP tier on
8122 and the Vite proxy points there by default. `server/config.local.php.example` disables
every outbound integration; `npm run seed -- --confirm` fills a local database with fixtures.
Reaching production needs an explicit `RG_API_TARGET` and prints a warning. It used to be the
default, which meant every developer was one query away from editing live workforce records.
**Never run a migration against anything but a local database.**

**`config.php` declares which database it is, and the CLI tools believe it, not `DB_HOST`.**
`define('RG_ENV', 'local')` or `'production'`, read through `rg_is_local_database()`. They used
to infer it from the host, and on cPanel the production database is `localhost` too — so the live
system read as local: `migrate.php --go` skipped the typed-name confirmation and
`seed.php --confirm --reset` would have emptied clients, workers and invoices on it. A
`config.php` without the constant counts as production, because the cost of being wrong that way
is a refusal rather than a restore from backup.

**A statement belongs in `schema.sql` only if the table it names is created in `schema.sql`.**
It had drifted the other way: migration content was copied back into the baseline, and three of
those copies were not re-runnable. A fresh install failed on statement three with an `ALTER` on
`integration_submissions`, a table migration 011 creates; then on migration 020 with a duplicate
`idx_consent_email_kind`, leaving 020 to 030 unapplied; then on 027, 029 and 030 with duplicate
foreign keys, because MySQL has no `ADD CONSTRAINT IF NOT EXISTS`. `npm run test:db` builds a
database from `schema.sql` plus every migration on every run, so this fails in CI now rather than
on the day somebody needs a new environment.

**Two schema mechanisms, and you need both.** `server/schema.sql` is the from-scratch baseline
— one re-runnable file organised in "build passes", where later
`ALTER TABLE ... ADD COLUMN IF NOT EXISTS` blocks patch earlier `CREATE TABLE` statements *in
the same file*, so a table's real shape is its `CREATE` **plus** every later `ALTER`.
Everything new goes in `server/migrations/NNN_name.sql`, applied by `php server/migrate.php`.
Dry run is the default; a non-local database needs `--production` and a typed database name.

**A foreign key does not force a row to be linked** — it constrains non-NULL values only. Only
`NOT NULL` does. That was assumed otherwise here and corrected after testing it against a real
database; see migration 004 and its precondition.

**A person can exist three times — link, never copy.** The `people` spine exists, every
record is linked to it, and `ats.php` `onboard` / `from_learner` were rewritten to use
`find_or_create_person()` and read identity back from `people`. The `workers` row still carries
denormalised `name`/`email`/`phone` columns beside `person_id`; treat `people` as the truth and
never introduce a new path that writes identity from anywhere else. `server/backfill-people.php --go` is safe to run twice: it leaves rows that
already carry a `person_id` alone and gives an unlinked row the person its group already has.
It was not — it inserted a fresh person for every group regardless, repointed every record at
the duplicate, and orphaned the original along with the right-to-work checks, verifications,
eligibility and leave records still hanging off it. Both the quickstart and the production
runbook run it after a seed, by which time everything is already linked.

`server/seed.php` is another of
those paths and did not: it inserted users, workers and applicants with no `person_id` at all,
so seeding a database with migration 004 applied — every developer's — failed outright with
"Field 'person_id' doesn't have a default value". It goes through `find_or_create_person()` now,
and a worker's login shares the worker's person rather than making a second one. See
`docs/BLUEPRINT.md`, "The identity defect".

**PHP is deployed by hand.** `.github/workflows/deploy.yml` uploads `dist/` only and explicitly
excludes `*.php`, `config.php` and `.htaccess`. Never add `dangerous-clean-slate` — it would
delete the entire API from the server.

**`server/config.php` is gitignored and holds every secret.** Database password, OpenRouter
key, Stripe, GoCardless, Twilio, SMTP and IMAP credentials. `server/config.sample.php` is the
tracked template with `CHANGE_ME` placeholders. Never put a real value in a tracked file, a
comment, a document or a commit message.

**Nothing secret reaches the client bundle.** There is no `.env` and there are no `VITE_*`
variables; all third-party calls are proxied server-side. Keep it that way.

**Client-side gating lives in one file.** `src/lib/access.ts` maps route to permitted roles and
is read by both `RequireAuth` and the sidebar, so a visible link always leads somewhere the user
may go. Add a route there when you add a route, or it will be visible to everyone. It mirrors
the `require_role()` calls in `server/` — and it is a user-experience guard only. **The server
is the enforced boundary.**

**The public site makes no third-party requests, and that is load-bearing.** Fonts are
self-hosted in `public/fonts` with the faces in `src/fonts.css`; the CSP in `public/.htaccess` is
`default-src 'self'` with no external origin allow-listed. Because nothing optional is loaded and
nothing optional is stored, the site shows a storage *notice* rather than a consent choice. Add a
third-party script, font, map or pixel and that stops being true: you then owe a real consent
control that keeps the script inert until the visitor agrees, an entry in `docs/COOKIES.md`, and
a line in the privacy notice. See `src/components/public/CookieConsent.tsx`.

**A guard goes above the read, not just above the write.** Twenty endpoints role-gated their
POST branches and left every GET on `require_auth()` alone, so a client portal login could read
the whole client base, every applicant, the rota and the workforce's compliance state by calling
the PHP file directly. The guard now sits immediately after `require_auth()`, above every
branch. `server/tests/guards.php` (in `npm run test:php`) fails if an endpoint returns anything
between `require_auth()` and its first authorisation call; endpoints that really do serve any
authenticated account are listed there with the reason.

**Service scope goes on the record, not on the list.** `require_service_scope()` after loading
a row, on reads as well as writes, on every branch — including the ones that take a task id or a
worker id rather than the obvious one, and the ones that take the service from the request body.
Only the ATS and workforce *list* queries applied `service_scope()`, so a manager confined to
security could read a care candidate in full by id, reject them, add and complete onboarding
tasks, convert them into a worker with a payroll number, and set another service's worker's pay.
`rg_in_service_scope()` in `server/pure.php` is the rule: a record with no service is **out** of
scope for a scoped account. It is opt-in per account through `users.scope_to_division`, which was
off for every fixture, so nothing exercised it — `scoped@local.invalid` exists now.

**A check and a write in two statements is not a check.** `ats.php?action=onboard` read
`worker_id`, found NULL, and inserted; four simultaneous requests made four workers from one
applicant, each with its own payroll number. It is one transaction with the applicant held `FOR
UPDATE` now. `find_or_create_person()` had the same shape against the UNIQUE `people.match_key`
and turned the database's correct refusal into a 500 for a returning applicant. Note that
`php -S` serves one request at a time unless `PHP_CLI_SERVER_WORKERS` is set, so a concurrency
test written without it passes against broken code — `server/tests/db/run.php` sets it to 8.

**A payroll number is permanent, and the counter has to know that.** `next_seq()` starts an
unseen counter at 1 and `workers.payroll_no` is UNIQUE, so on a database whose numbers predate
the counter the next conversion dies on the unique key. Migration 032 raises the counter to the
highest number in use; `server/backfill-payroll.php` fills in workers that have none. Neither
ever renumbers anybody: the old number is already on payslips and in records.

**A stage is a fact about work done, not a field to type into.** `rg_stage_transition()` in
`server/pure.php` holds the pipeline rules and `ats.php` enforces them: forward one stage at a
time, backward or out only with a reason, and `onboarding`/`onboarded` not settable by hand at all
— they are what starting onboarding and approving activation *do*. Every stage used to be
selectable from a dropdown, so `applied` → `onboarded` was one click with no worker, no checklist
and no approval behind it. `ats.php?id=N` returns `next_stages` from the same function, so the
screen offers exactly what the server will accept.

**Assigning work means assigning it to staff.** A recruiter, a task owner and an interviewer all
go through `is_assignable_staff()`, which is `is_staff()` on a live account. A client portal login
and a worker's login are ordinary `users` rows; either would have been shown, or emailed about, a
record they cannot open. `users.php?action=assignable` feeds the dropdowns from the same test.

**Sensitive data is separated by zone, not by role alone.** `can_view_zone()` reads
`zone_permissions` (migration 015), and it is enforced for `payroll`, `compliance` and `health`.
A new endpoint that returns right-to-work state, DBS status, an SIA number or anything about
somebody's health takes the zone check as well as `require_staff()`. `rg_file_readable()` does the
same job per file in `server/files.php` — being allowed to open the vault is not permission to
open everything in it.

**One clock, UTC, pinned in `server/lib.php`.** `date_default_timezone_set('UTC')` and
`SET time_zone = '+00:00'` on the connection, immediately after `mysqli_report()`. Neither was
pinned: PHP took the host's `date.timezone` and the connection took the MySQL server's default,
so on a developer machine `NOW()` returned `21:47:21` while `date()` in the same request
returned `20:47:21`. Every `created_at` was written on one clock and every date comparison in
PHP made on the other, for the seven months of British Summer Time. Do not reintroduce a local
timezone anywhere. DATETIME columns (`shifts.starts_at`, work dates) hold literal wall-clock and
are unaffected; TIMESTAMP columns are converted on read, so on a server that was not already UTC
an existing `created_at` now reads as the UTC instant it always was.

**A rejection is not "no evidence", and the difference is load-bearing.**
`rg_load_evidence()` tracks a `$decided` map of requirements a reviewer has ruled on, separately
from the `$evidence` entry a rejection removes. Without it the newest-wins test read
`from_verification` on a key the rejection had just unset, so the next row — an *older*
verification — won, and somebody rejected today read as verified. The certifications pass matches
on a lower-cased free-text label and must never outrank a decision: it could revive a rejection
outright, or quietly re-date a reviewed expiry. Same rule for references and the contract file.

**Employment state first, credentials second.** `rg_employment_gate()` in `server/pure.php` runs
inside `rg_deployment_check()` before any evidence is assessed: archived, left, suspended,
inactive, onboarding outstanding, not approved. The gate weighed credentials alone, so a suspended
worker or one nobody had ever approved could be booked on a shift with an in-date DBS. The verdict
carries `blocked_by` (`employment` or `evidence`) because the two need different people to fix
them. `workers.status` gained `'suspended'` in migration 035 — `rg_derive_worker_status()` had
branched on it since 016 against a column that could not hold it.

**Approval is a fact somebody is answerable for.** `workers.approved_at`/`approved_by`
(migration 034), granted by `workers.php?action=approve`, which requires onboarding finished and a
signed employment contract that has been **reviewed**. Migration 034 backfills every already-active
worker, because introducing the requirement would otherwise have stood the whole workforce down on
the day it shipped; `server/report-activation.php` says who is blocked and why, and writes nothing.

**Derived status is recalculated when something changes, not only at 3am.**
`rg_refresh_worker_status()` — called on verify, reject, approve and unapprove.
`rg_derive_worker_status()` used to run only in the nightly `expiry-alerts.php` sweep, so an
approval at 10am was invisible until the next morning. The sweep still runs.

**`server/employment-contracts.php` is employment evidence. `server/contracts.php` is commercial.**
Different tables, different people, different meanings of the word, and they were confused often
enough to be worth saying twice. Neither is a document designer or a signature service.

**`rg_deployment_check()` is the only gate, and three paths used to dodge it.**
`server/compliance-lib.php` also holds `rg_shift_clash()` and `rg_claim_check()` — the clash test
lives there rather than in `scheduling.php` precisely because an endpoint cannot be required by
another endpoint, which is how `offer.php` and `me.php` ended up with no clash test at all. Any
new path that puts a worker on a shift calls `rg_claim_check()`. A worker accepting an SMS offer
and a worker claiming a shift in the app both tested division and nothing else: a worker
scheduled 08:00–16:00 accepted an overlapping 09:00–17:00 shift through `/m` and ended up on
both. Matching and broadcast separately ran a legacy `worker_compliant()` — hard-coded DBS/SIA —
so "Find eligible" offered a nurse with a lapsed NMC registration whom assigning then refused.
That legacy pair is deleted; do not reintroduce a second opinion about who may work.

**Hours are the payroll zone, not just "internal".** `server/timesheets.php` and
`server/payroll.php` both take `can_view_zone($conn, $user, 'payroll')` on top of the role
check, above every branch. `require_staff()` alone was not a narrowing: an editor, a trainer and
an employee could each read every worker's hours for every day on record, while `workers.php`
was carefully hiding a single pay rate behind the same zone. `src/lib/access.ts` mirrors it with
`PAYROLL`, and `src/lib/__tests__/access.test.ts` pins it.

**A shift that ends before it starts is a night shift.** `work_date` is the date the shift
*starts*, so `22:00`–`06:00` is eight hours. `rg_shift_minutes()` in `server/pure.php` is the
one place that decides this, and it is explicitly UTC rather than trusting the host's
`date.timezone` — the same entry must not be worth different money on a laptop and on cPanel.
Equal times are refused rather than guessed at. The timesheet endpoint used to reject the whole
case as "the end time must be after the start time", so a business built on waking nights and
night guarding could not record a night, while `shifts` had stored overnight spans all along.

**A pay rate is dated.** `worker_pay_rates` (migration 022) is the history; `workers.pay_rate_pence`
is only the current figure, for the profile and for costing a new shift. `pay_compute()` prices
every entry at the rate in force on the day it was worked. It read the current rate, so a rise
re-priced every period the worker had ever done and no closed period could be reproduced.
Anything new that values past work reads the history, not the column.

**A premium belongs to an hour, not to an entry.** `rg_premium_segments()` in `server/pure.php`
splits a worked interval minute by minute and carries every band in force at that moment — a
Saturday at 22:00 is both weekend and night — and `pay_entry()` pays the dearest *configured*
band, so which one wins lives in `pay_rules` rather than in code. The previous model took one
premium for the whole entry from its start hour: a 05:00–13:00 shift was paid night for all
eight hours, and a Friday 20:00 → Saturday 04:00 shift night for all eight and weekend for none.

**`bank_holidays` is generated, not typed.** `rg_bank_holidays()` computes the standard eight for
a year and migration 023 seeds to 2035 from it. The table is still the source of truth, because
one-off holidays are announced rather than derived. Never hand-type dates into a money table: the
table held 2026 only and every 2027 bank holiday would have paid flat.

**The pay floor is configuration and it is empty.** `pay_floors` (migration 024) flags a rate
below it and never blocks a run. It is a floor, not the National Minimum Wage — NMW has age bands
and `workers` holds no date of birth. Same rule as retention periods: do not put a figure in code.

**The pay PDF is titled GROSS PAY, not PAYSLIP,** and says so in its footer. There is no PAYE, NI,
pension or net figure anywhere in the system, and no tax code or NI number in the schema to
compute them from. Do not retitle it until there is.

**Pay is on `minutes - break_minutes`.** `timesheets.minutes` is the clock span including the
break; `rg_deduct_break()` takes the break proportionally out of the premium bands, because
nothing records when it was taken. There was no break column at all, so gross hours were paid
door to door.

**Holiday accrual is informational and nothing pays it.** 12.07% of hours worked, never added to
gross, never written back, and separate from the leave balance — `leave_records` is never read by
the pay path, so taking leave produces no pay. Not a double payment; not a payment. Say which one
you mean before changing either.

**`pay_type` branches nothing.** An `umbrella` worker is calculated exactly as PAYE. The Payroll
page subtotals them and says so; do not read the gross total as a PAYE figure.

**Anything that turns hours into money reads `deleted_at`.** `pay_compute()` did not, so a
soft-deleted timesheet was still paid. Archiving is the disposal route this system has; a query
that ignores it converts an archive into a silent liability.

**A broadcast notification is read by receipt, not by a column.** `notification_reads`
(migration 025). One broadcast row is read by many people and `notifications` has one `read_at`,
so every broadcast was forced to read and the unread count ignored them — which meant a panic
alert, which is a broadcast, arrived pre-read and the bell never lit. Anything new that notifies
more than one person uses the receipts.

**A panic alert is a sequence, not a flag.** `rg_panic_open()` reads the ordered kinds, because a
shift can be raised, acknowledged and raised again. The board used `in_array('panic', ...)`, a
latch nothing could clear.

**Screening a list is not deciding about a person.** `rg_deployment_check(..., $persist = false)`
for bulk screening. It wrote a `person_services` row per worker, so "Find eligible" on a
200-worker division performed 200 writes on a GET, and broadcast did it again. `rg_load_rules()`
is memoised per request for the same reason.

**Location reads are audited; location rows can be archived.** `shift_events` is personal data
about the worker (`docs/PRIVACY.md`) and had neither: no retention policy at all and no
`deleted_at`, so the only disposal available was a hard DELETE. Migration 028 adds both, and
`field.php` records `field.location_read` when it actually releases coordinates — not when a
worker opens their own trail, which would be noise rather than accountability.

**Retention periods are configuration, and they are all NULL.** `server/retention-sweep.php`
reports by default, acts only with `--go`, acts only where a period is set, and never
hard-deletes. Do not put a period in code. See `docs/RETENTION.md`.

**Cloudflare records for this domain stay grey-cloud (DNS only)** so cPanel can issue SSL.
This differs from other projects on the account — do not "fix" it to match them.

## 4. Golden rules

The full list is [`docs/RULES.md`](docs/RULES.md). The ones most easily broken by accident:

1. **Compliance status is calculated, never set.** No editable "compliant" field, anywhere.
2. **A person exists once.** Link with an identifier; never copy identity fields.
3. **Never hard-delete an employment record.** Archive, or request deletion with approval.
4. **Prepared statements always.** No interpolation into SQL, for any value.
5. **Money is integers in pence.** Never a float.
6. **Branch, pull request, merge.** Never commit to `main`.
7. **Documentation states what the code does**, not what the plan says.

## 5. Working here

```bash
npm run dev:api      # PHP API on 127.0.0.1:8122  (run this too)
npm run dev          # localhost:5122
npm run typecheck    # tsc -b --force
npm run lint         # eslint . — has a known non-zero baseline, see below
npm test             # vitest, node environment, src/**/*.test.ts(x)
npm test -- access   # one test file, by substring of its path
npm run test:php     # php server/tests/run.php — whole suite, no database, no filtering
npm run test:db      # php server/tests/db/run.php — real HTTP against a disposable database
npm run build
```

Database chores (local only — see "Development is local" above):

```bash
php server/migrate.php            # dry run is the DEFAULT; --go applies
php server/backfill-people.php    # same: --go to write
npm run seed -- --confirm         # fixtures, CLI-only and local-host-only
```

CI (`.github/workflows/ci.yml`) runs typecheck, lint, `npm test`, build, a build-output
assertion (`dist/index.html`, `manifest.webmanifest`, `sw.js` present, **zero `*.map` files**),
`php -l` across every PHP file, `php server/tests/run.php`, `php server/tests/db/run.php` against
a MySQL service, a check that `server/config.php` is not tracked, and
`python3 scripts/docs_sync.py --check`.

**Deployment is gated on CI, for the exact commit CI tested.** `deploy.yml` triggers on
`workflow_run` when CI completes, and runs only when the conclusion is success, the branch is
`main`, the triggering event was a `push`, and the head repository is this one. It used to
trigger on `push: branches: [main]` alongside `ci.yml` — two workflows on one event, which start
together rather than in sequence, so the upload finished whether or not anything passed. The
`event == 'push'` and head-repository conditions matter because CI also runs on `pull_request`:
without them a fork branch named `main` would have reached a job that runs `npm ci` with the FTP
credentials in its environment. See `docs/TESTING.md`.

**Lint gates, at zero.** CI runs `eslint --max-warnings 0` and blocks. It was 33 errors, then
27, then 19; the last of them were cleared by changing the code rather than the rules. Keep it
there — a new error now fails the build, and the fix is the code, not a disable comment.

**Adding a feature with data:**

1. Add the table in a numbered migration (and to `server/schema.sql` as the baseline).
2. Add or extend an endpoint in `server/`. Follow the existing shape: `require lib.php`,
   `require_auth()`, `require_role()`, prepared statements, `json_out()`, `audit()` the
   mutation.
3. Add the type to `src/lib/types.ts`.
4. Fetch it with `useApi` from `src/hooks/use-api.ts`.
5. Update `docs/SCHEMA.md`, `docs/API.md`, `docs/TRACEABILITY.md` and `docs/STATUS.json`, then
   regenerate progress with `python3 scripts/docs_sync.py`.

**Pure logic goes in `server/pure.php`, and gets a test in `server/tests/run.php`.** If it is
a function of its arguments, it does not belong inside an endpoint. Give date functions an
injectable clock.

**Reuse before building.** `next_seq()` for any sequence. `rg_classify()` in `server/pure.php`
for any expiry date. The `compliance_reminders` dedupe pattern for any
tiered alert. The `server/files.php` streamed-download-behind-auth pattern for any document.
The `server/scheduling.php` compliance guard is the seed of the deployment gate.

## 6. Current state

181 done, 12 partial, 1 to do of 194 tracked items; see [`docs/PROGRESS.md`](docs/PROGRESS.md),
generated from `docs/STATUS.json`. Never edit `PROGRESS.md`; edit `STATUS.json` and regenerate.

Milestones 1 to 4 — the person spine, the compliance engine, right-to-work and credential
verification, and the deployment gate — are **built and verified against a local database**.
Migrations `001`–`030` exist in `server/migrations/` and all thirty are applied locally. The
deployment record for 2026-09-03 in `docs/CHANGELOG.md` says production was at 18 applied with 0
pending and that 019 was applied that day, so **`020` through `030` are outstanding there** — but
that is the record talking, not a check against the live database. Confirm with
`php server/migrate.php --status` on the server before acting on it.

The PHP tier is **not** deployed by the pipeline: `deploy.yml` uploads `dist/` only and excludes
`*.php`. A change to `server/` reaches production only when somebody uploads it, and shipping the
frontend ahead of it means the interface changes while the API does not.

What is left is concentrated in EMPLOYMENT and GOVERNANCE. Check `docs/PROGRESS.md` rather than
assuming from this paragraph.

## 7. Frontend traps

**Routing and chunking are load-bearing, and heavily commented in the files themselves.**
`src/routes/index.tsx` lazy-loads every route except `Home`; `AppLayout` is lazy too, so a
visitor to the marketing site never downloads the CMS shell. Adding an eager import to that
file puts whatever it pulls in into the entry chunk for every visitor.

**Do not add manual chunks in `vite.config.ts`.** Naming a chunk in `manualChunks` promotes it
into the entry's dependency graph, which emits a `modulepreload` for it on every page — and
because the same chunks are excluded from the service-worker precache, offline the preload
never resolves and the app renders blank on every route. Only `react` is named, deliberately.

**The workbox `globIgnores` patterns must be re-checked against `ls dist/assets` whenever
chunking changes.** A glob that matches nothing fails silently; that is how 700 kB of office
code previously re-entered the worker app's install.

**`/app/timetable` is a redirect, not a page.** It was a second, worse copy of Timesheets —
same endpoint, same totals, same table, same dialog, minus the CSV export and minus the role
check on its "log time" button — named for a calendar it never was. The week view it implied is
Scheduling's `timeGridWeek`. The route stays as a `<Navigate>` so old links still land
somewhere; do not rebuild the page.

**Two registries describe the app's pages, and they answer different questions.**
`src/lib/app-routes.ts` is the page list: label, path, icon, search keywords. The ⌘K palette is
built from it and `titleFor()` names the browser tab from it, so a new page needs an entry there
or it is unsearchable and its tab reads "RitaGreen OS". `src/components/app-sidebar.tsx` keeps
its own tree because it groups differently and shortens labels to fit. Do not add a third.

**`src/lib/access.ts` maps route → permitted roles** and is read by both `RequireAuth` and the
sidebar. Add a route there when you add a route. It is a UX guard only — the server is the
enforced boundary. It is the one part of `src/` with unit tests
(`src/lib/__tests__/access.test.ts`); keep them passing.
