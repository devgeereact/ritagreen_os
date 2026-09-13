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

**Anything that turns hours into money reads `deleted_at`.** `pay_compute()` did not, so a
soft-deleted timesheet was still paid. Archiving is the disposal route this system has; a query
that ignores it converts an archive into a silent liability.

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
`php -l` across every PHP file, `php server/tests/run.php`, a check that `server/config.php` is
not tracked, and `python3 scripts/docs_sync.py --check`.

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

160 done, 12 partial, 2 to do of 174 tracked items — see [`docs/PROGRESS.md`](docs/PROGRESS.md),
generated from `docs/STATUS.json`. Never edit `PROGRESS.md`; edit `STATUS.json` and regenerate.

Milestones 1 to 4 — the person spine, the compliance engine, right-to-work and credential
verification, and the deployment gate — are **built and verified against a local database**.
Migrations `001`–`021` exist in `server/migrations/` and all twenty-one are applied locally. The
deployment record for 2026-09-03 in `docs/CHANGELOG.md` says production was at 18 applied with 0
pending and that 019 was applied that day, so **`020` and `021` are outstanding there** — but
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
