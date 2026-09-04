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
never introduce a new path that writes identity from anywhere else. See `docs/BLUEPRINT.md`,
"The identity defect".

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

**Lint does not gate yet.** It is `continue-on-error: true` with ~19 remaining errors, nearly
all React 19's new hook ruleset on working code. Do not add to the pile. Flip
`continue-on-error` to `false` and add `--max-warnings 0` when it reaches zero — that is
tracked as "ESLint baseline clean" in `docs/STATUS.json`.

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

151 done, 14 partial, 3 to do of 168 tracked items — see [`docs/PROGRESS.md`](docs/PROGRESS.md),
generated from `docs/STATUS.json`. Never edit `PROGRESS.md`; edit `STATUS.json` and regenerate.

Milestones 1 to 4 — the person spine, the compliance engine, right-to-work and credential
verification, and the deployment gate — are **built and verified against a local database, and
not yet applied to production**. Migrations `001`–`019` exist in `server/migrations/`; applying
them to the live database is the outstanding step and is a deliberate, separate act. Read
`docs/ROADMAP.md` for what each milestone still owes before it is called complete.

What is left is concentrated in PLATFORM (the lint baseline, tooling), EMPLOYMENT and
GOVERNANCE. Check `docs/PROGRESS.md` rather than assuming from this paragraph.

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

**`src/lib/access.ts` maps route → permitted roles** and is read by both `RequireAuth` and the
sidebar. Add a route there when you add a route. It is a UX guard only — the server is the
enforced boundary. It is the one part of `src/` with unit tests
(`src/lib/__tests__/access.test.ts`); keep them passing.
