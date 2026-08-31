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

```bash
npm install
npm run dev        # http://localhost:5122  (strictPort — fails loudly if taken)
```

Other scripts: `npm run typecheck`, `npm run lint`, `npm run build`, `npm run preview`.

> **Known problem, being fixed.** `vite.config.ts` currently proxies every `*.php` request to
> the live domain, so **development reads and writes the production database**. There is no
> local PHP or MySQL yet. Adding one is the prerequisite for all schema work — see
> [`docs/ROADMAP.md`](docs/ROADMAP.md). Treat local development as production access until it
> lands.

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
  *.php                 47 endpoints, one per resource
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

CI runs typecheck, lint, build, `php -l` across every PHP file and a documentation consistency
check on every pull request.

## Contributing

Read [`docs/RULES.md`](docs/RULES.md) first. The short version:

- Branch, pull request, merge. Never commit to `main` — pushing to `main` deploys.
- No secret in a tracked file, ever.
- Compliance status is calculated, never set by hand.
- Never hard-delete an employment record.
- Documentation states what the code does, not what the plan says.
