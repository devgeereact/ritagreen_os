# Architecture

**Status:** describes the system as built on 2026-08-31, plus the target identity model.

---

## 1. Shape

```
Browser — React 19 SPA (Vite build, static files)
   │  fetch() with  Authorization: Bearer <token>
   ▼
server/*.php — 55 flat endpoint files, no framework, no router, no Composer
   │  mysqli prepared statements
   ▼
MySQL / MariaDB on cPanel shared hosting (host = localhost)
```

The React application never touches the database. It calls PHP over HTTP and receives JSON.
PHP is the only tier holding credentials.

Both tiers are served from the same document root on the same domain, so in production every
API call is same-origin.

## 2. Frontend

| | |
|---|---|
| Framework | React 19.2, Vite 7.2, TypeScript 5.9 (strict, `noUnusedLocals`, `noUnusedParameters`) |
| Routing | `react-router-dom` 7.11, `createBrowserRouter`, single route table in `src/routes/index.tsx` |
| UI | shadcn/ui (`new-york` style) over Radix primitives, 37 vendored components in `src/components/ui/` |
| Styling | Tailwind 3.4 with CSS custom properties, dark mode by class |
| State | React `useState`, one context (`AuthProvider`), one fetch hook (`useApi`). No Redux, no react-query |
| Charts | ApexCharts | 
| Calendar | FullCalendar |
| Documents | jsPDF (`src/lib/pdf.ts`), SheetJS + file-saver (`src/lib/csv.ts`) |

**Rendering split.** The 17 public marketing pages are eager-imported so the first paint is
fast. All 34 CMS pages are `lazy()` behind a Suspense boundary, so a public visitor never
downloads the internal application bundle. `vite.config.ts` additionally splits React, charts,
calendar, editor and PDF into their own vendor chunks.

**Data fetching.** `src/hooks/use-api.ts` is a hand-rolled GET hook carrying a monotonic
request-id guard so a slow earlier response cannot overwrite a faster later one. There is no
cache, no mutation layer and no optimistic update.

**Four surfaces, one bundle:**

| Surface | Path | Audience |
|---|---|---|
| Public site | `/` and children | Anyone |
| Internal CMS | `/app/*` | Owner and staff |
| Client portal | `/portal` | Client accounts |
| Worker app | `/m` | Worker accounts |

`homeFor()` in `src/providers/auth-provider.tsx` routes a user to their surface after login:
a `client` role or a `client_id` goes to `/portal`, a `worker_id` goes to `/m`, everyone else
to `/app`.

## 3. Backend

**File-per-resource.** Every endpoint is a standalone `.php` file in `server/` that begins:

```php
require __DIR__ . '/lib.php';
```

Dispatch inside a file is by HTTP method plus a `?action=` query parameter. There is no
front controller, no autoloader and no dependency on Composer — a deliberate choice for stock
cPanel hosting with no shell access to install anything.

**`server/pure.php` holds the domain logic.** No database, no config, no output, no globals —
every function is a function of its arguments alone, and every date function takes an
injectable clock. `lib.php` requires it, so every endpoint has it, and `server/tests/run.php`
includes it directly with no infrastructure at all. It holds credential expiry classification
and reminder banding, the staff/worker/client distinction, and field status derivation. Three
of those were previously duplicated definitions inside separate endpoint files.

**`server/lib.php` is the kernel.** Every endpoint depends on it. It provides:

| Area | Functions |
|---|---|
| Bootstrapping | Loads `config.php`, asserts required constants, sets CORS from `ALLOWED_ORIGINS`, installs global error and exception handlers |
| Response | `json_out()`, `fail()` |
| Request | `body()`, `method()`, `bearer_token()`, `client_ip()` |
| Auth | `current_user()`, `require_auth()`, `require_role()`, `is_staff()`, `require_staff()`, `linked_worker_id()` |
| Governance | `audit()`, `rate_limit()` |
| Sequences | `next_seq()` — atomic named counters for document numbers |
| Mail | `mail_enabled()`, `smtp_configured()`, `send_mail()`, `smtp_send()`, `encode_mime_word()` |
| SMS and WhatsApp | `sms_enabled()`, `normalise_phone()`, `send_sms()`, `send_whatsapp()`, `message_worker()`, `sms_log()` |
| 2FA | `totp_secret()`, `base32_decode()`, `totp_code()`, `totp_verify()` |
| Notifications | `notify()` |

Anything needing `$conn`, a constant from `config.php`, or the network belongs in `lib.php`.
Anything that is a function of its arguments belongs in `pure.php`, where it can be tested.

## 3a. Tests

Two suites, both dependency-free, both run in CI.

| Suite | Covers | Run |
|---|---|---|
| `server/tests/run.php` | Pure server logic: expiry classification and its boundary days, reminder banding, the staff/worker/client distinction, field status, pay premium selection | `npm run test:php` |
| `src/lib/__tests__/access.test.ts` | The client permission map: staff detection, prefix matching, and that a client or worker account cannot reach `/app` | `npm test` |

No PHPUnit and no Composer: the production host cannot install packages, and a suite that only
runs on one machine stops being run. `server/tests/run.php` is a few dozen lines of assertion
helper.

Endpoints and React components are not yet covered. The compliance engine (Milestone 2) must
be, because its output decides whether a person may work.

`smtp_send()` is a minimal SMTP client written directly over `fsockopen` — no PHPMailer, no
vendor directory. `totp_verify()` is a hand-rolled RFC 6238 implementation. Both exist because
the host cannot install packages.

**Three cron-only scripts**, refused over HTTP unless `?key=` matches `ALERTS_KEY`:

- `server/expiry-alerts.php` — the credential renewal engine
- `server/send-queued-mail.php` — drains `email_queue`; the only place SMTP is spoken
- `server/backup.php` — gzipped mysqldump above the document root, pruned to 14 days

**Three CLI-only tools**, which refuse to run over HTTP at all: `server/migrate.php`,
`server/seed.php`, `server/backfill-people.php`.

## 3b. Mail

Nothing in a web request opens an SMTP socket. `queue_mail()` writes to `email_queue`;
`send-queued-mail.php` drains it on a cron, claiming each row before attempting it so
overlapping runs cannot double-send, and recording every attempt in `email_log` — including
skips, because "did we tell them" is a question asked months later.

Queueing is **not** gated on `mail_enabled()`. The queue records what the system intended to
send; the drainer decides what can actually go out. Gating at the call site would mean a
disabled mailer leaves no trace that somebody should have been contacted.

Three sends stay synchronous, deliberately and with the reason in the code: the lone-worker
panic alert, password reset, and interactive mailbox replies.

## 4. Authentication

**Opaque bearer tokens. Not JWT, not PHP sessions.**

```
POST auth.php?action=login
  → rate_limit('login', 10, 300)
  → password_verify() against a bcrypt cost-12 hash
  → if user_2fa.enabled, require a TOTP code (returns {twofa_required:true} first)
  → issue_token(): 32 random bytes, hex encoded
       the SHA-256 hash is stored in `tokens` with a 30-day expiry,
       plus user_agent, ip and last_seen_at
  → the plaintext token is returned once and never stored
```

On every request `current_user()` hashes the presented bearer token and joins
`tokens` to `users`, requiring `expires_at > NOW()` and `deleted_at IS NULL`.

Storing only the hash means a database disclosure does not yield usable sessions. Recording
`user_agent` and `ip` is what powers the device list and per-session revoke in Settings.

`last_seen_at` is refreshed by `current_user()` on each authenticated request, throttled to at
most one write a minute per token so a burst of calls costs a single update. It was previously
written only at issue, which made the session list show creation time under a heading that
reads as last activity.

There is no refresh token and no rotation. The client holds the token in `localStorage` under
`rgos_token`, which is exposed to XSS by design — the trade being made is that a bearer header
avoids CSRF entirely.

`public/.htaccess` re-injects the `Authorization` header, which LiteSpeed on shared hosting
otherwise strips before PHP sees it.

**Additional controls:** mandatory 2FA enrolment for privileged roles behind the
`MANDATORY_2FA` config flag; an idle lock screen that re-verifies the password; Google SSO that
matches existing users by verified email only and never auto-creates an account;
self-registration that creates an *applicant*, not a user.

## 5. Authorisation — current state

Seven roles: `super_admin`, `admin`, `manager`, `trainer`, `editor`, `employee`, `client`.

They are declared in three places that must be kept in sync:

- `server/schema.sql` — the `roles` reference table and the `users.role` ENUM
- `src/providers/auth-provider.tsx` — the `Role` union type
- `server/*.php` — `require_role()` call sites

**The server is the only enforced boundary**, and always will be — the client layer is a
user-experience guard, not a security one.

`src/lib/access.ts` is the single source of truth for that guard: a path-prefix map of route to
permitted roles, plus `isStaff()`, `isClientAccount()` and `isWorkerAccount()`. Both
`RequireAuth` and the sidebar read it, so a link is never shown to someone who would be bounced
off the page behind it. Someone who does not belong on a surface is redirected to their own
home rather than to a dead end. The map mirrors the `require_role()` calls in `server/`.

Until this landed, `RequireAuth` accepted a `roles` prop that no route ever passed, so all
three authenticated surfaces were gated identically on "logged in".

**Every authenticated surface names itself.** The public site sets its title through
`useSeo()`; `/app`, `/portal` and `/m` set nothing at all, so each of them kept whichever
`<title>` the last public page had left in place — the browser tab, the history entry and a
screen reader's page announcement all said the marketing home page's title. `useDocumentTitle()`
(`src/hooks/use-document-title.ts`) now sets it: `AppLayout` derives the name from
`titleFor()` in `src/lib/app-routes.ts`, and the portal and worker app pass their own.

**A guard belongs above the read, not just above the write.** Twenty endpoints role-gated their
POST branches and left every GET on `require_auth()` alone, so any logged-in account — a client
portal login and a worker's own login included — could list the whole client base, every
applicant, the full rota and the entire workforce's compliance state by calling the PHP file
directly. The guard now sits immediately after `require_auth()` in each of them, above every
branch, and `server/tests/guards.php` fails the suite if an endpoint returns anything between
`require_auth()` and its first authorisation call. Endpoints that genuinely serve any
authenticated account are named there with the reason, and each scopes its own query to the
caller.

**Role is not sufficient on its own.** A worker's login is an ordinary account with
`users.worker_id` set, and a client portal login has `users.client_id` set, so either may hold
a role such as `employee`. Anything gated on "internal staff" must therefore check the link,
not just the role — which is what `is_staff()` in `server/lib.php` does. It treats
`super_admin`, `admin` and `manager` as staff unconditionally, so a manager who also holds a
worker record does not lock themselves out of the office.

`users.client_id` and `users.worker_id` link a login to a client or worker record. They are
per-row account links, not a tenancy scope — there is no query-level row filtering anywhere.

## 6. Divisions

Four business units — `care`, `academy`, `cleaning`, `security` — hard-coded as a MySQL ENUM
on thirteen tables, and again in TypeScript. Three further columns carry a near-duplicate
of the same list with one extra member — `contact_enquiries.division` adds `other`,
`announcements.audience` adds `all`, `skills.category` adds `general` — so they drift
independently. Sixteen columns in all, seventeen counting the TypeScript union.

Divisions drive real behaviour: `dashboard.php` filters the whole command centre by one;
`analytics.php` and `margin.php` report by one; `scheduling.php` uses the division to decide
*which* compliance check is mandatory; `comms.php` targets an announcement at one.

`src/lib/divisions.ts` is the single source of truth for the public site — one array driving
the navigation mega-menu, the services pages, the home page and the footer.

**Known cost:** adding or renaming a division is a schema migration against every ENUM column
plus the TypeScript literals. `clients.divisions` sidesteps this with a comma-separated string,
because a client can buy from several — which is the same modelling problem solved a second,
worse way. The target `person_services` table is the correct shape and should eventually be
the pattern everywhere.

## 7. Target identity model

The change Milestone 1 makes:

```
people                          the human. one row, forever.
  id, full_name, preferred_name, dob, email, phone, address,
  emergency contact, created_at, updated_at, deleted_at

person_services                 which services, and eligibility per service
  person_id, service, eligibility_status, eligibility_reason, calculated_at

candidates    person_id →       an application at a stage
workers       person_id →       payroll_no, employment status
users         person_id →       a login with a role
enrolments    person_id →       training records
```

Every existing table keeps working. `workers` stays, but stops being the person — it becomes
the employment record. The de-duplicating migration matches existing rows by email, then by
phone, and produces a review list for ambiguous matches rather than guessing.

## 7a. Migrations

`server/schema.sql` is the from-scratch baseline for a new database. Everything after it goes
in `server/migrations/NNN_name.sql`, applied in order by `server/migrate.php` and recorded in a
`schema_migrations` table with a checksum.

The runner is deliberately awkward to misuse:

- **Dry run is the default.** Applying takes `--go`.
- **A non-local database additionally takes `--production` and a typed database name.** MySQL
  DDL is not transactional: a migration that fails halfway leaves the schema halfway, and there
  is no rollback to reach for.
- **An applied migration that has since been edited is reported as drift.** Re-running it would
  not reconcile the database, so the answer is a new migration.

The migrations use MariaDB's `ADD COLUMN IF NOT EXISTS` and `ADD KEY IF NOT EXISTS`, matching
the existing `schema.sql` and the host. They are not portable to stock MySQL 8.

**A migration may declare a precondition** — a query that must return 0 before it is safe to
apply — with `-- @precondition:` and `-- @precondition-message:` in its header. The runner
evaluates it and stops with the count and the remedy rather than failing mid-chain.

**Ordering for the person spine**, which the precondition mechanism exists to enforce:

```
schema.sql                       the baseline
  ↓
migrate.php --go                 applies 001–003, stops at 004
  ↓
backfill-people.php --go         creates people, links every record
  ↓
migrate.php --go                 004 now passes its precondition and applies
```

A foreign key does **not** force every row to be linked — it constrains non-NULL values only,
so unlinked rows pass straight through 002. This was assumed otherwise and corrected after
testing it: 002 applies cleanly with an unlinked row present. **Migration 004 is what requires
the link**, by making `person_id NOT NULL`, and it sets `STRICT_ALL_TABLES` itself so the
behaviour does not depend on the server's `sql_mode` — without strict mode MySQL would coerce
every unlinked `person_id` to 0 and leave the spine quietly wrong instead of loudly unfinished.

## 8. Configuration

All server configuration is PHP constants in `server/config.php`, hand-placed on the server
and never committed. `server/config.sample.php` is the tracked template, with 43 `define()`
constants covering database, OpenRouter, Stripe, GoCardless, Google OAuth, Twilio, OpenWeather,
SMTP, IMAP, CORS and security policy.

There is no `.env` and there are no `VITE_*` variables. **Nothing secret or configurable
reaches the client bundle** — the only `import.meta.env` uses are Vite's own `DEV` and `PROD`
flags. This is a good property and should be preserved.

`config.php` ends by opening the global `$conn` mysqli connection that every endpoint uses.

## 9. Build and deploy

```
push to main
  → GitHub Actions: npm ci → npm run build
  → SamKirkland/FTP-Deploy-Action, FTPS port 21
  → dist/ into the document root
```

The deploy uploads **only `dist/`**. PHP files, `config.php` and `.htaccess` are excluded and
placed on the server by hand, so a deploy can never disturb them. The workflow carries an
explicit warning never to add `dangerous-clean-slate`, which would delete them.

The host runs Apache/LiteSpeed with PHP 8.4 and **has no Node**, so the build must happen
locally or in CI and ship as static artifacts.

`public/.htaccess` handles SPA routing, passes the `Authorization` header through, and hard
blocks `^uploads/` so uploaded documents are only ever reachable through `files.php` with an
authentication check.

## 10. Development environment

Development runs entirely locally. It did not always: the Vite proxy defaulted to
`https://ritagreenservices.com`, so every developer read and wrote the live production
database, including while testing a migration.

**Two processes.**

| | |
|---|---|
| `npm run dev` | Vite on 5122, proxying `*.php` to the local API |
| `npm run dev:api` | `php -S 127.0.0.1:8122 -t server` — the PHP tier, served exactly as it is on the host |

`php -S` executes each `.php` file directly, so no router or rewrite is needed; the request
path maps to the file, which is how the production document root works too.

**Configuration.** `server/config.local.php.example` is the tracked template. Copy it to
`server/config.php`, which is gitignored. It points at a local database and **disables every
outbound integration** — mail, SMS, WhatsApp, Stripe, GoCardless, Google SSO, OpenRouter,
weather. A local run must not be able to email a real worker or charge a real card.

**Fixtures.** `server/seed.php` populates a local database with staff logins for each role, a
worker login, a client portal login, four clients with sites, ten workers whose compliance is
deliberately mixed — valid, inside the 30-day window, expired, and one with no right to work —
plus certifications, applicants across the pipeline, assigned and open shifts, programmes,
enrolments, enquiries and an incident. That spread is what makes the compliance and scheduling
paths testable without editing rows by hand.

It refuses to run unless invoked from the CLI, against a `DB_HOST` of `localhost`, `127.0.0.1`
or `::1`, with an explicit `--confirm`, and it will not silently double up on a populated
database without `--reset`.

**Reaching production is deliberate.** The proxy target comes from `RG_API_TARGET` and
defaults to the local API. Overriding it prints a warning at startup:

```bash
npm run dev:prod-api
```

**Prerequisite the repository cannot supply:** a local MySQL or MariaDB server. Install one
(`brew install mariadb`, or a container) before the steps above. The rest — PHP, the schema,
the config template, the fixtures — is in the repository.

See `docs/RULES.md` rule 13.
