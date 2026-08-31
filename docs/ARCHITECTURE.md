# Architecture

**Status:** describes the system as built on 2026-08-31, plus the target identity model.

---

## 1. Shape

```
Browser — React 19 SPA (Vite build, static files)
   │  fetch() with  Authorization: Bearer <token>
   ▼
server/*.php — 47 flat endpoint files, no framework, no router, no Composer
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

**`server/lib.php` is the kernel.** Every endpoint depends on it. It provides:

| Area | Functions |
|---|---|
| Bootstrapping | Loads `config.php`, asserts required constants, sets CORS from `ALLOWED_ORIGINS`, installs global error and exception handlers |
| Response | `json_out()`, `fail()` |
| Request | `body()`, `method()`, `bearer_token()`, `client_ip()` |
| Auth | `current_user()`, `require_auth()`, `require_role()` |
| Governance | `audit()`, `rate_limit()` |
| Sequences | `next_seq()` — atomic named counters for document numbers |
| Mail | `mail_enabled()`, `smtp_configured()`, `send_mail()`, `smtp_send()`, `encode_mime_word()` |
| SMS and WhatsApp | `sms_enabled()`, `normalise_phone()`, `send_sms()`, `send_whatsapp()`, `message_worker()`, `sms_log()` |
| 2FA | `totp_secret()`, `base32_decode()`, `totp_code()`, `totp_verify()` |
| Notifications | `notify()` |

`smtp_send()` is a minimal SMTP client written directly over `fsockopen` — no PHPMailer, no
vendor directory. `totp_verify()` is a hand-rolled RFC 6238 implementation. Both exist because
the host cannot install packages.

**Two cron-only scripts**, refused over HTTP unless `?key=` matches `ALERTS_KEY`:

- `server/expiry-alerts.php` — the credential renewal engine
- `server/backup.php` — gzipped mysqldump above the document root, pruned to 14 days

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

**Known defect:** `last_seen_at` is written when the token is issued and never updated
(`server/auth.php:42` is the only write), so the session list shows each session's creation
time in a column labelled as last activity.

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

**The server is the only enforced boundary.** `RequireAuth` accepts a `roles` prop that the
router never passes, so all three authenticated surfaces are gated identically on "logged in".
Several read endpoints call `require_auth()` with no `require_role()`. Milestone 5 addresses
both.

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

## 10. Development environment — current state and target

**Current, and unacceptable:** `vite.config.ts` proxies every `*.php` request to
`https://ritagreenservices.com`. There is no local PHP or MySQL. Development reads and writes
the live production database.

**Target, before any schema work:**

- `server/config.local.php.example` with localhost credentials and mail, SMS and payments
  disabled
- `php -S localhost:8122 -t server/` as the local API
- The Vite proxy target read from an environment variable, defaulting to local
- A seed script producing realistic fixture data

See `docs/RULES.md`.
