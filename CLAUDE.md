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

React 19 SPA (static `dist/`) → 47 flat PHP endpoint files in `server/` → MySQL on cPanel.
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

**The schema is not migrations.** `server/schema.sql` is one re-runnable file organised in
"build passes", where later `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` blocks patch earlier
`CREATE TABLE` statements *in the same file*. To know a table's real shape you must read its
`CREATE` **and** every later `ALTER` against it. New work goes in numbered migrations.

**A person can exist three times.** `server/ats.php` copies identity fields rather than
linking. Never add another path that does this. See `docs/BLUEPRINT.md`, "The identity defect".

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
npm test             # vitest
npm run test:php     # php server/tests/run.php — no database needed
npm run build
```

CI runs typecheck, lint with zero warnings, build, a source-map assertion, `php -l` across
every PHP file, and `python3 scripts/docs_sync.py --check`.

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

46 of 108 tracked items done, 12 partial — see [`docs/PROGRESS.md`](docs/PROGRESS.md),
generated from `docs/STATUS.json`.

Substantially built: recruitment pipeline, credential expiry engine, certificates with public
verification, regulatory report packs, scheduling with a compliance guard, field operations,
timesheets and pay, the commercial pipeline with Stripe and GoCardless, authentication with
two-factor and session revocation, GDPR export and erasure.

The four things that matter most and are not built: one person record, per-service eligibility,
a compliance engine that calculates a verdict, and right to work as evidence rather than a
boolean. That is `docs/ROADMAP.md` milestones 1 to 4, in that order, and the order is not
negotiable.
