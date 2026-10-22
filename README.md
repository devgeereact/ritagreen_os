# RitaGreen OS

The operations platform and public website of RitaGreen Services: one company, four services
(Healthcare, Cleaning, Security and Training; internal ids `care`, `cleaning`, `security`,
`academy`), one person record per human.

A React single-page application served as static files from cPanel, talking to a set of flat
PHP endpoints over the same domain, backed by MariaDB.

**Start here:** [`docs/`](docs/) is the documentation pack.
[`docs/PRD.md`](docs/PRD.md) for why, [`docs/BLUEPRINT.md`](docs/BLUEPRINT.md) for what,
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for how,
[`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md) before you write UI,
[`docs/RULES.md`](docs/RULES.md) before you change anything,
[`docs/ROADMAP.md`](docs/ROADMAP.md) for what remains. Agents start at
[`AGENTS.md`](AGENTS.md) (all agents) or [`CLAUDE.md`](CLAUDE.md) (Claude Code).

---

## What it does

| Surface | Path | For |
|---|---|---|
| Public site | `/` | Services, requests for staff and care, careers with Google Forms, news, certificate verification |
| Office app | `/app/*` | Owner and office staff: Today, Recruitment, People, Compliance, Rota, Website, Reports, Settings (and Finance while switched on) |
| Staff portal | `/m` | Workers: Home, Rota, Records, Me |
| Client portal | `/portal` | Client accounts, restricted until its future is decided |

The modules are described in [`docs/BLUEPRINT.md`](docs/BLUEPRINT.md), Part 2.

## Stack

| Layer | Technology |
|---|---|
| Frontend | React 19, Vite 7, TypeScript 5.9, React Router 7 |
| UI | shadcn/ui over Radix, Tailwind 3 |
| API | PHP 8.4, flat file-per-resource, `mysqli` prepared statements, no framework, no Composer |
| Database | MariaDB on cPanel |
| Auth | Opaque bearer tokens, bcrypt cost 12, TOTP two-factor, Google SSO |
| Deploy | GitHub Actions, FTPS, `dist/` only |

## Running it

Two processes: the Vite dev server, and a local PHP API for it to talk to.

```bash
npm install

# One-time: a local database and config
#   (needs MariaDB installed, or Docker: docs/TESTING.md, "Docker toolbox")
mysql -u root -e "CREATE DATABASE ritagreen_os_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root ritagreen_os_dev < server/schema.sql
cp server/config.local.php.example server/config.php   # edit if your MySQL user differs
php server/migrate.php --go            # applies migrations up to 004, which waits for the backfill
npm run seed -- --confirm
php server/backfill-people.php --go    # links anything not already linked; safe to re-run
php server/migrate.php --go            # 004 now passes its precondition

# Every day: two terminals
npm run dev:api    # PHP API on 127.0.0.1:8122
npm run dev        # http://localhost:5122  (strictPort — fails loudly if taken)
```

Seeded logins, all sharing the fixture password printed by `npm run seed`:
`admin@local.invalid` for the office, `amara.login@local.invalid` for the worker app at `/m`,
`priya@local.invalid` for the client portal at `/portal`.

**The dev proxy points at the local API by default.** It used to default to the live domain,
so every developer read and wrote the production workforce database. Reaching production is
now deliberate and prints a warning:

```bash
npm run dev:prod-api      # or RG_API_TARGET=https://ritagreenservices.com npm run dev
```

Other scripts: `npm run typecheck`, `npm run lint`, `npm run build`, `npm run preview`.

### Tests

```bash
npm test           # frontend unit tests (Vitest)
npm run test:php   # server pure logic and the endpoint guard check; no database
npm run test:db    # real HTTP against a disposable local database
```

No PHPUnit and no Composer, because the production host cannot install packages. What each
suite covers is in [`docs/TESTING.md`](docs/TESTING.md).

## Repository layout

```
src/
  routes/index.tsx      every route, in one file
  pages/                public/ · app/ · auth/ · portal/ · worker/
  components/ui/        vendored shadcn components
  lib/                  api client, access map, nav, services, types, pdf, csv
  providers/            auth context
server/
  lib.php               the shared kernel every endpoint requires
  *.php                 endpoints, one per resource (docs/API.md lists them)
  schema.sql            the from-scratch baseline
  migrations/           numbered changes, applied by migrate.php
docs/                   the documentation pack; docs/runbooks/ for release, backup and form intake
scripts/docs_sync.py    keeps PROGRESS.md in step with STATUS.json
scripts/agent_guard.py  Claude Code hook that blocks config.php edits, PROGRESS.md hand edits and pushes to main
```

## Server setup

The PHP tier is **not** part of the automated deploy. It is version-controlled here and
uploaded to the document root by hand, so a frontend deploy can never disturb it.

First-time setup, cron jobs and the files that must not be served are in
[`server/README.md`](server/README.md). Releasing the 2026 build is
[`docs/runbooks/release-2026.md`](docs/runbooks/release-2026.md).

## Deploying

Merging into `main` deploys. CI runs on `main`, and `deploy.yml` builds and uploads `dist/`
over FTPS only when that CI run succeeds. PHP files, `config.php` and `.htaccess` are excluded
from the upload by design and are placed by hand.

CI runs typecheck, `eslint --max-warnings 0`, the three test suites (the database one on
MariaDB 10.11), build, a build-output assertion
(no source maps ship), `php -l` across every PHP file, a guard against a committed `config.php`
and a documentation consistency check on every pull request. All of them block.

## Contributing

Read [`docs/RULES.md`](docs/RULES.md) first. The short version:

- Branch, pull request, merge. Never commit to `main` — pushing to `main` deploys.
- No secret in a tracked file, ever.
- Compliance status is calculated, never set by hand.
- Never hard-delete an employment record.
- Documentation states what the code does, not what the plan says.
