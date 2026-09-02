# RitaGreenOS — API reference

Complete reference for the PHP API in `server/`. Every claim here was checked
against the source; where a file's own header comment disagrees with its code,
the code is documented and the discrepancy is flagged.

- **55 HTTP endpoint files.** Verified against the tree at `server/`.
- Alongside them sit four libraries — `lib.php` (the kernel), `pure.php` (domain logic with no
  database or config), `pay-lib.php` and `compliance-lib.php` — plus `config.sample.php`.
- Three command-line tools are **not** reachable over HTTP at all: `migrate.php`, `seed.php`
  and `backfill-people.php`. Each refuses to run outside the CLI. See `docs/ARCHITECTURE.md`
  §7a and §10.

---

## 1. Architecture

There is no framework, no router, no autoloader and no Composer. The API is a
flat set of PHP files, one per resource, served directly by Apache/LiteSpeed
from the document root alongside the React build. A request to
`https://ritagreenservices.com/clients.php?action=update&id=4` is served by the
file `clients.php`; there is no rewrite layer in front of it.

Every endpoint begins with the same line:

```php
require __DIR__ . '/lib.php';
```

`lib.php` loads `config.php` (server-only, gitignored), opens the mysqli
connection `$conn`, installs the error handlers, emits the CORS headers, and
defines the shared helpers. Two endpoints (`payroll.php`, `margin.php`) also
`require __DIR__ . '/pay-lib.php'`; `me.php` requires it lazily inside its
payslip branch (`me.php:24`).

### Dispatch

Each file reads `$_GET['action']` and `$_SERVER['REQUEST_METHOD']` and runs
through a sequence of `if` blocks, each terminating in `json_out(...)` (which
calls `exit`). Execution falling off the end of the file hits a final
`fail('Method not allowed.', 405)` or `fail('Unknown action.', 404)`.

Two consequences follow from this, and both are visible in the tables below:

- **Order matters.** A bare `if (method() === 'GET')` block placed before the
  action-specific handlers swallows every GET regardless of `?action=`. This is
  why, for example, `GET /enquiries.php?action=handled` returns the enquiry
  list rather than an error (`enquiries.php:11`), and why any GET to
  `skills.php` returns the catalogue (`skills.php:12`).
- **A missing branch is a 404/405, not a routing error.** There is no route
  table to consult; the file itself is the route table.

### Request and response format

Request bodies are JSON, parsed by `body()` (`lib.php:68`), except
`files.php` upload, which is `multipart/form-data` and read from `$_FILES` /
`$_POST`. Responses are JSON with `Content-Type: application/json`, set
unconditionally in `lib.php:47`. Three endpoints deliberately override or
bypass that:

- `files.php?action=download` re-sends `Content-Type` and streams the file
  (`files.php:89`).
- `payments.php?action=public_checkout` sends a `302` redirect to Stripe
  (`payments.php:139`).
- `backup.php` and `expiry-alerts.php` `echo` plain text for cron logs while
  the JSON content-type header is still set.

`weather.php` also echoes a pre-encoded JSON string rather than calling
`json_out`, so it can serve from its file cache (`weather.php:34`, `102`).

### Money and dates

All monetary values are integer pence, in fields suffixed `_pence`. VAT is
computed at the UK standard 20% rate at creation time
(`invoices.php:91`, `quotes.php:98`). Dates are `YYYY-MM-DD`; date-times are
MySQL `Y-m-d H:i:s`.

---

## 2. Authentication model

**Opaque bearer tokens, not JWTs and not PHP sessions.** No endpoint calls
`session_start()`; nothing is signed or self-describing.

On successful login, `issue_token()` (`auth.php:35`) generates 32 random bytes
(`bin2hex(random_bytes(32))` — a 64-character hex string), stores only its
SHA-256 hash in the `tokens` table alongside the user id, user agent and client
IP, and sets `expires_at` to `NOW() + INTERVAL 30 DAY`. The plaintext token is
returned to the client once and never stored server-side.

Clients send it as:

```
Authorization: Bearer <token>
```

`bearer_token()` (`lib.php:82`) reads it from `getallheaders()` or
`$_SERVER['HTTP_AUTHORIZATION']` via a case-insensitive regex.
`current_user()` (`lib.php:92`) hashes the presented token and joins
`tokens` to `users`, requiring `expires_at > NOW()` and `users.deleted_at IS
NULL`. It returns the row `{id, name, email, role, division, client_id,
worker_id}` or `null`.

- `require_auth($conn)` (`lib.php:110`) returns that row or fails with
  **401 `Not authenticated.`**
- `require_role($user, ...$roles)` (`lib.php:118`) fails with **403 `You do not
  have access to this.`** unless `$user['role']` is in the list.

### Roles

Seven roles, defined in `users.php:11`:

`super_admin`, `admin`, `manager`, `trainer`, `editor`, `employee`, `client`.

Only `super_admin` may create another `super_admin` (`users.php:68`).
The `client_id` and `worker_id` columns on `users` link a login to a client
record (gating `portal.php`) or a worker record (gating `me.php`, and used by
`field.php` to stamp events).

### Session lifecycle

- Logout deletes the single token row (`auth.php:111`).
- A password reset deletes **every** token for that user (`auth.php:184`).
- Deactivating a user deletes every token for that user (`users.php:45`).
- `auth.php?action=sessions` lists live tokens; `revoke_session` deletes one by
  id, scoped to the calling user.

Note that `tokens.last_seen_at` is written once at issue time
(`auth.php:43`) and never updated afterwards, so the value shown in the session
list is the creation time for every session.

### Two-factor authentication

TOTP (RFC 6238, SHA-1, 6 digits, 30-second step, ±1 step drift tolerance),
implemented in pure PHP in `lib.php:456-508` so it runs on stock shared
hosting. Secrets live in `user_2fa`. When `MANDATORY_2FA` is set,
`super_admin`/`admin`/`manager` accounts are flagged `must_setup_2fa` in the
login response until they enrol (`auth.php:27`); the flag is advisory — the
API does not itself refuse those users' requests.

### Google SSO

`auth.php?action=google_callback` exchanges an OAuth code server-side and
matches on the verified email. It **never auto-creates** a user: an unmatched
email gets 403 (`auth.php:402`).

---

## 3. CORS

Set in `lib.php:38-53`, before any output.

`ALLOWED_ORIGINS` is an array constant in `config.php` (sample values in
`config.sample.php:119`: `http://localhost:5122`, `http://127.0.0.1:5122`,
`https://ritagreenservices.com`, `https://www.ritagreenservices.com`). The
request's `Origin` is matched against it with a strict `in_array(..., true)` —
no wildcards, no prefix matching. On a match the API echoes that exact origin
in `Access-Control-Allow-Origin` and adds `Vary: Origin`. On no match, **no**
allow-origin header is emitted at all.

Unconditionally emitted regardless of origin:

```
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
Access-Control-Allow-Headers: Content-Type, Authorization
Access-Control-Allow-Credentials: true
Content-Type: application/json
```

An `OPTIONS` request short-circuits with **204** at `lib.php:50`, before any
authentication or action dispatch. Every endpoint therefore answers preflight
without a token.

`PUT` and `DELETE` are advertised in the allow-methods header but no endpoint
implements either; both fall through to the trailing 405.

---

## 4. Error shape

Errors are a single-key object produced by `fail()` (`lib.php:62`):

```json
{ "error": "Human-readable message." }
```

Status codes in use: `400` (validation), `401` (not authenticated / bad
credentials), `403` (wrong role, or a shared-secret key mismatch), `404`
(not found, unknown action), `405` (method not allowed), `409` (conflict —
already paid, already booked, duplicate email), `429` (rate limited), `498`
(one non-standard case: GoCardless bad webhook signature, `gocardless.php:55`),
`500`, `502` (upstream service failure), `503` (feature not configured).

Uncaught exceptions and PHP errors are converted to JSON by the handlers at
`lib.php:8-19`. Note that the exception handler interpolates the raw exception
message into the response (`'Server error: ' . $e->getMessage()`,
`lib.php:13`) irrespective of the `GK_DEBUG` constant, so internal detail can
reach the caller on an unexpected failure.

---

## 5. Rate limiting

`rate_limit($conn, $bucket, $max, $windowSec, $subject = null)` is a sliding
window keyed on `(bucket, subject)` backed by the `rate_limits` table. `$subject`
defaults to the caller's IP; pass one explicitly to throttle something else —
login also limits per account (hashed), and `ai.php` limits per user id. Roughly
1% of calls opportunistically delete rows older than a day.

The IP comes from `client_ip()`, which returns **`REMOTE_ADDR`**. A forwarded
header (`CF-Connecting-IP`, `X-Forwarded-For`) is read only when `REMOTE_ADDR` is
listed in the `TRUSTED_PROXIES` constant, which is empty by default.

> This used to prefer the forwarded headers unconditionally. Both are set by the
> caller, so rotating one per request landed in a fresh bucket every time and
> defeated every limit in this table — verified against a running server, where
> fourteen login attempts with a rotating `X-Forwarded-For` all returned 401 and
> none returned 429. `TRUSTED_PROXIES` empty is correct on this deployment:
> Cloudflare IP restoration already puts the visitor's real address in
> `REMOTE_ADDR` before PHP runs. Populate it only behind a proxy that does not.

Every call site:

| Bucket | Limit | Window | Where |
|---|---|---|---|
| `login` | 10 | 300 s | `auth.php:51` |
| `forgot` | 5 | 600 s | `auth.php:124` |
| `reset` | 10 | 600 s | `auth.php:158` |
| `unlock` | 10 | 300 s | `auth.php:292` (after `require_auth`) |
| `register` | 6 | 600 s | `auth.php:305` |
| `google` | 20 | 300 s | `auth.php:361` |
| `contact` | 5 | 600 s | `contact.php:9` |
| `booking` | 8 | 600 s | `bookings.php:13` |
| `ats` | 8 | 600 s | `ats.php:25` |
| `offer` | 20 | 600 s | `offer.php:40` (POST only) |

Nothing else is rate-limited. In particular the public `GET /offer.php`,
`GET /certificates.php`, `payments.php?action=public_checkout`, the blog and
testimonial reads, and both webhooks have no throttle.

---

## 6. Shared kernel (`lib.php`)

Required by every endpoint. 519 lines; the helpers below are the whole public
surface.

### Output and request

| Function | Line | Purpose |
|---|---|---|
| `json_out($data, $code = 200)` | 55 | Emit JSON and `exit`. |
| `fail($message, $code = 400)` | 62 | Emit `{"error": ...}` and `exit`. |
| `body(): array` | 68 | Decode the JSON request body; `[]` if absent or not an object. |
| `method(): string` | 76 | The HTTP verb. |

### Identity and access

| Function | Line | Purpose |
|---|---|---|
| `bearer_token(): ?string` | 82 | Extract the token from the `Authorization` header. |
| `current_user($conn): ?array` | 92 | Resolve the token to a live, non-deleted user row, or `null`. |
| `require_auth($conn): array` | 110 | As above, but 401 instead of `null`. |
| `require_role($user, ...$roles)` | 118 | 403 unless the user's role is listed. |
| `audit($conn, $userId, $action, $detail)` | 124 | Append a row to `audit_log`. |
| `client_ip(): string` | 133 | Best-effort caller IP, proxy-aware, truncated to 45 chars. |
| `rate_limit($conn, $bucket, $max, $windowSec)` | 147 | Sliding-window throttle; 429 on breach. |
| `next_seq($conn, $key): int` | 173 | Atomically allocate the next integer in a named counter (used for invoice, quote, credit-note, certificate and incident numbers). |

### Email

| Function | Line | Purpose |
|---|---|---|
| `mail_enabled(): bool` | 190 | True when `MAIL_ENABLED`, `MAIL_FROM` and `MAIL_TO` are all set. |
| `smtp_configured(): bool` | 198 | True when `SMTP_HOST`/`SMTP_USER`/`SMTP_PASS` are set. |
| `send_mail($to, $subject, $body, $replyTo = null, $extraHeaders = [])` | 209 | Send plain text via SMTP if configured, else PHP `mail()`. Strips CR/LF from the subject and extra headers to block header injection. |
| `smtp_send(...)` | 243 | Minimal socket SMTP client with `AUTH LOGIN`, optional STARTTLS, peer verification on by default, and dot-stuffing. No Composer dependency. |
| `encode_mime_word($text)` | 334 | RFC 2047 base64 encoding for non-ASCII header values. |

### SMS and WhatsApp (Twilio)

| Function | Line | Purpose |
|---|---|---|
| `sms_enabled(): bool` | 347 | True when the Twilio constants are set and not `CHANGE_ME`. |
| `normalise_phone($phone): ?string` | 355 | Normalise a UK number to E.164. |
| `send_sms($conn, $to, $body): bool` | 366 | Send one SMS; body truncated to 480 chars. |
| `sms_log($conn, $to, $body, $status, $meta)` | 398 | Record every attempt in `sms_log`, including skipped ones. |
| `whatsapp_enabled(): bool` | 407 | SMS enabled plus `TWILIO_WHATSAPP_FROM`. |
| `send_whatsapp($conn, $to, $body): bool` | 413 | Send one WhatsApp message; body truncated to 1500 chars. |
| `message_worker($conn, $to, $body): bool` | 448 | WhatsApp if available, otherwise SMS. |

### TOTP

| Function | Line | Purpose |
|---|---|---|
| `totp_secret($length = 32)` | 460 | Generate a base32 enrolment secret. |
| `base32_decode($b32)` | 469 | Base32 to raw bytes. |
| `totp_code($secret, $counter)` | 485 | The 6-digit code for a time counter. |
| `totp_verify($secret, $code)` | 499 | Constant-time compare against the current window ±1 step. |

### Notifications

| Function | Line | Purpose |
|---|---|---|
| `notify($conn, $userId, $title, $body, $type, $url)` | 512 | Insert an in-app notification. A `null` user id is a broadcast to all staff. |

### Pay engine (`pay-lib.php`)

Pure functions, no auth and no output; callers handle both. Shared by
`payroll.php`, `margin.php` and `me.php?action=payslip` so labour cost is
computed identically everywhere.

| Symbol | Line | Purpose |
|---|---|---|
| `PAY_OT_WEEKLY_THRESHOLD` (40.0) | 8 | Hours per ISO week before overtime. |
| `PAY_HOLIDAY_RATE` (0.1207) | 9 | Holiday accrual as a fraction of worked hours. |
| `pay_valid_date($d): bool` | 11 | `YYYY-MM-DD` shape check. |
| `pay_load_rules($conn)` | 17 | Multipliers from `pay_rules`, defaulting to night 1.30, weekend 1.25, bank holiday 2.00, overtime 1.50. |
| `pay_load_bank_holidays($conn, $from, $to)` | 27 | Set of bank-holiday dates in range. |
| `pay_day_premium($rules, $date, $startTime, $bankHols)` | 40 | Highest applicable day premium: bank holiday > weekend > night. |
| `pay_compute($conn, $from, $to, $onlyWorker = null)` | 56 | Per-entry pay over **approved** timesheets only; returns `workers`, `lines`, `rules`. |

---

## 7. Endpoint summary

| File | Purpose | Auth posture |
|---|---|---|
| `academy.php` | LMS: programmes, enrolments, certificate issue | Auth; reads any role, writes `super_admin`/`admin`/`manager` plus `trainer` (enrol/complete) or `editor` (create/publish) |
| `ai.php` | OpenRouter chat proxy | Auth, any role |
| `analytics.php` | Group BI aggregates | `super_admin`/`admin`/`manager` |
| `api.php` | Items smoke test | Auth, any role |
| `ats.php` | Recruitment and onboarding | **POST with no action is public**; everything else auth, writes `super_admin`/`admin`/`manager` (+`trainer` for `from_learner`) |
| `audit.php` | Audit-log viewer | `super_admin`/`admin` |
| `auth.php` | Login, 2FA, sessions, reset, registration, Google SSO | **Mixed** — see §8 |
| `backup.php` | Cron mysqldump | **CLI, or HTTP with `?key=ALERTS_KEY`** |
| `blog.php` | Blog posts | **Public reads**; admin `super_admin`/`admin`/`manager`/`editor` |
| `bookings.php` | Cover requests | **POST with no action is public**; rest auth, writes `super_admin`/`admin`/`manager` |
| `certificates.php` | Certificate verification and Open Badges | **Public** |
| `clients.php` | CRM | Auth; reads any role, writes `super_admin`/`admin`/`manager` |
| `comms.php` | Announcements | Auth; POST `super_admin`/`admin`/`manager` |
| `compliance.php` | Credential register and audit packs | Auth; POST `super_admin`/`admin`/`manager` |
| `contact.php` | Public enquiry form | **Public** |
| `contracts.php` | Recurring contracts | Auth; writes `super_admin`/`admin`/`manager` |
| `credit-notes.php` | Credit notes against invoices | Auth; POST `super_admin`/`admin`/`manager` |
| `dashboard.php` | Command-centre summary | Auth, any role |
| `enquiries.php` | Enquiry inbox | `super_admin`/`admin`/`manager` |
| `expiry-alerts.php` | Cron compliance reminder digest | **CLI, or HTTP with `?key=ALERTS_KEY`** |
| `field.php` | Field operations, clock-in, panic | Staff for the board; a worker only for their own shifts |
| `files.php` | Document centre | `super_admin`/`admin`/`manager`/`editor` for the whole file; delete narrows to `super_admin`/`admin`/`manager` |
| `gdpr.php` | Data-subject export and erasure | `super_admin`/`admin` |
| `gocardless.php` | Direct Debit | **Webhook public (HMAC-verified)**; rest auth, writes `super_admin`/`admin`/`manager` |
| `inbox.php` | IMAP mailbox | `super_admin`/`admin`/`manager` |
| `incidents.php` | Incidents and safeguarding | Auth; create allows `super_admin`/`admin`/`manager`/`trainer`/`employee`, other writes `super_admin`/`admin`/`manager` |
| `insurance.php` | Company insurance register | Auth; writes `super_admin`/`admin`/`manager` |
| `invoices.php` | Invoicing | Auth; writes `super_admin`/`admin`/`manager` |
| `jobs.php` | Work orders | Auth; status allows `employee`, create `super_admin`/`admin`/`manager` |
| `margin.php` | Pay-vs-charge reconciliation | `super_admin`/`admin`/`manager` |
| `me.php` | Worker self-service (mobile PWA) | Auth **and** a linked `worker_id` |
| `notifications.php` | Notification bell | Auth, any role |
| `offer.php` | Shift offer accept/decline from an SMS link | **Public, token in the URL is the secret** |
| `payments.php` | Stripe Checkout | **Webhook and public pay link are public**; rest `super_admin`/`admin`/`manager` |
| `payroll.php` | Pay run and payslips | `super_admin`/`admin`/`manager` |
| `portal.php` | Client self-service portal | Auth **and** a linked `client_id` |
| `quotes.php` | Quoting | Auth; writes `super_admin`/`admin`/`manager` |
| `references.php` | Reference checks | Auth; writes `super_admin`/`admin`/`manager` |
| `reports.php` | Regulatory export packs | `super_admin`/`admin`/`manager` |
| `scheduling.php` | Shifts, matching, dispatch | Auth; writes `super_admin`/`admin`/`manager` |
| `shift-templates.php` | Recurring shift templates | Auth; writes `super_admin`/`admin`/`manager` |
| `skills.php` | Skills catalogue | Auth; writes `super_admin`/`admin`/`manager` |
| `testimonials.php` | Testimonials | **Public read**; admin `super_admin`/`admin`/`manager`/`editor` |
| `timesheets.php` | Time tracking | Auth; create allows `employee`, approve `super_admin`/`admin`/`manager` |
| `users.php` | User and role management | `super_admin`/`admin` |
| `weather.php` | OpenWeather proxy with shift advisory | Auth, any role |
| `workers.php` | Workforce records | Staff to read (pay fields office-only); writes `super_admin`/`admin`/`manager` |

Throughout the tables below, "office roles" means
`super_admin`, `admin`, `manager`.

---

## 8. `auth.php`

Authentication, second factor, session management, self-registration and Google
SSO. 16 routes. Falls through to `Unknown action.` (404) at `auth.php:419`.

| Method | Action | Auth | Body / query | Returns |
|---|---|---|---|---|
| POST | `login` | **Public**, rate-limited | `{email, password, code?}` | `{token, user}`, or `{twofa_required: true}` when the account has 2FA on and no `code` was supplied (`auth.php:78`) |
| GET | `me` | Auth | — | `{user}` including `must_setup_2fa` |
| POST | `logout` | **None enforced** | — | `{ok: true}`; deletes the token row if one was presented (`auth.php:111`) |
| POST | `forgot` | **Public**, rate-limited | `{email}` | `{ok: true}` always — never reveals whether the address exists (`auth.php:153`) |
| POST | `reset` | **Public**, rate-limited | `{token, password}` | `{ok: true}`; token must match `^[a-f0-9]{64}$`, password ≥ 8 chars; burns the reset row and revokes every session |
| GET | `2fa_status` | Auth | — | `{enabled}` |
| POST | `2fa_setup` | Auth | — | `{secret, otpauth}`; upserts a disabled secret |
| POST | `2fa_enable` | Auth | `{code}` | `{ok: true}` |
| POST | `2fa_disable` | Auth | `{code}` | `{ok: true}`; returns ok without a code if 2FA is already off |
| GET | `sessions` | Auth | — | `{sessions: [{id, user_agent, ip, created_at, last_seen_at, current}]}` |
| POST | `revoke_session` | Auth | `{id}` | `{ok: true}`; scoped to the caller's own tokens |
| POST | `unlock` | Auth, rate-limited | `{password}` | `{ok: true}`; re-verifies the password for a lock screen, issues no new token |
| POST | `register` | **Public**, rate-limited | `{name, email, phone?, division, role_applied?, consent}` | `{ok: true}` 201; creates an `applicants` row with source `self-registration`, a `consent_log` entry, a broadcast notification and an office email |
| GET | `google_config` | **Public** | — | `{enabled}` |
| GET | `google_start` | **Public** | — | `{url}` — the Google authorisation URL; 503 if SSO is unconfigured |
| POST | `google_callback` | **Public**, rate-limited | `{code, state}` | `{token, user}`, or `{twofa_required: true, ticket}` when the matched account has an authenticator enrolled. Requires `email_verified`, a valid single-use `state`, and an existing non-deleted user (403 if none) |
| POST | `google_2fa` | **Public**, rate-limited | `{ticket, code}` | `{token, user}`; completes a Google sign-in for a 2FA account. The ticket is issued by `google_callback` and lasts 5 minutes |

> `state` and `google_2fa` are both new. Without `state` the callback accepted any
> authorization code handed to it, which is login CSRF: an attacker obtains a code
> against their own Google account and causes a victim's browser to submit it,
> signing the victim into the attacker's account. And 2FA was skipped entirely on
> this path — an account with an authenticator enrolled could sign in through
> Google with no code at all. A ticket is needed rather than a second call with a
> code because Google has already spent the authorization code by then.

Password hashing is bcrypt: cost 12 on reset (`auth.php:176`), PHP's default
cost when a user is created (`users.php:101`).

**Header comment discrepancy.** The block at `auth.php:2-12` documents only
`login`, `me`, `logout`, `forgot`, `reset` and the four `2fa_*` actions. The
file also implements `sessions`, `revoke_session`, `unlock`, `register`,
`google_config`, `google_start` and `google_callback` — seven undocumented
routes, three of which are public.

---

## 9. `api.php`

Smoke test proving React → PHP → MySQL. Reached only from the dev-only
`/app/smoke-test` page.

| Method | Auth | Body | Returns |
|---|---|---|---|
| GET | Auth, any role | — | `{items: [{id, name, created_at}]}`, newest first, capped at 100 |
| POST | Auth, any role | `{name}` | `{item: {id, name}}` 201; name required, max 255 chars; throttled `smoke` 30/5 min |

Both verbs were fully public until this was closed. The POST was an
unauthenticated, unthrottled write into the production database.

---

## 10. `ai.php`

OpenRouter proxy. The API key stays server-side and never reaches the browser.

| Method | Auth | Body | Returns |
|---|---|---|---|
| POST | Auth, any role | `{prompt, system?}` | `{reply}` |

Returns 503 if `OR_API_KEY` is unset or `CHANGE_ME`, and 502 on any transport
or upstream error. The model is `OR_MODEL` from `config.php`; `system` defaults
to a RitaGreen-specific UK-English assistant prompt (`ai.php:18`). Any
authenticated user of any role — including `client` and `employee` — can spend
tokens through this endpoint.

---

## 11. `dashboard.php`

| Method | Auth | Query | Returns |
|---|---|---|---|
| GET | Auth, any role | `division` = `all`\|`care`\|`academy`\|`cleaning`\|`security` (invalid falls back to `all`) | Counts for programmes, enrolments, upcoming sessions, certificates and open shifts; outstanding invoice pence; revenue by division; up to 10 DBS/SIA expiries within 30 days |

---

## 12. `analytics.php`

| Method | Auth | Returns |
|---|---|---|
| GET | Office roles | Revenue by division, six-month monthly revenue trend, jobs by status, quote counts with win rate, workforce by division, compliance RAG counts, and group totals |

---

## 13. `clients.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — (with `?id=`) | Auth, any role | `id` | `{client, sites, rate_cards, financials}` where `financials` is quoted / invoiced / paid / outstanding pence |
| GET | — | Auth, any role | — | `{clients}` with site count, pipeline stage and account manager |
| POST | `update` | Office roles | `id`; body `{stage?, status?, account_manager_id?, sla_response_hours?}` | `{ok: true}`; stage ∈ lead/qualified/proposal/won/lost, status ∈ active/prospect/paused |
| POST | `rate_card` | Office roles | `id`; body `{division, role?, charge_pence}` | `{ok: true}` 201 |
| POST | — | Office roles | body `{name, type?, contact_name?, email?, phone?}` | `{client}` 201; type ∈ care_home/nhs/landlord/event/private, created as `prospect` |

**Header comment discrepancy.** `clients.php:2-5` documents only the two GETs
and the bare POST; `update` and `rate_card` appear only in inline comments
above their handlers.

---

## 14. `workers.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | **Staff only** (`require_staff`) | — | `{workers}` with compliance and availability fields for every non-deleted worker. `pay_rate_pence`, `pay_type` and `holiday_hours` are returned only to `super_admin`, `admin` and `manager`; they are stripped from the response for every other account |
| POST | `availability` | Office roles | `id`; body `{avail_days, avail_nights, avail_weekends, travel_radius_mi, home_postcode}` | `{ok: true}` |
| POST | `pay` | Office roles | `id`; body `{pay_rate_pence, pay_type}` | `{ok: true}`; pay type ∈ paye/umbrella |
| POST | — | Office roles | body `{name, email?, phone?, role?, division?, right_to_work?, pay_rate_pence?, pay_type?}` | `{worker}` 201; role ∈ carer/nurse/cleaner/guard/trainer, created as `onboarding` |

Until this was gated, any authenticated user — a `client`-role portal login
included — could read the full workforce list with pay rates and DBS/SIA detail
through the bare GET.

**Header comment discrepancy.** `workers.php:2-4` documents only GET and the
bare POST; `availability` and `pay` appear only in inline comments.

---

## 15. `skills.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | Auth, any role | `worker_id?` | `{skills}`, plus `{assigned: [skill_id]}` when `worker_id` is given |
| POST | `set` | Office roles | `worker_id`; body `{skill_ids: []}` | `{ok: true}`; replaces the worker's whole skill set |
| POST | — | Office roles | body `{name, category?}` | `{ok: true}` 201; category ∈ care/academy/cleaning/security/general, anything else coerced to `general` |

The GET block has no `action` guard, so `GET /skills.php?action=set` returns the
catalogue rather than an error.

---

## 16. `scheduling.php`

Shift creation, smart matching and dispatch. Compliance and clash guards live
in `worker_compliant()` (`scheduling.php:31`) and `has_clash()`
(`scheduling.php:51`); both consult `certifications` as well as the DBS/SIA
columns on the worker row, so a valid check recorded in Compliance counts at
scheduling time.

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | `match` | Auth, any role | `division`, `starts_at`, `ends_at`, `skill_id?` | `{workers, bucket}` — eligible, available, clash-free workers ordered by fewest upcoming shifts. `bucket` is `nights` (start ≥ 20:00 or < 06:00), `weekends` (Sat/Sun) or `days` |
| POST | `open` | Office roles | body `{title, division, client_id?, site_name?, starts_at, ends_at}` | `{id}` 201; creates an unassigned shift with status `open` |
| POST | `broadcast` | Office roles | `id` | `{offered: n}`; creates a 40-hex-character `shift_offers` token per eligible worker and messages each one a `/offer/<token>` link over WhatsApp or SMS. 409 unless the shift is `open` |
| GET | — | Auth, any role | `from`, `to` (default: current month) | `{shifts}` for the calendar |
| POST | — | Office roles | body `{worker_id, title, client_id?, site_name?, starts_at, ends_at}` | `{shift}` 201; 409 on missing right-to-work, a lapsed required check, or an overlapping scheduled shift |

---

## 17. `shift-templates.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | Auth, any role | — | `{templates}` |
| POST | `toggle` | Office roles | `id` | `{ok: true}`; flips `active` |
| POST | `generate` | Office roles | `weeks` (clamped to 1–12, default 4) | `{created: n}`; creates open shifts from every active template, skipping duplicates by division + title + start time |
| POST | — | Office roles | body `{title, division, client_id?, site_name?, weekday, start_time, end_time}` | `{ok: true}` 201; weekday is ISO 1–7, times `HH:MM` |

---

## 18. `field.php`

Field operations: the office live board and the worker's clock-in/panic trail.

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | `live` | **Staff only** (`require_staff`) | — | `{shifts}` for today with a derived `field_status` of `unfilled`, `completed`, `on_site`, `overrunning`, `no_show` (30 min past start, not clocked in), `late` or `scheduled`, plus a `panic` flag |
| GET | — (with `?shift_id=`) | Staff, **or** the worker assigned to that shift | `shift_id` | `{events}` — the shift timeline |
| POST | — | Staff for any shift; a worker **only for a shift assigned to them** | body `{shift_id, kind, note?, lat?, lng?, signer?}` | `{ok: true}` 201, else 403 |

`kind` ∈ `clock_in`, `clock_out`, `check_in`, `panic`, `note`, `task`,
`signoff`. A `clock_out` flips a `scheduled` shift to `completed`
(`field.php:103`). A `panic` raises a broadcast notification and, when mail is
enabled, emails `MAIL_TO` with a `[PANIC]` subject.

Scoping is by shift ownership rather than role, because a worker's login is an
ordinary account with `users.worker_id` set and may hold any role. A worker
account posting against a shift that is unassigned, or assigned to someone else,
is refused with 403. Until this was added, any authenticated account — a `client`
portal login included — could post events against **any** shift id, panic alerts
included. The worker stamped on the event is the caller's linked `worker_id` if
present, otherwise the shift's assigned worker.

---

## 19. `timesheets.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | Auth, any role | — | `{timesheets, totals: {pending_minutes, approved_minutes}}`, 300 most recent |
| POST | `approve` | Office roles | `id` | `{ok: true}` |
| POST | — | Office roles + `employee` | body `{worker_id, work_date, start_time, end_time}` | `{ok: true}` 201; date `YYYY-MM-DD`, times `HH:MM`, end after start, maximum 16 hours |

Minutes are computed server-side from the times; the client cannot supply them.

---

## 20. `payroll.php`

Both routes require office roles and a valid `from`/`to` pair with
`from <= to`. Only `approved` timesheets are included.

| Method | Action | Params | Returns |
|---|---|---|---|
| GET | `payslip` | `worker_id`, `from`, `to` | `{worker, period, lines, totals}` |
| GET | — | `from`, `to` | `{period, rows, totals, rules}` — the full pay run |

Both write an `audit_log` entry (`payroll.php:41`, `69`).

---

## 21. `margin.php`

| Method | Auth | Params | Returns |
|---|---|---|---|
| GET | Office roles | `from`, `to` | `{period, rows, totals}` per division with `revenue_pence`, `cost_pence`, `margin_pence`, `margin_pct` |

Revenue is invoice net raised in the period excluding `void` and `draft`, less
credit notes; credits are gross so the net portion is approximated by dividing
by 1.20 (`margin.php:40`). Cost is labour gross from `pay_compute`, so premiums
and overtime are included.

---

## 22. `invoices.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | Auth, any role | — | `{invoices, totals, aged}`; `aged.buckets` are current / 1–30 / 31–60 / 61–90 / 90+ days, `aged.items` is the chase list sorted worst-first |
| POST | `pay` | Office roles | `id` | `{ok: true}`; marks the invoice paid |
| POST | — | Office roles | body `{division, client_id?, net_pence}` | `{invoice}` 201 |

Payment terms are 30 days (`invoices.php:11`). Invoice numbers are
`INV-<year>-####` from the atomic per-year counter. The GET response strips
`pay_token` and exposes only a boolean `has_pay_link` (`invoices.php:38-39`),
so the public pay-link secret is never returned in the list.

**Stale header comment.** `invoices.php:2` describes the module as "manual
reconciliation — no card processor wired yet", but `payments.php` implements
Stripe Checkout and `gocardless.php` implements Direct Debit, both of which
mark invoices paid.

---

## 23. `credit-notes.php`

| Method | Auth | Params | Returns |
|---|---|---|---|
| GET | Auth, any role | `invoice_id` | `{credit_notes}` |
| POST | Office roles | body `{invoice_id, amount_pence, reason?}` | `{ok: true, number}` 201 |

The credit is capped at the invoice's remaining balance; crediting the full
remainder sets the invoice to `void` (`credit-notes.php:67`). Numbers are
`CN-<year>-####`.

---

## 24. `quotes.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | Auth, any role | — | `{quotes, totals: {open_pence, won_pence}}` |
| POST | `status` | Office roles | `id`; body `{status}` | `{ok: true}`; status ∈ draft/sent/declined, and the update refuses to touch a quote already `accepted` |
| POST | `accept` | Office roles | `id` | `{ok: true, invoice_number}` 201; creates a mirroring invoice and links it back. 409 if already accepted |
| POST | — | Office roles | body `{title, division, net_pence, client_id?}` | `{quote}` 201 |

Numbers are `QUO-<year>-####`.

---

## 25. `contracts.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | Auth, any role | — | `{contracts, totals: {annual_active_pence}}` with `days_to_renewal` |
| POST | `status` | Office roles | `id`; body `{status}` | `{ok: true}`; status ∈ active/expired/cancelled |
| POST | `renew` | Office roles | `id`; body `{months}` | `{ok: true}`; months outside 1–60 is coerced to 12; extends from `GREATEST(end_date, CURDATE())` |
| POST | — | Office roles | body `{title, division, rate_pence, frequency, start_date, end_date, client_id?, scope?, sla_hours?}` | `{contract}` 201 |

Frequency ∈ weekly/fortnightly/monthly/quarterly; annualisation multiplies by
52 / 26 / 12 / 4 (`contracts.php:15`).

---

## 26. `jobs.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | Auth, any role | — | `{jobs}`, emergency first then high then standard |
| POST | `status` | Office roles + `employee` | `id`; body `{status}` | `{ok: true}`; status ∈ booked/assigned/in_progress/done/cancelled |
| POST | — | Office roles | body `{title, division?, type?, priority?, client_id?, scheduled_for?, notes?}` | `{job}` 201; type ∈ care_visit/clean/guard_shift/course/other |

---

## 27. `bookings.php`

The client-facing front door to cover.

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| POST | — | **Public**, rate-limited | body `{contact_name, email, phone?, division, site_name?, detail, preferred_start?, preferred_end?, consent}` | `{ok: true}` 201; links to an existing client by email, writes a `consent_log` row, raises a broadcast notification and emails the office |
| GET | — | Auth, any role | — | `{requests}`, 300 most recent |
| POST | `status` | Office roles | `id`; body `{status}` | `{ok: true}` |
| POST | `confirm` | Office roles | `id` | `{ok: true, shift_id}`; turns the request into an open shift and emails the requester. 409 if a shift already exists |

**Header comment discrepancy.** `bookings.php:5` says the status route sets
`quoted/declined`; the code also accepts `new` (`bookings.php:87`).

---

## 28. `portal.php`

Client self-service. Requires an authenticated user whose `client_id` is set;
otherwise 403 (`portal.php:10`).

| Method | Action | Params | Returns |
|---|---|---|---|
| GET | — | — | `{client, requests, invoices, workers, contracts}`; workers are the distinct staff on upcoming scheduled shifts with a derived `cleared` boolean, and invoices exclude drafts |
| POST | `request` | body `{division, detail, site_name?, preferred_start?}` | `{ok: true}` 201; raises a cover request under the caller's own client id |
| POST | `issue` | body `{title, detail, division?}` | `{ok: true, ref}` 201; logs a `complaint` incident at `medium` severity with reference `INC-<year>-####` |

Neither POST re-checks a role, but both are hard-scoped to the caller's own
`client_id`, and the contact name and email are taken from the token's user
row rather than the request body (`portal.php:80`).

---

## 29. `me.php`

Worker self-service, powering the mobile PWA. Requires an authenticated user
whose `worker_id` is set; otherwise 403 (`me.php:11`).

| Method | Action | Params | Returns |
|---|---|---|---|
| GET | `payslip` | `from`, `to` | `{period, lines, totals}` for the caller's own worker record only |
| POST | `accept` | `shift_id` | `{ok: true}`; atomic claim of an open shift. 409 if it is no longer open or was just taken, 403 if it belongs to another division |
| GET | — | — | `{worker, compliance, shifts, open_shifts}` |

Clock-in, clock-out, check-in and panic are not here; they go through
`field.php`, which reads `worker_id` off the token.

---

## 30. `compliance.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | `pack` | Auth, any role | `worker_id` | `{worker, credentials, references, documents, generated_by, generated_at}` — the audit bundle a CQC or SIA ACS inspector asks for |
| GET | — | Auth, any role | — | `{summary, items}` across every worker, soonest expiry first |
| POST | — | Office roles | body `{worker_id, type, number?, expiry?}` | `{ok: true}` 201 |

`classify()` (`compliance.php:10`) buckets an expiry as `expired` (past),
`expiring` (≤ 30 days), `valid`, or `unknown` (no date). The same logic is
duplicated as `classify_ins()` in `insurance.php:14`, `classify_me()` in
`me.php:14` and `rpt_status()` in `reports.php:37`.

The audit pack is readable by any authenticated role, including `client` and
`employee`, and returns a worker's email, phone, DBS and SIA detail and
document list.

**Header comment discrepancy.** `compliance.php:2-4` omits `?action=pack`; it
is documented only in the inline comment at `compliance.php:19-22`.

---

## 31. `references.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | Auth, any role | `worker_id` | `{references}` |
| POST | `status` | Office roles | `id`; body `{status, gap_note?}` | `{ok: true}`; status ∈ requested/received/verified/gap_explained |
| POST | — | Office roles | body `{worker_id, referee_name, referee_org?, relationship?, email?, phone?}` | `{ok: true}` 201 |

---

## 32. `insurance.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | Auth, any role | — | `{policies, summary: {el_minimum_met, el_minimum_pence}}` |
| POST | `delete` | Office roles | `id` | `{ok: true}`; soft delete |
| POST | — | Office roles | body `{type, insurer, policy_no?, cover_pence?, start_date?, expiry?, notes?}` | `{ok: true}` 201 |

Type ∈ employers_liability / public_liability / professional_indemnity / other.
`el_minimum_met` is true when a non-expired employers' liability policy carries
at least £5,000,000 of cover (`insurance.php:23`).

---

## 33. `incidents.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — (with `?id=`) | Auth, any role | `id` | `{incident, actions}` |
| GET | — | Auth, any role | — | `{incidents, summary}`, critical first, capped at 400 |
| POST | `status` | Office roles | `id`; body `{status}` | `{ok: true}`; status ∈ open/investigating/actioned/closed |
| POST | `add_action` | Office roles | `id`; body `{note}` | `{ok: true}` 201 |
| POST | `action_done` | Office roles | `action_id`; body `{done}` | `{ok: true}` |
| POST | — | Office roles + `trainer` + `employee` | body `{division, type, severity, title, detail, client_id?, site_name?, worker_id?, riddor?, safeguarding?, occurred_at?}` | `{ok: true, ref, id}` 201 |

Type ∈ accident/incident/near_miss/safeguarding/complaint; severity ∈
low/medium/high/critical. A `safeguarding` type forces the safeguarding flag
(`incidents.php:124`). Safeguarding, RIDDOR or critical incidents raise a
broadcast notification and email `MAIL_TO`. References are
`INC-<year>-####`.

---

## 34. `academy.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — (with `?id=`) | Auth, any role | `id` | `{programme, enrolments}` |
| GET | — | Auth, any role | — | `{programmes}` with learner counts |
| POST | `enrol` | Office roles + `trainer` | body `{programme_id, learner_name}` | `{ok: true}` 201 |
| POST | `complete` | Office roles + `trainer` | `enrolment_id` | `{ok: true, public_id}` 201; issues a certificate `RG-<year>-#####`. Idempotent — an enrolment that already holds a certificate returns the existing id with 200 (`academy.php:90`) |
| POST | `publish` | Office roles + `editor` | `id`; body `{status}` | `{ok: true}`; status ∈ draft/published/archived |
| POST | — | Office roles + `editor` | body `{title, description?, division?, status?, cpd_hours?}` | `{programme}` 201 |

---

## 35. `certificates.php`

Both routes are public; there is no `require_auth` anywhere in the file.

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | `badge` | **Public** | `id` (the certificate public id) | An Open Badges 3.0 / W3C Verifiable Credential JSON-LD assertion |
| GET | `verify` | **Public** | `id` | `{certificate: {id, learner_name, course, issued_on, status}}` |

Both require `status = "issued"` and 404 otherwise. Both disclose the learner's
name and course to anyone holding the id, which is the point of a public
verification page — but note the id is the sequential `RG-<year>-#####` from
`next_seq`, so ids are enumerable.

---

## 36. `blog.php`

Public reads, admin writes. The file runs an idempotent
`CREATE TABLE IF NOT EXISTS blog_posts` on every request (`blog.php:17`), so
it self-migrates on first deployment.

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | **Public** | `slug?` | `{post}` for a single published post, or `{posts}` — up to 100 published posts, newest first |
| GET | `all` | `super_admin`/`admin`/`manager`/`editor` | — | `{posts}` including drafts |
| POST | `create` | Same | body `{title, slug?, excerpt?, category?, image?, body?, status?, published_at?, read_mins?}` | `{ok: true, id}` 201; duplicate slug returns 400 with a clear message (`blog.php:127`) |
| POST | `update` | Same | `id`; same body | `{ok: true}` |
| POST | `delete` | Same | `id` | `{ok: true}`; soft delete |

Reading time is auto-computed at 200 words per minute when `read_mins` is
omitted or non-positive (`blog.php:114`).

---

## 37. `testimonials.php`

Same shape as `blog.php`, including the self-migrating
`CREATE TABLE IF NOT EXISTS testimonials` (`testimonials.php:15`).

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | **Public** | — | `{testimonials}` — up to 60 published entries |
| GET | `all` | `super_admin`/`admin`/`manager`/`editor` | — | `{testimonials}` including drafts |
| POST | `create` | Same | body `{author, body, role?, rating?, status?, sort_order?}` | `{ok: true, id}` 201; rating clamped to 1–5 |
| POST | `update` | Same | `id`; same body | `{ok: true}` |
| POST | `delete` | Same | `id` | `{ok: true}`; soft delete |

---

## 38. `contact.php`

| Method | Auth | Body | Returns |
|---|---|---|---|
| POST | **Public**, rate-limited | `{name, email, phone?, division?, message}` | `{ok: true}` 201 |

Name, phone and the subject line are stripped of CR/LF before interpolation
into mail headers (`contact.php:12-16`). Name ≤ 160 chars, message ≤ 5000. An
unrecognised division is stored as `other`. When mail is enabled it sends two
messages: an office alert with the sender as `Reply-To`, and an autoresponder
to the sender with the office as `Reply-To`. A message beginning
`CAREERS APPLICATION` switches both to careers wording (`contact.php:40`).
Mail failures are non-fatal — the enquiry is already saved.

Any other method returns 405 (`contact.php:6`).

---

## 39. `enquiries.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | Office roles | — | `{enquiries}`, 200 most recent |
| POST | `handled` | Office roles | `id` | `{ok: true}` |

The GET block precedes the action check, so a GET with any `action` returns the
list.

---

## 40. `ats.php`

Recruitment and onboarding.

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| POST | — | **Public**, rate-limited | body `{name, email, phone?, role_applied?, division, consent}` | `{ok: true}` 201; creates an applicant with source `careers`, a `consent_log` row, a broadcast notification and an office email |
| GET | — (with `?id=`) | Auth, any role | `id` | `{applicant, tasks}` |
| GET | — | Auth, any role | — | `{applicants}`, 500 most recent |
| POST | `stage` | Office roles | `id`; body `{stage}` | `{ok: true}`; stage ∈ applied/screened/interview/vetting/offer/onboarded/rejected |
| POST | `add_task` | Office roles | `id`; body `{label}` | `{ok: true}` 201 |
| POST | `task_done` | Office roles | `task_id`; body `{done}` | `{ok: true}` |
| POST | `onboard` | Office roles | `id` | `{ok: true, worker_id}` 201; creates a worker, marks the applicant `onboarded`, and seeds the five-item checklist at `ats.php:15`. 409 if already onboarded |
| POST | `from_learner` | Office roles + `trainer` | body `{name, email?, division?}` | `{ok: true, id}` 201; Academy-to-ATS handoff, entering at stage `screened`. An invalid email is replaced with `<name>@learner.invalid` (`ats.php:180`) |

The staff GET exposes every applicant's name, email and phone to any
authenticated role.

---

## 41. `users.php`

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | — | `super_admin`/`admin` | — | `{users}` including deactivated ones, with `active` and any client/worker link |
| POST | `deactivate` | `super_admin`/`admin` | `id` | `{ok: true}`; soft-deletes and revokes every token. 409 on self-deactivation |
| POST | — | `super_admin`/`admin` | body `{name, email, role?, division?, password, client_id?, worker_id?}` | `{user}` 201 |

Password ≥ 8 characters. Only a `super_admin` may create a `super_admin`
(403 otherwise). A duplicate email or a worker that already has a login returns
409. Linked client and worker ids are validated to exist and be non-deleted.

---

## 42. `notifications.php`

The bell. A user sees their own notifications plus broadcasts (`user_id IS
NULL`), but the unread count tracks only their own targeted rows — broadcasts
are always reported as read (`notifications.php:42`).

| Method | Action | Auth | Body | Returns |
|---|---|---|---|---|
| GET | `unread` | Auth, any role | — | `{unread}` |
| GET | — | Auth, any role | — | `{notifications, unread}`, 50 most recent |
| POST | `read` | Auth, any role | `{id}` | `{ok: true, unread}` |
| POST | `read_all` | Auth, any role | — | `{ok: true, unread: 0}` |

---

## 43. `comms.php`

| Method | Auth | Body | Returns |
|---|---|---|---|
| GET | Auth, any role | — | `{announcements}` — group-wide plus the caller's own division, 100 most recent |
| POST | Office roles | `{audience?, title, body}` | `{announcement}` 201 |

Audience ∈ all/care/academy/cleaning/security; body ≤ 5000 chars. Creating an
announcement also raises a broadcast notification.

---

## 44. `inbox.php`

Live IMAP mailbox with SMTP replies. Returns 503 if the PHP `imap` extension
is absent or `IMAP_HOST` is unset. TLS certificate validation is on unless
`IMAP_VALIDATE_CERT` is explicitly false (`inbox.php:25`).

**Auth: `super_admin`, `admin`, `manager` for every route.** Reading this mailbox
exposes every inbound enquiry, and `reply` sends as the company. Until this was
gated, any authenticated account could do both.

| Method | Action | Params | Returns |
|---|---|---|---|
| GET | `unread` | — | `{unread}` |
| GET | `list` (default) | `limit` (1–100, default 30) | `{messages}`, newest first |
| GET | `message` | `uid` | `{message}` including the decoded plain-text body, the CRM `contact` lookup, and marks the message `\Seen` |
| POST | `seen` | `uid` | `{ok: true}` |
| POST | `unseen` | `uid` | `{ok: true}` |
| POST | `reply` | body `{to, subject, message, in_reply_to?, uid?}` | `{ok: true}`; sends via `send_mail`, adds `In-Reply-To`/`References` when given, and flags the original `\Answered` |

`$action` defaults to `list` (`inbox.php:129`), so a bare GET lists messages.
An unrecognised action returns 404 (`inbox.php:238`).

**Header comment discrepancy.** `inbox.php:3-8` omits `action=unread`.

Any authenticated account of any role can read the whole shared mailbox and
send mail from the company address.

---

## 45. `files.php`

Document centre. The **whole file** is gated to
`super_admin`/`admin`/`manager`/`editor` at `files.php:60`, immediately after
`require_auth` — so list, download and extract are all closed to `employee`,
`trainer` and `client`.

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| GET | `download` | File-level roles | `id` | The raw file, with its own `Content-Type` and a `Content-Disposition` attachment header |
| GET | `extract` | File-level roles | `id` | `{name, mime, text, truncated, note}` — plain text pulled out of txt/csv, docx, xlsx or PDF, truncated at 12,000 characters |
| GET | — | File-level roles | — | `{files}` |
| POST | `delete` | Office roles | `id` | `{ok: true}`; soft delete |
| POST | — | File-level roles | `multipart/form-data`: `file`, `category?`, `client_id?`, `worker_id?` | `{file}` 201 |

Uploads are capped at 10 MB (`files.php:64`). The MIME type is detected from
file contents with `finfo`, not from the client-supplied type
(`files.php:179`), and must be one of nine allowed types (`files.php:65`).
Stored filenames are 16 random bytes plus the extension derived from the
detected MIME, so the original name never reaches the filesystem.

Uploads land in `server/uploads` — that is, `__DIR__ . '/uploads'`
(`files.php:63`), which under the deployment described in `server/README.md`
is **inside the document root**. Downloads are proxied through this endpoint,
but nothing in the PHP prevents a direct request to
`/uploads/<stored_name>`; that has to be blocked by `.htaccess` on the server.

**Header comment discrepancy.** `files.php:2-6` omits `?action=extract`, and
describes download as merely "auth required" without noting the file-wide role
gate.

---

## 46. `gdpr.php`

| Method | Action | Auth | Body | Returns |
|---|---|---|---|---|
| POST | `export` | `super_admin`/`admin` | `{email}` | Everything held for that address: enquiries, service requests, consent log, client records, worker records |
| POST | `erase` | `super_admin`/`admin` | `{email}` | `{ok: true, erased: {...}, needs_manual_review: {clients, workers}}` |

Erasure anonymises `contact_enquiries` and `service_requests` and deletes
`consent_log` rows. Client and worker records are **not** erased — employment
and contract records have an independent legal basis — but any match is
surfaced under `needs_manual_review` for a manual decision.

Note that the file reads the body and validates the email at `gdpr.php:13-15`,
**before** dispatching on the action. There is therefore no GET path at all,
and any request without a valid email in a JSON body fails 400 regardless of
action.

---

## 47. `audit.php`

| Method | Auth | Returns |
|---|---|---|
| GET | `super_admin`/`admin` | `{entries}` — the 200 most recent audit rows with the acting user's name |

---

## 48. `reports.php`

Five read-only export packs, all office roles, all GET. The client renders CSV
or PDF from the JSON.

| Action | Returns |
|---|---|
| `client_kpi` | Per-client revenue, outstanding, job count, last invoice and account manager, worst debt first |
| `cqc_pack` | Every care worker's right-to-work, DBS status, verified reference count and certificate count |
| `sia_acs` | Every security officer's licence number, SIA status and verified reference count |
| `coshh` | Cleaning workforce compliance plus the 200 most recent cleaning incidents |
| `skills_for_care` | Academy programmes with enrolled and completed counts |

An unrecognised action returns 400 `Unknown report.` (`reports.php:123`).

**Header comment discrepancy.** `reports.php:3` documents only `client_kpi`;
the other four packs are undocumented at the top of the file.

---

## 49. `payments.php`

Stripe Checkout, hosted. No card data touches this server.

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| POST | `webhook` | **None — Stripe signature verified** | Raw body; `Stripe-Signature` header | `{ok: true}` |
| GET | `public_checkout` | **None — 40-hex token in the URL** | `token` | 302 redirect to the Stripe hosted page |
| POST | `paylink` | Office roles | `id` | `{url}`; mints a 40-hex `pay_token` on the invoice if absent |
| POST | `checkout` | Office roles | body `{invoice_id}` | `{url}` |

All routes return 503 until `STRIPE_SECRET_KEY` is set to a real value, so
deploying the file changes nothing until the keys are added.

**Webhook verification** (`payments.php:23-83`): the `t=`/`v1=` pairs are
parsed out of the signature header, HMAC-SHA256 of `t.payload` is compared with
`hash_equals` against `STRIPE_WEBHOOK_SECRET`, and events older than 300
seconds are rejected as replays. On `checkout.session.completed` the handler
re-reads the invoice total from the database and marks it paid **only** when
`amount_total` matches exactly and the currency is `gbp`
(`payments.php:61`); a mismatch is written to the audit log as
`invoice.paid.mismatch` and the invoice is left unpaid. A receipt is emailed to
the client when mail is enabled.

`public_checkout` requires the token to match `^[a-f0-9]{40}$`, and 409s on an
invoice that is already paid or void.

**Header comment discrepancy.** `payments.php:6-7` documents only `checkout`
and `webhook`; `public_checkout` and `paylink` are undocumented at the top of
the file.

---

## 50. `gocardless.php`

Direct Debit via the Billing Requests flow. Raw HTTPS, no SDK. Sandbox unless
`GC_ENVIRONMENT` is `live` (`gocardless.php:19`).

| Method | Action | Auth | Params | Returns |
|---|---|---|---|---|
| POST | `webhook` | **None — HMAC verified** | Raw body; `Webhook-Signature` header | `{ok: true}` |
| GET | `status` | Auth, any role | `client_id` | `{mandate}` or `{mandate: null}` |
| POST | `setup` | Office roles | body `{client_id}` | `{url}` — the hosted mandate authorisation page |
| POST | `complete` | Office roles | body `{mandate}` (the local mandate row id) | `{status}` — `active` or `pending` |
| POST | `charge` | Office roles | body `{invoice_id}` | `{ok: true, status}` |

Every route except the webhook 503s when `GC_ACCESS_TOKEN` is unset
(`gocardless.php:96`), which runs immediately after `require_auth`.

**Webhook verification** (`gocardless.php:50-55`): HMAC-SHA256 of the raw body
against `GC_WEBHOOK_SECRET`, compared with `hash_equals`. A bad signature
returns the non-standard status **498**. Mandate events update `mandates`;
payment events update `dd_payments`.

On the **first** settlement of a payment — a `confirmed` or `paid_out` event for
a `dd_payments` row not already in one of those states — the collected
`amount_pence` is credited to the invoice, capped at its total, and the invoice
is marked paid only once the credited total covers it. A full settlement writes
an `invoice.paid.gocardless` audit row; a part settlement writes
`invoice.part_paid.gocardless`.

Previously any confirmed collection set `amount_paid_pence = total_pence`
unconditionally, so a part-collection reported as paid in full, and a redelivered
webhook re-credited the same payment. Reading the row's prior status before
updating it is what makes the handler idempotent.

There is still no timestamp replay check, unlike the Stripe webhook. The
signature check itself is sound, so this is a robustness gap rather than a way
in.

`charge` refuses an already-paid invoice, an invoice with no client, or a
client with no active mandate (all 409).

---

## 51. `offer.php`

Public shift-offer accept/decline from an SMS or WhatsApp link. **No login.**
The 40-hex-character token in the URL is the only secret; it must match
`^[a-f0-9]{40}$` or the request 404s (`offer.php:10`).

| Method | Auth | Params | Returns |
|---|---|---|---|
| GET | **Public** | `token` | `{offer: {status, worker}, shift: {...}}` |
| POST | **Public**, rate-limited | `token`; body `{action}` | `{result, message}` |

`action` is `accept` or `decline`. An offer whose status is not `sent` returns
`{result: "closed"}`. Acceptance is an atomic conditional update — `UPDATE
shifts SET worker_id = ?, status = "scheduled" WHERE id = ? AND status =
"open"` (`offer.php:60`) — so exactly one worker wins the race; the winner
expires every other outstanding offer for that shift, and losers get
`{result: "filled"}`.

The GET is not rate-limited, so the offer detail (worker name, client name,
site and times) is enumerable by anyone who can guess a token, though the
160-bit space makes that impractical.

---

## 52. `weather.php`

| Method | Auth | Params | Returns |
|---|---|---|---|
| GET | Auth, any role | `city?` (default `London,GB`) | `{city, units, temp, feels_like, main, description, icon, humidity, wind_mph, advisory, fetched_at}` |

The OpenWeather key stays server-side. 503 if `OPENWEATHER_API_KEY` is unset or
`CHANGE_ME`. The `city` parameter is constrained to `^[A-Za-z .,'-]+$` and 60
characters, and anything else falls back to `London,GB` (`weather.php:24`), so
the proxy cannot be pointed at another host. Responses are cached for 15
minutes in a file under the system temp directory, keyed by an MD5 of city and
units.

`advisory.level` is `ok`, `watch` or `alert`, derived from normalised
temperature and wind (`weather.php:72-83`).

**Stale config comment.** `config.sample.php:51` describes
`OPENWEATHER_API_KEY` as read by "a future weather.php"; the endpoint exists.

---

## 53. `expiry-alerts.php` (cron)

Compliance renewal reminder engine, designed to run once a day.

| Trigger | Auth |
|---|---|
| CLI: `php expiry-alerts.php` | None needed — `PHP_SAPI === 'cli'` bypasses the key check (`expiry-alerts.php:20`) |
| HTTP: `GET /expiry-alerts.php?key=…` | **`?key` must `hash_equals` `ALERTS_KEY`**, else 403. An unset or `CHANGE_ME` key refuses every HTTP request |

It scans DBS and SIA expiries on the worker row, `certifications` rows with an
expiry, and company `insurance` policies, buckets each into a 90 / 60 / 30-day
or `expired` tier, and records each `(credential, tier)` pair in
`compliance_reminders` so a future-tier nudge fires exactly once. Expired items
re-nag at most weekly (`expiry-alerts.php:105`). The office gets one digest;
workers with a valid email on file get a personal nudge at the 30-day and
expired tiers only. Output is plain text for the cron log.

---

## 54. `backup.php` (cron)

| Trigger | Auth |
|---|---|
| CLI: `php backup.php` | None needed |
| HTTP: `GET /backup.php?key=…` | **`?key` must `hash_equals` `ALERTS_KEY`**, else 403 |

Writes a gzipped `mysqldump` to `dirname(__DIR__) . '/backups'` — one level
**above** the document root, so it is never web-accessible — and prunes dumps
older than 14 days. Requires `shell_exec` and `mysqldump`; it reports clearly
rather than failing silently when either is unavailable. Output is plain text.

Note that the database password is passed on the `mysqldump` command line
(`backup.php:43`), where it is briefly visible in the host's process list. It
is `escapeshellarg`-quoted, so it is not an injection risk.

---

## 54a. `right-to-work.php`

Right-to-work checks. A check is evidence, not a flag. Added in Milestone 3; before it, right
to work was a single `TINYINT(1)` on the worker record with no method, date, verifier, evidence
or expiry.

**Auth: internal staff only (`require_staff`) to read; `super_admin`/`admin`/`manager` to
write.**

| Method | Action | Params | Returns |
|---|---|---|---|
| GET | — | `person_id` | `{person_id, checks[], current}` — full history, newest first, with the verifier's name |
| GET | `due` | `days` (0–365, default 60) | `{items[], window_days}` — checks that are unverified, flagged for follow-up, or coming up for recheck, each with an `attention` line saying which |
| POST | — | body `{person_id, method, method_note?, reference?, checked_on?, outcome?, recheck_due?, follow_up_required?, evidence_file_id?, notes?}` | `{ok, id}` 201 |
| POST | `verify` | `id` | `{ok: true}` |

`method` ∈ `online_share_code`, `manual_document`, `idsp_digital`, `other`.
`outcome` ∈ `pending`, `valid`, `invalid`, `requires_follow_up`.

**Recording and verifying are separate on purpose.** The person who collects a document is
often not the person who signs it off, and "verified" with nobody's name against it is not
verification. A check whose outcome is still `pending` cannot be verified — signing that off
would be signing off nothing — and a check already verified cannot be verified twice.

A future-dated check is refused. `workers.right_to_work` is kept in step with the latest check
so anything still reading the old flag does not disagree with the record.

## 15a. `compliance.php` — eligibility

Added in Milestone 2, alongside the existing summary and pack actions.

| Method | Action | Params | Returns |
|---|---|---|---|
| GET | `eligibility` | `worker_id`, `service?` | The verdict for one service: `{status, eligible, score, blocking[], warnings[], items[], reason}` |
| GET | `eligibility_all` | `worker_id` | `{worker, person_id, services{care,academy,cleaning,security}}` — a separate verdict per service |

`status` ∈ `eligible`, `eligible_with_warnings`, `not_eligible`. The verdict is **calculated**
from the rules configured for the service and the evidence on file; it is never read from a
stored flag, and no endpoint accepts one. Each call also records the result in
`person_services`.

`scheduling.php` POST calls the same check and refuses the assignment with HTTP 409 when
anything mandatory is unmet, naming every blocking requirement rather than one at a time.

## 54b. Endpoints added after the baseline audit

Documented here rather than renumbered into the list above, so the original audit numbering
stays stable and comparable.

| Endpoint | Purpose | Auth |
|---|---|---|
| `right-to-work.php` | Right-to-work checks: record, list, what is due, verify | Staff to read; office to write. See §54a |
| `verifications.php` | The generalised verification lifecycle for every other credential | Staff to read; office to write |
| `leave.php` | Leave and absence, with a holiday balance in hours | A worker sees and requests only their own; staff see all |
| `governance.php` | Deletion approval, export log, retention policies, the zone matrix | Staff; approvals and zones are admin or super-admin |
| `action-centre.php` | What needs attention today, grouped by urgency | Staff only |
| `system-health.php` | Live health checks, and `?action=workload` | `super_admin`/`admin`/`manager` |
| `forms-intake.php` | Google Forms webhook, integration health, retry | **Webhook: shared secret, no bearer token.** Views are staff only |
| `send-queued-mail.php` | Drains `email_queue`. The only place SMTP is spoken | **Cron.** HTTP needs `?key=ALERTS_KEY` |
| `migrate.php`, `seed.php`, `backfill-people.php` | Schema and fixtures | **CLI only.** Refuse to run over HTTP at all |

Two auth mechanisms here are neither a bearer token nor a role, and are worth stating plainly:

- **`forms-intake.php` POST** authenticates with a shared secret in the `X-RitaGreen-Secret`
  header, compared using `hash_equals`, because Google Apps Script has no login. It is rate
  limited, and an integration with no secret configured refuses every submission.
- **`send-queued-mail.php`** follows `expiry-alerts.php` and `backup.php`: CLI, or HTTP with
  `?key=` matching `ALERTS_KEY`.

## 55. Public / unauthenticated surface

Every route below is reachable with **no bearer token**. This is the full list.

`api.php` was on this list until both of its verbs were placed behind `require_auth()`; its POST was an unauthenticated, unthrottled write to the production database.

### Fully open, no secret required

| Route | Method | Throttle | Notes |
|---|---|---|---|
| `/contact.php` | POST | `contact` 5/10 min | Writes `contact_enquiries`; sends two emails |
| `/certificates.php?action=verify&id=` | GET | none | Discloses learner name and course for an issued certificate; ids are sequential and enumerable |
| `/certificates.php?action=badge&id=` | GET | none | Same data as an Open Badges 3.0 assertion |
| `/blog.php` | GET | none | Published posts; `?slug=` for one |
| `/testimonials.php` | GET | none | Published testimonials |
| `/ats.php` | POST (no `action`) | `ats` 8/10 min | Creates an applicant and a consent record |
| `/bookings.php` | POST (no `action`) | `booking` 8/10 min | Creates a service request and a consent record |
| `/auth.php?action=login` | POST | `login` 10/5 min | |
| `/auth.php?action=forgot` | POST | `forgot` 5/10 min | Deliberately non-enumerating |
| `/auth.php?action=reset` | POST | `reset` 10/10 min | Token must be 64 hex chars, single-use, 1-hour expiry |
| `/auth.php?action=register` | POST | `register` 6/10 min | Creates an ATS applicant, not a login |
| `/auth.php?action=google_config` | GET | none | Returns whether SSO is configured |
| `/auth.php?action=google_start` | GET | none | Returns the Google authorisation URL |
| `/auth.php?action=google_callback` | POST | `google` 20/5 min | Issues a real token; matches existing users only |
| `/auth.php?action=logout` | POST | none | No `require_auth`; a no-op without a token |
| **`OPTIONS` on every endpoint** | OPTIONS | none | `lib.php:50` returns 204 before any auth or dispatch |

### Open but secured by a shared secret

| Route | Method | Throttle | Secret |
|---|---|---|---|
| `/forms-intake.php?key=<integration>` | POST | `intake` 120/5 min | `X-RitaGreen-Secret` header, SHA-256 compared with `hash_equals`. An integration with no secret refuses everything |
| `/send-queued-mail.php?key=` | GET | none | `hash_equals` against `ALERTS_KEY`; also runs from CLI with no key |

### Open but secured by an unguessable token in the URL

| Route | Method | Throttle | Secret |
|---|---|---|---|
| `/offer.php?token=` | GET | none | 40 hex chars (160 bits) from `shift_offers.token` |
| `/offer.php?token=` | POST | `offer` 20/10 min | Same |
| `/payments.php?action=public_checkout&token=` | GET | none | 40 hex chars from `invoices.pay_token`; redirects to Stripe |

### Open but cryptographically verified

| Route | Method | Verification |
|---|---|---|
| `/payments.php?action=webhook` | POST | HMAC-SHA256 of `t.payload` against `STRIPE_WEBHOOK_SECRET`, `hash_equals`, plus a 300-second replay window. Amount and currency re-checked against the invoice before marking it paid |
| `/gocardless.php?action=webhook` | POST | HMAC-SHA256 of the raw body against `GC_WEBHOOK_SECRET`, `hash_equals`. No replay window, but settlement is idempotent and credits only the amount actually collected |

### Open but secured by a shared secret in a query parameter

| Route | Method | Secret |
|---|---|---|
| `/expiry-alerts.php?key=` | GET | `hash_equals` against `ALERTS_KEY`; refuses everything if the key is unset or `CHANGE_ME` |
| `/backup.php?key=` | GET | Same |

Both also run from the CLI with no key at all. A key passed in the query string
lands in the server access log, so prefer the CLI trigger where possible.

### Not public, despite appearances

- `weather.php` and `ai.php` call `require_auth` before anything else, so both
  upstream API keys are behind the token.
- `files.php` gates the entire file — including `download` — to
  `super_admin`/`admin`/`manager`/`editor`. Uploaded files themselves sit under
  `server/uploads` inside the document root and are protected only by whatever
  `.htaccess` the server carries, not by PHP.
- The staff halves of `ats.php`, `bookings.php`, `blog.php` and
  `testimonials.php` sit **after** their public branch, so a request that does
  not match the public condition exactly falls through to `require_auth`.

---

## 56. Summary of header-comment vs code discrepancies

Each of these is a case where the block comment at the top of the file
understates or misstates what the code does. None of them changes behaviour,
but each one misleads a reader who trusts the header.

| File | Discrepancy |
|---|---|
| `auth.php:2-12` | Omits seven routes: `sessions`, `revoke_session`, `unlock`, `register`, `google_config`, `google_start`, `google_callback`. Three of them are public |
| `certificates.php:2-3` | Omits `?action=badge`, the public Open Badges assertion |
| `payments.php:6-7` | Omits `?action=public_checkout` (public) and `?action=paylink` |
| `reports.php:2-3` | Omits four of five report actions: `cqc_pack`, `sia_acs`, `coshh`, `skills_for_care` |
| `inbox.php:3-8` | Omits `?action=unread` |
| `files.php:2-6` | Omits `?action=extract`; describes download as "auth required" without the file-wide role gate at `files.php:60` |
| `compliance.php:2-4` | Omits `?action=pack` (present only in an inline comment) |
| `clients.php:2-5` | Omits `?action=update` and `?action=rate_card` (inline comments only) |
| `workers.php:2-4` | Omits `?action=availability` and `?action=pay` (inline comments only) |
| `bookings.php:5` | Says the status route sets `quoted`/`declined`; the code also accepts `new` (`bookings.php:87`) |
| `invoices.php:2` | Says "manual reconciliation — no card processor wired yet"; Stripe (`payments.php`) and GoCardless (`gocardless.php`) both mark invoices paid |
| `config.sample.php:51` | Calls `OPENWEATHER_API_KEY` "read only by a future `weather.php`"; `weather.php` exists and uses it |
| `server/README.md` | Its endpoint table lists 8 of the 47 endpoint files. Treat this document as the reference instead |
