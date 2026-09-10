# RitaGreen OS

The operations platform behind RitaGreen Services — one company, four divisions
(Care, Academy, Cleaning, Security), one workforce database.

A React single-page application served as static files from cPanel, talking to a set of flat
PHP endpoints over the same domain, backed by MySQL.

**Start here:** [`docs/`](docs/) is the documentation pack.
[`docs/PRD.md`](docs/PRD.md) for why, [`docs/BLUEPRINT.md`](docs/BLUEPRINT.md) for what,
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for how,
[`docs/RULES.md`](docs/RULES.md) before you change anything.

---

## What it does

| Surface | Path | For |
|---|---|---|
| Public site | `/` | Marketing, services, careers, certificate verification |
| Internal CMS | `/app/*` | Owner and staff — 31 pages |
| Client portal | `/portal` | Client accounts — cover requests, invoices, assigned workers |
| Worker app | `/m` | Workers — own compliance, shifts, clock in and out |

Covering recruitment, workers, compliance and credential expiry, training records and
certificates, scheduling and field operations, timesheets and pay, clients and sites, quotes
through to invoices, incidents, communications and reporting.

## Stack

| Layer | Technology |
|---|---|
| Frontend | React 19, Vite 7, TypeScript 5.9, React Router 7 |
| UI | shadcn/ui over Radix, Tailwind 3, ApexCharts, FullCalendar |
| API | PHP 8.4, flat file-per-resource, `mysqli` prepared statements, no framework, no Composer |
| Database | MySQL / MariaDB on cPanel |
| Auth | Opaque bearer tokens, bcrypt cost 12, TOTP two-factor, Google SSO |
| AI | OpenRouter, proxied server-side so the key never reaches the browser |
| Deploy | GitHub Actions, FTPS, `dist/` only |

## Running it

Two processes: the Vite dev server, and a local PHP API for it to talk to.

```bash
npm install

# One-time: a local database and config
#   (needs MySQL or MariaDB installed — brew install mariadb, or Docker)
mysql -u root -e "CREATE DATABASE ritagreen_os_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root ritagreen_os_dev < server/schema.sql
cp server/config.local.php.example server/config.php   # edit if your MySQL user differs
php server/migrate.php --go            # applies 001-003, stops at 004
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
npm test           # frontend: the permission map
npm run test:php   # server: expiry banding, access rules, field status, pay premiums
```

Both are dependency-free — no PHPUnit, no Composer — because the production host cannot
install packages. `npm run test:php` needs no database and no `config.php`.

## Repository layout

```
src/
  routes/index.tsx      every route, in one file
  pages/                public/ · app/ · auth/ · portal/ · worker/
  components/ui/        37 vendored shadcn components
  lib/                  api client, divisions, types, pdf, csv
  providers/            auth context
server/
  lib.php               the shared kernel every endpoint requires
  *.php                 54 endpoints, one per resource
  schema.sql            the database, as a single re-runnable file
docs/                   the documentation pack
scripts/docs_sync.py    keeps PROGRESS.md in step with STATUS.json
```

## Server setup

The PHP tier is **not** part of the automated deploy. It is version-controlled here and
uploaded to the document root by hand, so a frontend deploy can never disturb it.

1. Create the MySQL database and user in cPanel.
2. Import `server/schema.sql` through phpMyAdmin.
3. Copy `server/config.sample.php` to `config.php` **on the server** and fill it in.
   `config.php` is gitignored and must never be committed — it holds the database password and
   every API key.
4. Upload the `*.php` files to the document root, alongside `index.html`.

Two scripts are cron-driven and refuse HTTP access without a key:
`server/expiry-alerts.php` (the credential renewal engine) and `server/backup.php`.

## Deploying

Push to `main`. GitHub Actions runs `npm ci`, `npm run build` and uploads `dist/` over FTPS.
PHP files, `config.php` and `.htaccess` are excluded from the upload by design.

CI runs typecheck, `eslint --max-warnings 0`, both test suites, build, a build-output assertion
(no source maps ship), `php -l` across every PHP file, a guard against a committed `config.php`
and a documentation consistency check on every pull request. All of them block.

## Contributing

Read [`docs/RULES.md`](docs/RULES.md) first. The short version:

- Branch, pull request, merge. Never commit to `main` — pushing to `main` deploys.
- No secret in a tracked file, ever.
- Compliance status is calculated, never set by hand.
- Never hard-delete an employment record.
- Documentation states what the code does, not what the plan says.
