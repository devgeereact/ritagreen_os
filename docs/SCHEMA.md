# Database schema reference

Source of truth: [`server/schema.sql`](../server/schema.sql) (52 tables) **plus**
`server/migrations/` (20 migrations). A freshly built database has **73 tables**: the 52 in the
baseline, 20 added by migration, and `schema_migrations` itself. Counted against a local
database built from scratch on 2026-09-04. `schema.sql` is the from-scratch baseline and is not
edited to reflect migrations — `php server/migrate.php --status` is what tells you where a
database actually is.
Every line citation below is of the form `schema.sql:LINE`.

## 1. Overview

The database is **MySQL/MariaDB on cPanel shared hosting**, reached only through
the PHP endpoints in `server/`. The React app never connects to it directly.

Conventions that hold across the whole schema:

- **Engine and charset.** Every table is declared
  `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`. There are no exceptions.
- **Money is stored as integers in pence** (`schema.sql:3`). Columns are named
  `*_pence` — `net_pence`, `vat_pence`, `total_pence`, `rate_pence`,
  `charge_pence`, `pay_rate_pence`, `amount_pence`, `cover_pence`. Nothing uses
  `DECIMAL` for currency.
- **Soft delete via `deleted_at TIMESTAMP NULL`** on records that must not be
  hard-deleted (`schema.sql:3-4` names users, clients, workers and certificates;
  in practice fourteen tables carry the column — see §5).
- **Timestamps.** `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP` is near
  universal; mutable records add
  `updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
  The session runs at `SET time_zone = '+00:00'` (`schema.sql:7`), so everything
  is UTC.
- **Booleans are `TINYINT(1) NOT NULL DEFAULT 0`.** The PHP layer casts them to
  real booleans or to `0`/`1` integers on the way out, inconsistently — see §6.

### The file is not a migration set

`schema.sql` is a **single re-runnable file organised into "build passes"**, not
a numbered migration directory. Later blocks patch earlier ones *in the same
file*:

- Every table is `CREATE TABLE IF NOT EXISTS`.
- Later `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` blocks add columns to tables
  created hundreds of lines earlier (`schema.sql:405`, `417`, `461`, `505`,
  `511`, `552`, `667`, `677`, `766`).
- Two `ALTER TABLE ... MODIFY COLUMN` statements at `schema.sql:733-734` restate
  a definition that the `CREATE` above already produces; they exist only to
  bring pre-existing installs into line.

**The true shape of a table is its `CREATE` plus every later `ALTER` against
it.** The column tables in §4 present the merged result, and every column that
arrived by a later `ALTER` is marked as such with the line number of the
`ALTER`.

This is a known limitation. There is no schema version table, no ordering
guarantee beyond file order, and no down-migration. `ADD COLUMN IF NOT EXISTS`
is a MariaDB extension — the comment at `schema.sql:354` notes it is relied on
because the shared host runs MariaDB. On stock MySQL those statements would
fail. There is also no way to tell, from the database alone, which build pass an
install is at.

### How the schema reaches the server

The file is imported by hand through cPanel → phpMyAdmin (`schema.sql:2`). It is
not run by the deploy. Two of the fifty tables are an exception: `blog_posts`
and `testimonials` are created at request time by their own endpoints — see §6.

---

## 2. Table overview by domain

Fifty tables. One line each.

### Identity and security (8)

| Table | Purpose |
|---|---|
| `roles` | Reference list of the seven role keys and their labels. |
| `users` | Staff, client-portal and worker logins; role, division, soft delete. |
| `tokens` | Bearer session tokens, with device and last-seen tracking. |
| `user_2fa` | Per-user TOTP secret and enabled flag. |
| `password_resets` | Single-use, short-lived reset tokens. |
| `audit_log` | Append-only record of user actions. |
| `rate_limits` | One row per throttled request hit, counted within a window. |
| `counters` | Named atomic sequences for document numbers. |

### Clients and sites (5)

| Table | Purpose |
|---|---|
| `clients` | Customer accounts, pipeline stage, account manager, SLA. |
| `sites` | Physical locations belonging to a client. |
| `rate_cards` | Agreed charge per client, division and role. |
| `service_requests` | Client-facing cover requests / bookings. |
| `mandates` | GoCardless Direct Debit mandates, one active per client. |

### Workers (4)

| Table | Purpose |
|---|---|
| `workers` | The workforce: role, division, credentials, pay, availability. |
| `skills` | Catalogue of skills used for shift matching. |
| `worker_skills` | Junction of workers to skills. |
| `worker_references` | Reference checks, including explained employment gaps. |

### Compliance (5)

| Table | Purpose |
|---|---|
| `certifications` | Per-worker credentials with expiry and status. |
| `insurance` | Company-level policy register with cover amounts. |
| `compliance_reminders` | Dedup log for the 90/60/30/expired renewal digest. |
| `incidents` | Accidents, near misses, safeguarding and complaints. |
| `incident_actions` | Follow-up actions against an incident. |

### Recruitment (2)

| Table | Purpose |
|---|---|
| `applicants` | ATS pipeline from applied through to onboarded. |
| `onboarding_tasks` | Checklist items against an applicant. |

### Training (4)

| Table | Purpose |
|---|---|
| `programmes` | Academy courses, with CPD hours. |
| `enrolments` | A learner on a programme, and the certificate they earned. |
| `sessions` | Scheduled training sessions. |
| `certificates` | Publicly verifiable certificates with a stable public id. |

### Scheduling (5)

| Table | Purpose |
|---|---|
| `shifts` | Scheduled and open shifts. |
| `shift_templates` | Recurring weekly shift patterns. |
| `shift_offers` | Broadcast offers on open shifts, first to accept wins. |
| `shift_events` | Field-ops event stream: clock in/out, check-ins, panic. |
| `jobs` | Work orders and one-off bookings. |

### Time and pay (4)

| Table | Purpose |
|---|---|
| `timesheets` | Worked time, as claimed and then approved or rejected. |
| `pay_rules` | Uplift multipliers for night, weekend, bank holiday, overtime. |
| `bank_holidays` | England and Wales bank-holiday calendar, seeded to 2035. |
| `contracts` | Recurring service agreements with rate and SLA. |

### Commercial (5)

| Table | Purpose |
|---|---|
| `quotes` | Estimates, optionally converted to an invoice. |
| `invoices` | Sales invoices with VAT, due date and payment tracking. |
| `credit_notes` | Credits and refunds against an invoice. |
| `dd_payments` | GoCardless payments raised against a mandate. |
| `files` | Document centre: uploads tied to a client, worker or neither. |

### Governance (2)

| Table | Purpose |
|---|---|
| `consent_log` | GDPR opt-in record for public form submissions. |
| `contact_enquiries` | Submissions from the public contact form. |

### Comms (4)

| Table | Purpose |
|---|---|
| `announcements` | Broadcasts to all staff or one division. |
| `notifications` | Per-user in-app bell notifications. |
| `sms_log` | Audit of outbound text messages. |
| `items` | Smoke-test table (see Misc). |

### Content (2)

| Table | Purpose |
|---|---|
| `blog_posts` | Public blog and resource posts, managed in the CMS. |
| `testimonials` | Curated client reviews, managed in the CMS. |

### Misc (0 beyond the above)

`items` (`schema.sql:10`) is a two-column smoke test left over from the initial
API check. It is counted under Comms above only to keep the totals honest; it
belongs to no domain and nothing reads it.

---

## 3. Reading the column tables

- **Type** is the declared MySQL type, merged across `CREATE` and any later
  `ALTER`.
- **Null** is `yes`/`no` as declared.
- Where a column arrived through a later `ALTER`, the Notes column says
  "added by `ALTER` at `schema.sql:N`".
- Foreign keys give the referenced table and the `ON DELETE` behaviour.
- Indexes and unique keys are listed after each column table.

---

## 4. Tables

### `items`

Smoke test for the API and database connection. Nothing in the app reads it.
`CREATE` at `schema.sql:10`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `name` | VARCHAR(255) | no | — | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

### `roles`

Reference table of role keys. Seeded, never written at runtime.
`CREATE` at `schema.sql:17`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `role_key` | VARCHAR(32) | no | — | Primary key |
| `label` | VARCHAR(64) | no | — | Human-readable name |

### `users`

Every login: staff, client-portal users and worker app users. Soft-deleted.
`CREATE` at `schema.sql:32`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `name` | VARCHAR(160) | no | — | |
| `email` | VARCHAR(190) | no | — | Unique |
| `password_hash` | VARCHAR(255) | no | — | bcrypt |
| `role` | ENUM('super_admin','admin','manager','trainer','editor','employee','client') | no | 'employee' | Mirrors `roles.role_key` but is a separate hardcoded ENUM |
| `division` | ENUM('care','academy','cleaning','security') | yes | NULL | Division ENUM, `schema.sql:38` |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | TIMESTAMP | yes | NULL | Soft delete |
| `client_id` | INT | yes | NULL | Added by `ALTER` at `schema.sql:552`. Links a portal login to a client. **No foreign key.** |
| `worker_id` | INT | yes | NULL | Added by `ALTER` at `schema.sql:552`. Links a mobile login to a worker. **No foreign key.** |

Keys: `UNIQUE uniq_email (email)`.

### `tokens`

Bearer session tokens. One row per active session.
`CREATE` at `schema.sql:52`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `user_id` | INT | no | — | FK → `users(id)` **ON DELETE CASCADE** |
| `token_hash` | CHAR(64) | no | — | Unique |
| `expires_at` | DATETIME | no | — | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `user_agent` | VARCHAR(255) | yes | NULL | Added by `ALTER` at `schema.sql:505` |
| `ip` | VARCHAR(45) | yes | NULL | Added by `ALTER` at `schema.sql:505` |
| `last_seen_at` | DATETIME | yes | NULL | Added by `ALTER` at `schema.sql:505` |

Keys: `UNIQUE uniq_token (token_hash)`, `KEY idx_user (user_id)`.

### `audit_log`

Append-only action log. `CREATE` at `schema.sql:64`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `user_id` | INT | yes | NULL | **No foreign key and no index**, though `audit.php:12` joins on it |
| `action` | VARCHAR(64) | no | — | |
| `detail` | VARCHAR(255) | no | '' | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_action (action)`.

### `counters`

Named atomic sequences. `next_seq()` in `lib.php` increments `val` in one
statement, so concurrent invoice, quote and certificate creates never collide.
Verified: 24 invoices raised in parallel produced 24 unique numbers.
Key format is e.g. `invoice-2026` (`schema.sql:73-75`).
`CREATE` at `schema.sql:76`.

**Every document number comes from here, including in fixtures.** `server/seed.php` used to
write the incident ref `INC-<year>-0001` by hand and leave `val` at zero, so the first incident
filed on a freshly seeded database drew 0001 again and died on `uniq_inc_ref`. A number written
around this table is a collision waiting for the next writer.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `name` | VARCHAR(64) | no | — | Primary key |
| `val` | INT | no | 0 | |

### `rate_limits`

One row per throttled hit on a public endpoint. `rate_limit()` in `lib.php`
counts rows within a window and prunes rows older than a day probabilistically
(`lib.php:165`). `CREATE` at `schema.sql:83`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `bucket` | VARCHAR(64) | no | — | |
| `ip` | VARCHAR(45) | no | — | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_bucket_ip_time (bucket, ip, created_at)`.

### `clients`

Customer accounts. Soft-deleted. `CREATE` at `schema.sql:92`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `name` | VARCHAR(190) | no | — | |
| `type` | ENUM('care_home','nhs','landlord','event','private') | no | 'private' | |
| `contact_name` | VARCHAR(160) | yes | NULL | |
| `email` | VARCHAR(190) | yes | NULL | |
| `phone` | VARCHAR(40) | yes | NULL | |
| `divisions` | VARCHAR(120) | no | '' | **Comma-separated list of division ids, not a junction table.** See §6 |
| `status` | ENUM('active','prospect','paused') | no | 'prospect' | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | TIMESTAMP | yes | NULL | Soft delete |
| `stage` | ENUM('lead','qualified','proposal','won','lost') | no | 'lead' | Added by `ALTER` at `schema.sql:677` |
| `account_manager_id` | INT | yes | NULL | Added by `ALTER` at `schema.sql:677`. **No foreign key** to `users` |
| `sla_response_hours` | INT | yes | NULL | Added by `ALTER` at `schema.sql:677` |

Keys: none beyond the primary key. There is no index on `name`, `status` or
`deleted_at`.

### `sites`

Locations belonging to a client. `CREATE` at `schema.sql:107`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `client_id` | INT | no | — | FK → `clients(id)` **ON DELETE CASCADE** |
| `name` | VARCHAR(190) | no | — | |
| `address` | VARCHAR(255) | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | TIMESTAMP | yes | NULL | Soft delete |

Keys: `KEY idx_client (client_id)`.

### `workers`

The workforce. Soft-deleted. This is the most heavily `ALTER`ed table in the
file: eight of its twenty-three columns arrive after the `CREATE`.
`CREATE` at `schema.sql:120`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `name` | VARCHAR(160) | no | — | |
| `email` | VARCHAR(190) | yes | NULL | Not unique |
| `phone` | VARCHAR(40) | yes | NULL | |
| `role` | ENUM('carer','nurse','cleaner','guard','trainer') | no | 'carer' | |
| `division` | ENUM('care','academy','cleaning','security') | no | 'care' | Division ENUM, `schema.sql:126` |
| `status` | ENUM('active','onboarding','inactive') | no | 'onboarding' | |
| `right_to_work` | TINYINT(1) | no | 0 | |
| `dbs_status` | ENUM('valid','pending','expired') | yes | NULL | |
| `dbs_expiry` | DATE | yes | NULL | |
| `sia_number` | VARCHAR(40) | yes | NULL | |
| `sia_expiry` | DATE | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | TIMESTAMP | yes | NULL | Soft delete |
| `dbs_update_service` | TINYINT(1) | no | 0 | Added by `ALTER` at `schema.sql:405` |
| `recheck_due` | DATE | yes | NULL | Added by `ALTER` at `schema.sql:405` |
| `pay_rate_pence` | INT | no | 0 | Added by `ALTER` at `schema.sql:417` |
| `pay_type` | ENUM('paye','umbrella') | no | 'paye' | Added by `ALTER` at `schema.sql:417` |
| `holiday_hours` | DECIMAL(8,2) | no | 0 | Added by `ALTER` at `schema.sql:417` |
| `home_postcode` | VARCHAR(12) | yes | NULL | Added by `ALTER` at `schema.sql:766` |
| `travel_radius_mi` | INT | no | 10 | Added by `ALTER` at `schema.sql:766` |
| `avail_days` | TINYINT(1) | no | 1 | Added by `ALTER` at `schema.sql:766` |
| `avail_nights` | TINYINT(1) | no | 0 | Added by `ALTER` at `schema.sql:766` |
| `avail_weekends` | TINYINT(1) | no | 0 | Added by `ALTER` at `schema.sql:766` |

Keys: none beyond the primary key. No index on `division`, `status`,
`deleted_at`, `dbs_expiry` or `sia_expiry`, despite the expiry digest scanning
the last two.

### `certifications`

Per-worker credentials with renewal tracking. Distinct from `certificates`,
which are academy awards. `CREATE` at `schema.sql:139`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `worker_id` | INT | no | — | FK → `workers(id)` **ON DELETE CASCADE** |
| `type` | VARCHAR(80) | no | — | Free text, not an ENUM |
| `number` | VARCHAR(80) | yes | NULL | |
| `expiry` | DATE | yes | NULL | |
| `status` | ENUM('valid','pending','expired') | no | 'valid' | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_worker (worker_id)`. No `updated_at`, no `deleted_at`.

### `contact_enquiries`

Public contact-form submissions. `CREATE` at `schema.sql:152`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `name` | VARCHAR(160) | no | — | |
| `email` | VARCHAR(190) | no | — | |
| `phone` | VARCHAR(40) | yes | NULL | |
| `division` | ENUM('care','academy','cleaning','security','**other**') | no | 'other' | Division ENUM **plus `other`**, `schema.sql:157`. `contact.php:29` coerces anything unrecognised to `other` |
| `message` | TEXT | no | — | |
| `status` | ENUM('new','handled') | no | 'new' | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: none beyond the primary key.

### `programmes`

Academy courses. `CREATE` at `schema.sql:164`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `title` | VARCHAR(190) | no | — | |
| `description` | TEXT | yes | NULL | |
| `division` | ENUM('care','academy','cleaning','security') | no | **'academy'** | Division ENUM, `schema.sql:168`. The only one defaulting to `academy` |
| `status` | ENUM('draft','published','archived') | no | 'draft' | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |
| `cpd_hours` | INT | no | 0 | Added by `ALTER` at `schema.sql:667` |

Keys: none beyond the primary key. No `deleted_at`.

### `enrolments`

A learner on a programme. `CREATE` at `schema.sql:174`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `programme_id` | INT | no | — | FK → `programmes(id)` **ON DELETE CASCADE** |
| `learner_name` | VARCHAR(160) | no | — | Free text; no link to `users` or `workers` |
| `status` | ENUM('enrolled','in_progress','completed','withdrawn') | no | 'enrolled' | |
| `certificate_public_id` | VARCHAR(40) | yes | NULL | Soft reference to `certificates.public_id`. **No foreign key** |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_programme (programme_id)`.

### `sessions`

Scheduled training sessions. Not to be confused with auth sessions, which live
in `tokens`. `CREATE` at `schema.sql:185`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `programme_id` | INT | yes | NULL | FK → `programmes(id)` **ON DELETE CASCADE** |
| `worker_id` | INT | yes | NULL | FK → `workers(id)` **ON DELETE SET NULL** |
| `starts_at` | DATETIME | no | — | |
| `ends_at` | DATETIME | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_starts (starts_at)`, `KEY idx_sess_programme (programme_id)`,
`KEY idx_sess_worker (worker_id)`.

Note the CASCADE on a nullable column: deleting a programme deletes its
sessions rather than orphaning them, which is probably not what the nullable
column implies.

### `invoices`

Sales invoices. `CREATE` at `schema.sql:200`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `number` | VARCHAR(32) | no | — | Unique; allocated from `counters` |
| `client_id` | INT | yes | NULL | FK → `clients(id)` **ON DELETE SET NULL** |
| `division` | ENUM('care','academy','cleaning','security') | no | 'care' | Division ENUM, `schema.sql:204` |
| `net_pence` | INT | no | 0 | Signed INT caps at £21,474,836.47 |
| `vat_pence` | INT | no | 0 | |
| `total_pence` | INT | no | 0 | |
| `status` | ENUM('draft','sent','paid','overdue','void') | no | 'draft' | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |
| `due_date` | DATE | yes | NULL | Added by `ALTER` at `schema.sql:461`. Drives the aged-debt buckets |
| `pay_token` | CHAR(40) | yes | NULL | Added by `ALTER` at `schema.sql:511`. Public pay-link token. **No index and no unique key** — see §6 |
| `amount_paid_pence` | INT | no | 0 | Added by `ALTER` at `schema.sql:511`. Partial-payment tracking |

Keys: `UNIQUE uniq_number (number)`, `KEY idx_status (status)`,
`KEY idx_inv_client (client_id)`. No `deleted_at` — `void` is the terminal
status instead.

### `timesheets`

Worked time, as claimed and then decided on; the input to payroll.
`CREATE` at `schema.sql:228`, patched by migrations 013, 014 and 021.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `worker_id` | INT | no | — | FK → `workers(id)` **ON DELETE CASCADE** |
| `work_date` | DATE | no | — | The date the shift **starts**; an `end_time` at or before `start_time` means it crossed midnight |
| `start_time` | TIME | no | — | |
| `end_time` | TIME | no | — | |
| `shift_id` | INT | yes | NULL | FK → `shifts(id)` **ON DELETE SET NULL**. The rota entry these hours were worked on, when there was one. Nullable on purpose: hours are recorded for call-outs and cover arranged by phone that never had a shift. Migration 029 |
| `minutes` | INT | no | 0 | Clock duration start to end, breaks included, computed by `rg_shift_minutes()` |
| `break_minutes` | INT | no | 0 | Unpaid break. Pay is on `minutes - break_minutes`. Migration 029 |
| `status` | ENUM('draft','pending','approved','rejected','queried') | no | 'pending' | `rejected` added by migration 021; `draft` and `queried` by migration 038. Only `approved` is paid |
| `approved_by` | INT | yes | NULL | FK → `users(id)` **ON DELETE SET NULL**. Who decided, whichever way |
| `decided_at` | TIMESTAMP | yes | NULL | When. Migration 021 |
| `decision_note` | VARCHAR(255) | yes | NULL | Why. Required by the endpoint on a rejection. Migration 021 |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | Migration 014 |
| `deleted_at` | TIMESTAMP | yes | NULL | Migration 013 |

Keys: `KEY idx_worker_date (worker_id, work_date)`,
`KEY idx_ts_approver (approved_by)`, `KEY idx_timesheets_deleted (deleted_at)`,
`KEY idx_ts_status_date (status, work_date)`, `KEY idx_ts_shift (shift_id)`.

Only `approved` rows reach pay, and `pay_compute()` in `pay-lib.php` also
excludes rows with `deleted_at` set — it did not, so an archived entry was still
paid. `shift_id` links the hours to the rota entry (migration 029), and the endpoint
refuses a shift belonging to a different worker or one that already has a
timesheet — the same shift could previously be claimed twice and paid twice with
nothing showing it. Clock events in `shift_events` still do **not** become
timesheets automatically: attendance and hours are recorded separately, and
reconciling them is still a human job.

### `worker_pay_rates`

A worker's pay rate over time. `CREATE` in `server/migrations/022`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `worker_id` | INT | no | — | FK → `workers(id)` **ON DELETE CASCADE** |
| `rate_pence` | INT | no | — | |
| `pay_type` | ENUM('paye','umbrella') | no | 'paye' | |
| `effective_from` | DATE | no | — | First day this rate applies. A rate has no end date — the next row's `effective_from` ends it, which makes an overlap impossible to express |
| `set_by` | INT | yes | NULL | FK → `users(id)` **ON DELETE SET NULL** |
| `note` | VARCHAR(255) | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `UNIQUE KEY uniq_worker_effective (worker_id, effective_from)`,
`KEY idx_wpr_lookup (worker_id, effective_from)`.

`workers.pay_rate_pence` remains the **current** rate, for the profile screen and
for costing a new shift. This table is the history behind it, and `pay_compute()`
reads it per timesheet entry. Before it existed the engine read the current rate,
so a rise re-priced every period the worker had ever worked and no closed period
could be reproduced. Migration 022 backfills every worker at `2000-01-01`,
deliberately far enough back that every pay run already produced returns exactly
the same figures.

### `pay_floors`

A configured minimum hourly rate, by date. `CREATE` in `server/migrations/024`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `effective_from` | DATE | no | — | Primary key |
| `rate_pence` | INT | no | — | |
| `label` | VARCHAR(120) | no | '' | |
| `set_by` | INT | yes | NULL | FK → `users(id)` **ON DELETE SET NULL** |

**Empty by default**, the same reasoning as retention periods: an unset floor
checks nothing, and a wrong floor in code is worse than no floor. A floor, not
the National Minimum Wage — NMW has age bands and an apprentice rate, and
`workers` holds no date of birth — so `pay_compute()` flags a row below it
(`below_floor`) and never blocks the run.

### `announcements`

Broadcasts to staff. `CREATE` at `schema.sql:235`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `author_id` | INT | yes | NULL | FK → `users(id)` **ON DELETE SET NULL** |
| `audience` | ENUM('all','care','academy','cleaning','security') | no | 'all' | Division ENUM **plus `all`**, `schema.sql:238` |
| `title` | VARCHAR(190) | no | — | |
| `body` | TEXT | no | — | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_audience (audience)`, `KEY idx_ann_author (author_id)`.

### `contracts`

Recurring service agreements. Soft-deleted. `CREATE` at `schema.sql:248`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `client_id` | INT | yes | NULL | FK → `clients(id)` **ON DELETE SET NULL** |
| `division` | ENUM('care','academy','cleaning','security') | no | 'care' | Division ENUM, `schema.sql:251` |
| `title` | VARCHAR(190) | no | — | |
| `scope` | TEXT | yes | NULL | |
| `rate_pence` | INT | no | 0 | |
| `frequency` | ENUM('weekly','fortnightly','monthly','quarterly') | no | 'monthly' | |
| `sla_hours` | INT | yes | NULL | |
| `start_date` | DATE | no | — | |
| `end_date` | DATE | no | — | **NOT NULL**, so open-ended contracts cannot be represented |
| `status` | ENUM('active','expired','cancelled') | no | 'active' | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | TIMESTAMP | yes | NULL | Soft delete |

Keys: `KEY idx_status (status)`, `KEY idx_end (end_date)`,
`KEY idx_contract_client (client_id)`.

### `quotes`

Estimates, optionally converted to an invoice. `CREATE` at `schema.sql:270`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `number` | VARCHAR(32) | no | — | Unique; allocated from `counters` |
| `client_id` | INT | yes | NULL | FK → `clients(id)` **ON DELETE SET NULL** |
| `division` | ENUM('care','academy','cleaning','security') | no | 'care' | Division ENUM, `schema.sql:274` |
| `title` | VARCHAR(190) | no | — | |
| `net_pence` | INT | no | 0 | |
| `vat_pence` | INT | no | 0 | |
| `total_pence` | INT | no | 0 | |
| `status` | ENUM('draft','sent','accepted','declined') | no | 'draft' | |
| `invoice_id` | INT | yes | NULL | FK → `invoices(id)` **ON DELETE SET NULL** |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |

Keys: `UNIQUE uniq_quote_number (number)`, `KEY idx_status (status)`,
`KEY idx_quote_client (client_id)`, `KEY idx_quote_invoice (invoice_id)`.
No `deleted_at`.

### `jobs`

Work orders. Soft-deleted, and `jobs.php?action=archive` is what sets it —
nothing did until then, so the column existed and every query respected it while
no job could ever be taken off the board. `CREATE` at `schema.sql:302`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `client_id` | INT | yes | NULL | FK → `clients(id)` **ON DELETE SET NULL** |
| `division` | ENUM('care','academy','cleaning','security') | no | 'care' | Division ENUM, `schema.sql:295` |
| `type` | ENUM('care_visit','clean','guard_shift','course','other') | no | 'other' | |
| `title` | VARCHAR(190) | no | — | |
| `priority` | ENUM('emergency','high','standard') | no | 'standard' | |
| `status` | ENUM('booked','assigned','in_progress','done','cancelled') | no | 'booked' | |
| `scheduled_for` | DATETIME | yes | NULL | |
| `notes` | TEXT | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | TIMESTAMP | yes | NULL | Soft delete |

Keys: `KEY idx_status (status)`, `KEY idx_client (client_id)`. There is no
worker assignment column despite the `assigned` status.

### `certificates`

Publicly verifiable academy certificates. Soft-deleted.
`CREATE` at `schema.sql:311`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `public_id` | VARCHAR(40) | no | — | Unique; the id used on the public `/verify` page |
| `learner_name` | VARCHAR(160) | no | — | Free text |
| `course` | VARCHAR(190) | no | — | Free text; **no link to `programmes`** |
| `issued_on` | DATE | no | — | |
| `status` | ENUM('issued','revoked') | no | 'issued' | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `deleted_at` | TIMESTAMP | yes | NULL | Soft delete |

Keys: `UNIQUE uniq_public (public_id)`.

### `files`

Document centre. Soft-deleted. `CREATE` at `schema.sql:328`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `stored_name` | VARCHAR(190) | no | — | Name on disk |
| `original_name` | VARCHAR(255) | no | — | Name as uploaded |
| `mime` | VARCHAR(120) | no | — | |
| `size_bytes` | INT | no | 0 | Signed INT caps at ~2.1 GB |
| `category` | ENUM('contract','certificate','policy','rams','id','other') | no | 'other' | |
| `client_id` | INT | yes | NULL | FK → `clients(id)` **ON DELETE SET NULL** |
| `worker_id` | INT | yes | NULL | FK → `workers(id)` **ON DELETE SET NULL** |
| `uploaded_by` | INT | yes | NULL | FK → `users(id)` **ON DELETE SET NULL** |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `deleted_at` | TIMESTAMP | yes | NULL | Soft delete |

Keys: `KEY idx_category (category)`, `KEY idx_files_client (client_id)`,
`KEY idx_files_worker (worker_id)`, `KEY idx_files_uploader (uploaded_by)`.

Soft-deleting a row leaves the file on disk; nothing in the schema records
whether the blob was reaped.

### `compliance_reminders`

Dedup log for the renewal digest, so a 60-day nudge never fires twice.
`credential_key` is e.g. `dbs:12`, `sia:12`, `cert:88`, `ins:3`
(`schema.sql:357-360`). `CREATE` at `schema.sql:361`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `credential_key` | VARCHAR(64) | no | — | Composite string key, not a foreign key |
| `tier` | ENUM('d90','d60','d30','expired') | no | — | |
| `sent_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_cred_tier (credential_key, tier)`, `KEY idx_sent (sent_at)`.
The dedup key is an index, **not a unique key**, so the guarantee depends
entirely on the application checking before it writes.

### `insurance`

Company-level policy register. Soft-deleted. `CREATE` at `schema.sql:372`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `type` | ENUM('employers_liability','public_liability','professional_indemnity','other') | no | 'public_liability' | |
| `insurer` | VARCHAR(160) | no | — | |
| `policy_no` | VARCHAR(80) | yes | NULL | |
| `cover_pence` | **BIGINT** | no | 0 | The only money column not INT — £5m cover would overflow a signed INT |
| `start_date` | DATE | yes | NULL | |
| `expiry` | DATE | yes | NULL | |
| `notes` | VARCHAR(255) | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | TIMESTAMP | yes | NULL | Soft delete |

Keys: `KEY idx_ins_expiry (expiry)`.

### `worker_references`

Reference checks, two or more per worker, with gaps explained.
`CREATE` at `schema.sql:388`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `worker_id` | INT | no | — | FK → `workers(id)` **ON DELETE CASCADE** |
| `referee_name` | VARCHAR(160) | no | — | |
| `referee_org` | VARCHAR(160) | yes | NULL | |
| `relationship` | VARCHAR(80) | yes | NULL | |
| `email` | VARCHAR(190) | yes | NULL | |
| `phone` | VARCHAR(40) | yes | NULL | |
| `status` | ENUM('requested','received','verified','gap_explained') | no | 'requested' | |
| `gap_note` | VARCHAR(255) | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |

Keys: `KEY idx_ref_worker (worker_id)`.

### `pay_rules`

Uplift multipliers. `payroll.php` applies the single highest day premium per
entry, plus overtime beyond the weekly threshold (`schema.sql:422-425`).
`CREATE` at `schema.sql:426`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `rule_key` | ENUM('night','weekend','bank_holiday','overtime') | no | — | **Primary key is an ENUM** — adding a rule type is a schema change |
| `multiplier` | DECIMAL(4,2) | no | 1.00 | 1.50 = time and a half |
| `meta` | VARCHAR(120) | yes | NULL | Human-readable description of when the rule applies; not machine-read |

### `bank_holidays`

England and Wales calendar. `CREATE` at `schema.sql:439`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `holiday_date` | DATE | no | — | Primary key |
| `name` | VARCHAR(80) | no | — | |

Seeded to the end of **2035** by migration 023. It held 2026 only, and
`pay_load_bank_holidays()` returns what it finds, so from 2027-01-01 every bank
holiday would silently have paid flat rate instead of the configured 2×
multiplier.

Those rows are generated, not typed: `rg_bank_holidays()` in `server/pure.php`
computes the standard eight for a year (Easter by the anonymous Gregorian
algorithm, not `easter_days()`, which needs `ext-calendar` a host may not have),
and `server/tests/run.php` checks it reproduces the eight 2026 rows this table
already held — substitute days included — plus the published 2027 dates and the
2022 Christmas-on-a-Sunday case, where Boxing Day keeps the Monday and Christmas
moves to the Tuesday.

The table stays the source of truth rather than the function. One-off holidays
happen — the 2022 Jubilee and the 2023 Coronation were announced, not derived —
and are inserted alongside. Scotland and Northern Ireland differ; operating
there needs a nation column before it needs more rows.

### `jobs.assigned_to`

`INT NULL`, FK → `workers(id)` **ON DELETE SET NULL** (migration 030).
`jobs.status` has had `assigned` in its enum since the table was created and
there was no assignee column anywhere, so the status was decorative: a job could
be marked assigned with nothing recording who to. `jobs.php?action=assign` runs
`rg_deployment_check()` before naming somebody — a work order is not a shift, but
putting a named carer against a care visit is a deployment decision, and it would
be odd for the rota to refuse what the job board allows.

### `credit_notes`

Credits and refunds against an invoice. `CREATE` at `schema.sql:465`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `number` | VARCHAR(32) | no | — | Unique; allocated from `counters` |
| `invoice_id` | INT | no | — | FK → `invoices(id)` **ON DELETE CASCADE** |
| `amount_pence` | INT | no | 0 | |
| `reason` | VARCHAR(255) | yes | NULL | |
| `created_by` | INT | yes | NULL | FK → `users(id)` **ON DELETE SET NULL** |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `UNIQUE uniq_cn_number (number)`, `KEY idx_cn_invoice (invoice_id)`.

CASCADE on a financial record: deleting an invoice row silently destroys its
credit notes. Since `invoices` has no `deleted_at`, that path is a hard delete.

### `shifts`

Scheduled and open shifts. `worker_id` is nullable because an open shift has no
worker until one accepts a broadcast offer (`schema.sql:480-481`). Draft and published, the site link and the cancellation reason were added by migration 038; see "The rota: migration 038" at the end of this file.
`CREATE` at `schema.sql:482`, with two no-op `MODIFY` statements at
`schema.sql:733-734` for older installs.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `worker_id` | INT | yes | NULL | FK → `workers(id)` **ON DELETE CASCADE**. Restated by `MODIFY` at `schema.sql:733` |
| `client_id` | INT | yes | NULL | **No foreign key** — see §6 |
| `division` | ENUM('care','academy','cleaning','security') | no | 'care' | Division ENUM, `schema.sql:486` |
| `title` | VARCHAR(190) | no | — | |
| `site_name` | VARCHAR(190) | yes | NULL | Free text; **no link to `sites`** |
| `starts_at` | DATETIME | no | — | |
| `ends_at` | DATETIME | no | — | |
| `status` | ENUM('open','scheduled','completed','cancelled') | no | 'scheduled' | Restated by `MODIFY` at `schema.sql:734` |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |

Keys: `KEY idx_worker_time (worker_id, starts_at)`, `KEY idx_time (starts_at)`.
No index on `client_id` or `status`. No `deleted_at`.

**ON DELETE CASCADE on `worker_id` is wrong for an audit trail**: hard-deleting a
worker destroys their shift history, and `shift_events` cascades from `shifts`,
so the field-ops log goes with it. Workers are soft-deleted in practice, which
masks this.

### `mandates`

GoCardless Direct Debit mandates. `CREATE` at `schema.sql:516`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `client_id` | INT | no | — | FK → `clients(id)` **ON DELETE CASCADE** |
| `gc_customer_id` | VARCHAR(64) | yes | NULL | GoCardless id |
| `gc_mandate_id` | VARCHAR(64) | yes | NULL | GoCardless id; **not unique** |
| `status` | ENUM('pending','active','failed','cancelled') | no | 'pending' | |
| `reference` | VARCHAR(40) | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |

Keys: `KEY idx_mandate_client (client_id)`, `KEY idx_mandate_ref (reference)`.

The "one active mandate per client" rule in the comment at `schema.sql:515` is
not enforced by any constraint.

### `dd_payments`

GoCardless payments raised against a mandate. `CREATE` at `schema.sql:531`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `invoice_id` | INT | yes | NULL | FK → `invoices(id)` **ON DELETE SET NULL** |
| `mandate_id` | INT | yes | NULL | FK → `mandates(id)` **ON DELETE SET NULL** |
| `gc_payment_id` | VARCHAR(64) | yes | NULL | GoCardless id; indexed but **not unique**, so a replayed webhook can double-insert |
| `amount_pence` | INT | no | 0 | |
| `status` | VARCHAR(40) | no | 'pending_submission' | Free text, not an ENUM — the only payment status column that is not |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_ddp_invoice (invoice_id)`, `KEY idx_ddp_gc (gc_payment_id)`.

### `incidents`

Accidents, near misses, safeguarding concerns and complaints.
`CREATE` at `schema.sql:557`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `ref` | VARCHAR(24) | no | — | Unique human reference |
| `division` | ENUM('care','academy','cleaning','security') | no | 'care' | Division ENUM, `schema.sql:560` |
| `type` | ENUM('accident','incident','near_miss','safeguarding','complaint') | no | 'incident' | |
| `severity` | ENUM('low','medium','high','critical') | no | 'low' | |
| `title` | VARCHAR(190) | no | — | |
| `detail` | TEXT | no | — | |
| `client_id` | INT | yes | NULL | FK → `clients(id)` **ON DELETE SET NULL** |
| `site_name` | VARCHAR(190) | yes | NULL | Free text; no link to `sites` |
| `worker_id` | INT | yes | NULL | FK → `workers(id)` **ON DELETE SET NULL** |
| `riddor` | TINYINT(1) | no | 0 | RIDDOR reportable |
| `safeguarding` | TINYINT(1) | no | 0 | |
| `status` | ENUM('open','investigating','actioned','closed') | no | 'open' | |
| `reported_by` | INT | yes | NULL | FK → `users(id)` **ON DELETE SET NULL** |
| `occurred_at` | DATETIME | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |

Keys: `UNIQUE uniq_inc_ref (ref)`, `KEY idx_inc_status (status)`,
`KEY idx_inc_type (type)`. No `deleted_at` — see §6.

### `incident_actions`

Follow-up actions against an incident. `CREATE` at `schema.sql:583`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `incident_id` | INT | no | — | FK → `incidents(id)` **ON DELETE CASCADE** |
| `note` | VARCHAR(500) | no | — | |
| `done` | TINYINT(1) | no | 0 | |
| `created_by` | INT | yes | NULL | **No foreign key** to `users`, unlike `credit_notes.created_by` |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_ia_incident (incident_id)`.

### `shift_events`

Field-ops event stream: clock in and out, check-ins, panic alerts, notes, tasks
and client sign-off. `CREATE` at `schema.sql:595`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `shift_id` | INT | no | — | FK → `shifts(id)` **ON DELETE CASCADE** |
| `worker_id` | INT | yes | NULL | FK → `workers(id)` **ON DELETE SET NULL** |
| `kind` | ENUM('clock_in','clock_out','check_in','panic','note','task','signoff') | no | 'note' | |
| `note` | VARCHAR(500) | yes | NULL | |
| `lat` | DECIMAL(9,6) | yes | NULL | Returned as a string by mysqli |
| `lng` | DECIMAL(9,6) | yes | NULL | Returned as a string by mysqli |
| `signer` | VARCHAR(160) | yes | NULL | Client sign-off name; free text |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_se_shift (shift_id)`, `KEY idx_se_kind (kind)`.

### `applicants`

ATS pipeline. `CREATE` at `schema.sql:612`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `name` | VARCHAR(160) | no | — | |
| `email` | VARCHAR(190) | no | — | Not unique |
| `phone` | VARCHAR(40) | yes | NULL | |
| `role_applied` | VARCHAR(80) | yes | NULL | Free text, unlike `workers.role` which is an ENUM |
| `division` | ENUM('care','academy','cleaning','security') | no | 'care' | Division ENUM, `schema.sql:618` |
| `stage` | ENUM('applied','screened','interview','vetting','offer','onboarded','rejected') | no | 'applied' | |
| `source` | VARCHAR(80) | yes | NULL | |
| `notes` | TEXT | yes | NULL | |
| `worker_id` | INT | yes | NULL | FK → `workers(id)` **ON DELETE SET NULL**. Set when the applicant is converted |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |

Keys: `KEY idx_app_stage (stage)`. No `deleted_at` despite holding personal data
subject to GDPR erasure — see §6.

### `onboarding_tasks`

Checklist items against an applicant. `CREATE` at `schema.sql:629`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `applicant_id` | INT | no | — | FK → `applicants(id)` **ON DELETE CASCADE** |
| `label` | VARCHAR(160) | no | — | |
| `done` | TINYINT(1) | no | 0 | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_ot_applicant (applicant_id)`.

### `user_2fa`

Per-user TOTP secret (RFC 6238). `CREATE` at `schema.sql:645`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `user_id` | INT | no | — | Primary key **and** FK → `users(id)` **ON DELETE CASCADE**. One row per user |
| `secret` | VARCHAR(64) | no | — | Stored in plaintext, not encrypted at rest |
| `enabled` | TINYINT(1) | no | 0 | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

No recovery-code table exists, so losing the authenticator means an
administrator has to delete the row by hand.

### `password_resets`

Single-use, short-lived reset tokens. `CREATE` at `schema.sql:654`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `user_id` | INT | no | — | FK → `users(id)` **ON DELETE CASCADE** |
| `token_hash` | CHAR(64) | no | — | Unique |
| `expires_at` | DATETIME | no | — | |
| `used` | TINYINT(1) | no | 0 | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `UNIQUE uniq_reset_token (token_hash)`, `KEY idx_reset_user (user_id)`.

### `rate_cards`

Agreed charge per client, division and role. `CREATE` at `schema.sql:683`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `client_id` | INT | no | — | FK → `clients(id)` **ON DELETE CASCADE** |
| `division` | ENUM('care','academy','cleaning','security') | no | 'care' | Division ENUM, `schema.sql:686` |
| `role` | VARCHAR(80) | no | 'standard' | Free text, unlike `workers.role` |
| `charge_pence` | INT | no | 0 | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_rc_client (client_id)`. There is **no unique key on
(client_id, division, role)**, so duplicate and contradictory rate cards can
coexist and the lookup result depends on row order.

### `service_requests`

Client-facing cover requests. `CREATE` at `schema.sql:695`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `client_id` | INT | yes | NULL | FK → `clients(id)` **ON DELETE SET NULL** |
| `contact_name` | VARCHAR(160) | no | — | |
| `email` | VARCHAR(190) | no | — | |
| `phone` | VARCHAR(40) | yes | NULL | |
| `division` | ENUM('care','academy','cleaning','security') | no | 'care' | Division ENUM, `schema.sql:701` |
| `site_name` | VARCHAR(190) | yes | NULL | Free text |
| `detail` | TEXT | no | — | |
| `preferred_start` | DATETIME | yes | NULL | |
| `preferred_end` | DATETIME | yes | NULL | |
| `status` | ENUM('new','quoted','confirmed','declined') | no | 'new' | |
| `shift_id` | INT | yes | NULL | The shift raised from this request. **No foreign key** |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_sr_status (status)`, `KEY idx_sr_client (client_id)`.
No `updated_at` despite `status` being mutable.

### `consent_log`

GDPR opt-in record for public forms. `CREATE` at `schema.sql:715`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `email` | VARCHAR(190) | no | — | |
| `kind` | VARCHAR(40) | no | 'contact' | Free text, not an ENUM |
| `granted` | TINYINT(1) | no | 1 | |
| `source` | VARCHAR(80) | yes | NULL | |
| `ip` | VARCHAR(45) | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_consent_email (email)`.

Append-only by design: a withdrawal is a new row with `granted = 0`, so current
consent is "the latest row for this email and kind", which nothing in the schema
enforces.

### `skills`

Catalogue used for shift matching. `CREATE` at `schema.sql:737`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `name` | VARCHAR(80) | no | — | Unique |
| `category` | ENUM('care','academy','cleaning','security','**general**') | no | 'general' | Division ENUM **plus `general`**, `schema.sql:740` |

Keys: `UNIQUE uniq_skill (name)`.

### `worker_skills`

Junction of workers to skills. `CREATE` at `schema.sql:757`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `worker_id` | INT | no | — | Part of the composite primary key. FK → `workers(id)` **ON DELETE CASCADE** |
| `skill_id` | INT | no | — | Part of the composite primary key. FK → `skills(id)` **ON DELETE CASCADE** |

Keys: `PRIMARY KEY (worker_id, skill_id)`.

This is the one properly normalised many-to-many in the schema — contrast
`clients.divisions`.

### `shift_templates`

Recurring weekly shift patterns. `CREATE` at `schema.sql:774`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `title` | VARCHAR(190) | no | — | |
| `division` | ENUM('care','academy','cleaning','security') | no | 'care' | Division ENUM, `schema.sql:777` |
| `client_id` | INT | yes | NULL | FK → `clients(id)` **ON DELETE SET NULL** |
| `site_name` | VARCHAR(190) | yes | NULL | Free text |
| `weekday` | TINYINT | no | — | 1 = Monday through 7 = Sunday, ISO (`schema.sql:780`). Not range-constrained |
| `start_time` | TIME | no | — | |
| `end_time` | TIME | no | — | |
| `active` | TINYINT(1) | no | 1 | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_tmpl_active (active)`. No `updated_at`, no `deleted_at`.

### `shift_offers`

Broadcast offers on open shifts; first to accept wins.
`CREATE` at `schema.sql:790`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `shift_id` | INT | no | — | FK → `shifts(id)` **ON DELETE CASCADE** |
| `worker_id` | INT | no | — | FK → `workers(id)` **ON DELETE CASCADE** |
| `token` | CHAR(40) | no | — | Unique; the accept link |
| `status` | ENUM('sent','accepted','declined','expired') | no | 'sent' | |
| `responded_at` | DATETIME | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `UNIQUE uniq_offer_token (token)`, `KEY idx_offer_shift (shift_id)`,
`KEY idx_offer_worker (worker_id)`.

There is no expiry column and no unique key on `(shift_id, worker_id)`, so the
same worker can be offered the same shift repeatedly, and "expired" is a status
someone has to set rather than a time the row passes.

### `notifications`

Per-user in-app bell notifications. `CREATE` at `schema.sql:806`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `user_id` | INT | yes | NULL | NULL means broadcast to all staff (`schema.sql:808`). FK → `users(id)` **ON DELETE CASCADE** |
| `type` | VARCHAR(40) | no | 'info' | Free text |
| `title` | VARCHAR(190) | no | — | |
| `body` | VARCHAR(500) | yes | NULL | |
| `url` | VARCHAR(190) | yes | NULL | |
| `read_at` | DATETIME | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_notif_user (user_id, read_at)`.

A broadcast row (`user_id IS NULL`) has a single `read_at`, so it cannot record
per-user read state — the first person to read it marks it read for everybody,
or the application ignores `read_at` for broadcasts.

### `sms_log`

Audit of outbound texts. `CREATE` at `schema.sql:820`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `recipient` | VARCHAR(40) | no | — | Phone number, stored in the clear |
| `body` | VARCHAR(500) | no | — | Message text, stored in the clear |
| `status` | ENUM('sent','failed','skipped') | no | 'sent' | |
| `meta` | VARCHAR(190) | yes | NULL | |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |

Keys: `KEY idx_sms_time (created_at)`. No retention or pruning is defined
anywhere in the schema.

### `blog_posts`

Public blog and resource posts. Soft-deleted.
`CREATE` at `schema.sql:831`, **and identically at `server/blog.php:18`** — see
§6.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `slug` | VARCHAR(190) | no | — | Unique |
| `title` | VARCHAR(255) | no | — | |
| `excerpt` | VARCHAR(500) | no | '' | |
| `category` | VARCHAR(64) | no | 'Company' | Free text, not an ENUM |
| `image` | VARCHAR(500) | no | '' | URL or path |
| `body` | MEDIUMTEXT | no | — | |
| `read_mins` | INT | no | 3 | |
| `status` | ENUM('draft','published') | no | 'draft' | |
| `published_at` | DATE | yes | NULL | DATE, not DATETIME |
| `author_id` | INT | yes | NULL | **No foreign key** to `users` |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | TIMESTAMP | yes | NULL | Soft delete |

Keys: `UNIQUE uniq_blog_slug (slug)`, `KEY idx_blog_pub (status, published_at)`.

The unique key on `slug` covers soft-deleted rows too, so a deleted post's slug
can never be reused.

### `testimonials`

Curated client reviews. Soft-deleted.
`CREATE` at `schema.sql:851`, **and identically at
`server/testimonials.php:16`** — see §6.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT AUTO_INCREMENT | no | — | Primary key |
| `author` | VARCHAR(160) | no | — | |
| `role` | VARCHAR(190) | no | '' | Job title of the reviewer, unrelated to `users.role` |
| `rating` | TINYINT | no | 5 | Not range-constrained; nothing stops 0 or 99 |
| `body` | VARCHAR(1000) | no | — | |
| `status` | ENUM('draft','published') | no | **'published'** | The only content table defaulting to published |
| `sort_order` | INT | no | 0 | |
| `author_id` | INT | yes | NULL | The CMS user who wrote the row, not the reviewer. **No foreign key** |
| `created_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | yes | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | TIMESTAMP | yes | NULL | Soft delete |

Keys: `KEY idx_testi_pub (status, sort_order)`.

---

## 5. Seeded data

The file seeds six things. Every seed uses `INSERT IGNORE`, so re-importing the
file does not duplicate or overwrite them.

### Roles — 7 rows (`schema.sql:22`)

`super_admin`, `admin`, `manager`, `trainer`, `editor`, `employee`, `client`,
each with a display label.

### Super-admin account — removed

`schema.sql` used to seed a `super_admin` whose password was written in plain text in the
comment above its bcrypt hash, in a version-controlled file, for an account with access to every
worker record. "Change it after first login" was the only control, and nothing enforced or
checked it.

The seed is gone. `php server/create-admin.php --email … --name …` creates the first
administrator with a prompted password instead. Local development is unaffected: `seed.php`
makes its own fixture accounts and refuses to run against a remote database.

**Any database built from the old `schema.sql` still has that account.** Check for
`dev@ritagreenservices.com` on every existing install and remove it or change its password.

### Pay rules — 4 rows (`schema.sql:432`)

| Rule | Multiplier | Applies to |
|---|---|---|
| `night` | 1.30 | Shifts starting 20:00–05:59 |
| `weekend` | 1.25 | Saturday and Sunday |
| `bank_holiday` | 2.00 | Listed UK bank holidays |
| `overtime` | 1.50 | Hours beyond 40 in an ISO week |

The `meta` column holds the descriptions above as free text. They are
documentation, not logic — the thresholds themselves are hardcoded in
`payroll.php`, so editing `meta` changes nothing.

### Bank holidays — 8 rows (`schema.sql:444`)

**2026 only**: New Year's Day, Good Friday, Easter Monday, Early May, Spring,
Summer, Christmas Day, Boxing Day (substitute).

The calendar runs out at the end of 2026. From 1 January 2027 every bank holiday
silently stops attracting the 2.00 multiplier, with no error and no warning —
payroll simply pays the base rate. This needs a scheduled top-up.

### Skills — 11 rows (`schema.sql:744`)

Care: Dementia care, Medication trained, Manual handling, Senior carer.
Security: Door supervision, CCTV operator, ACS vetted.
Cleaning: COSHH trained, Deep cleaning.
General: First aid, Driver.

No skills are seeded in the `academy` category, though the ENUM allows it.

### Demo certificate — 1 row (`schema.sql:324`)

One certificate, public id `RG-2026-00123`, learner "Jane Doe", course
"Health & Social Care (Level 3)", issued 2026-03-15, status `issued`. It exists
so the public `/verify` page can be tested (`schema.sql:323`), and it is live on
production unless someone deleted it.

---

## 6. Known schema issues

### 6.1 The division ENUM is copy-pasted into thirteen tables

`ENUM('care','academy','cleaning','security')` is repeated verbatim as a
hardcoded column type in **thirteen** tables. Adding a fifth division, renaming
one, or retiring one is an `ALTER TABLE` against every one of them, in the right
order, on a live database.

| Table | Column | Line |
|---|---|---|
| `users` | `division` | `schema.sql:38` |
| `workers` | `division` | `schema.sql:126` |
| `programmes` | `division` | `schema.sql:168` |
| `invoices` | `division` | `schema.sql:204` |
| `contracts` | `division` | `schema.sql:251` |
| `quotes` | `division` | `schema.sql:274` |
| `jobs` | `division` | `schema.sql:295` |
| `shifts` | `division` | `schema.sql:486` |
| `incidents` | `division` | `schema.sql:560` |
| `applicants` | `division` | `schema.sql:618` |
| `rate_cards` | `division` | `schema.sql:686` |
| `service_requests` | `division` | `schema.sql:701` |
| `shift_templates` | `division` | `schema.sql:777` |

Three further columns are the same list with an extra member, which makes the
problem worse rather than better — they are near-duplicates that will drift
independently:

| Table | Column | Extra member | Line |
|---|---|---|---|
| `contact_enquiries` | `division` | `'other'` | `schema.sql:157` |
| `announcements` | `audience` | `'all'` | `schema.sql:238` |
| `skills` | `category` | `'general'` | `schema.sql:740` |

That is sixteen places to touch. The type is also duplicated a seventeenth time
in TypeScript as `DivisionId` (`src/lib/types.ts:3`), which no tooling checks
against the SQL.

A `divisions` reference table with foreign keys, mirroring how `roles` is done
at `schema.sql:17`, would reduce this to one row insert. Note that `roles`
already demonstrates the problem in miniature: the reference table exists, but
`users.role` (`schema.sql:37`) is still a hardcoded ENUM with no foreign key to
it, so the two must be kept in step by hand.

### 6.2 `clients.divisions` is a comma-separated string

`clients.divisions VARCHAR(120) NOT NULL DEFAULT ''` (`schema.sql:99`) stores a
client's divisions as a delimited list, e.g. `care,cleaning`.

- It cannot be joined, indexed or constrained. "All clients in the security
  division" is a `LIKE '%security%'` scan, which also matches nothing sensible
  if a division is ever renamed to a substring of another.
- Nothing prevents an invalid value, a duplicate, or stray whitespace.
- Renaming a division means a string rewrite across every row.

`worker_skills` (`schema.sql:757`) shows the correct pattern already exists in
this schema. `clients.divisions` should be a `client_divisions` junction table.

The TypeScript type documents the workaround rather than fixing it:
`divisions: string // comma-separated division ids` (`src/lib/types.ts:12`).

### 6.3 Tables lacking `deleted_at` that arguably need it

Eleven tables carry `deleted_at` in the baseline: `users`, `clients`, `sites`, `workers`,
`contracts`, `jobs`, `certificates`, `files`, `insurance`, `blog_posts` and `testimonials`.
Migration 013 added five more (`applicants`, `invoices`, `incidents`, `timesheets`, `shifts`),
and migration 020 three more that hold public-intake personal data and previously had no way to
be archived at all: `contact_enquiries`, `service_requests` and `integration_submissions`. The
rest are hard-delete only.

The ones where that is a real problem:

| Table | Why it matters |
|---|---|
| `invoices` | A financial record. `void` covers cancellation, but an accidental `DELETE` is unrecoverable, and it **cascades to `credit_notes`** (`schema.sql:475`), destroying the credit trail with it. |
| `quotes` | Commercial record with a unique document number from `counters`; deleting one leaves a permanent gap with no record of why. |
| `credit_notes` | A refund record. Cascade-deleted along with its invoice. |
| `timesheets` | The evidentiary basis for what a worker was paid. Soft delete (013) and `updated_at` (014) exist; migration 021 adds `decided_at` and `decision_note`, so a decision now records who, when and why. An amendment to the times themselves still leaves no before-and-after. |
| `incidents` | Accident, RIDDOR and safeguarding records carry statutory retention duties. A hard delete is a compliance failure, and it **cascades to `incident_actions`** (`schema.sql:591`). |
| `certifications` | Evidence that a worker was cleared to work on a given date. Cascade-deleted with the worker (`schema.sql:148`). |
| `worker_references` | Vetting evidence. Cascade-deleted with the worker (`schema.sql:401`). |
| `applicants` | Holds personal data; needs a deletion story either way, and `onboarding_tasks` cascades from it. |
| `shifts` | The operational record a timesheet is checked against. Cascade-deleted with the worker, taking `shift_events` and `shift_offers` with it. |
| `programmes` | Cascade-deletes its `enrolments` (`schema.sql:182`), destroying learner history. |
| `audit_log` | Deliberately append-only, so no `deleted_at` is correct — but there is also no protection against a plain `DELETE`. |

The pattern is that soft delete was applied to the entities a user can click
"delete" on in the UI, and not to the records those entities cascade into. The
cascades are where the data loss actually happens.

### 6.4 Missing and inconsistent foreign keys

**`shifts.client_id` has no foreign key.** `schema.sql:485` declares
`client_id INT NULL` and the table's only constraint is `fk_shifts_worker`
(`schema.sql:496`). Compare `shift_templates.client_id` at `schema.sql:778`,
which has `fk_tmpl_client ... ON DELETE SET NULL` (`schema.sql:786`) — the same
column, in a directly related table, in the same build pass, constrained
correctly. `scheduling.php:207` does `LEFT JOIN clients c ON c.id = s.client_id`,
so the join is real; only the constraint is missing. Deleting a client therefore
leaves shifts pointing at a client id that no longer exists, and the join
silently returns `NULL` for the client name. There is no index on the column
either.

Other columns that name a foreign entity but declare no constraint:

| Column | Should reference | Line | Note |
|---|---|---|---|
| `users.client_id` | `clients(id)` | `schema.sql:553` | Added by `ALTER`; gates client-portal access |
| `users.worker_id` | `workers(id)` | `schema.sql:554` | Added by `ALTER`; gates worker-app access |
| `clients.account_manager_id` | `users(id)` | `schema.sql:679` | Added by `ALTER` |
| `audit_log.user_id` | `users(id)` | `schema.sql:66` | Joined at `audit.php:12`; also has no index |
| `incident_actions.created_by` | `users(id)` | `schema.sql:588` | `credit_notes.created_by` at `schema.sql:471` does have one |
| `service_requests.shift_id` | `shifts(id)` | `schema.sql:707` | The whole point of the column |
| `enrolments.certificate_public_id` | `certificates(public_id)` | `schema.sql:179` | String reference; the target is unique, so a constraint is possible |
| `blog_posts.author_id` | `users(id)` | `schema.sql:842` | |
| `testimonials.author_id` | `users(id)` | `schema.sql:859` | |

Four of these (`users.client_id`, `users.worker_id`,
`clients.account_manager_id`, and the `workers`/`invoices`/`tokens` additions)
arrived through `ADD COLUMN IF NOT EXISTS`, which cannot add a constraint in the
same statement. That is the mechanical cause: **every column added by a later
`ALTER` in this file is unconstrained**, and every column present in an original
`CREATE` generally is constrained. The build-pass format produced the gap.

Inconsistent `ON DELETE` behaviour for the same relationship also appears:

- `shifts.worker_id` → **CASCADE** (`schema.sql:496`), but
  `sessions.worker_id` → SET NULL (`schema.sql:196`),
  `files.worker_id` → SET NULL (`schema.sql:345`),
  `incidents.worker_id` → SET NULL (`schema.sql:579`),
  `shift_events.worker_id` → SET NULL (`schema.sql:608`),
  `applicants.worker_id` → SET NULL (`schema.sql:626`).
  Six references to the same parent, five SET NULL and one CASCADE. The CASCADE
  is on the operational history.
- `sessions.programme_id` is nullable but **CASCADE**s (`schema.sql:195`), which
  contradicts the nullability.

Free-text columns that duplicate a real relationship rather than referencing it:
`shifts.site_name` (`schema.sql:488`), `incidents.site_name`
(`schema.sql:566`), `service_requests.site_name` (`schema.sql:702`) and
`shift_templates.site_name` (`schema.sql:779`) are all `VARCHAR(190)` while a
`sites` table exists (`schema.sql:107`) and is never joined to any of them.
Likewise `certificates.course` (`schema.sql:315`) and
`enrolments.learner_name` (`schema.sql:177`) are free text where
`programmes` and a person record exist.

### 6.5 Two tables are created by their endpoints; forty-eight are not

`blog_posts` and `testimonials` are each defined **twice**: once in `schema.sql`
(`831` and `851`), and once as a `CREATE TABLE IF NOT EXISTS` executed on every
request by `server/blog.php:18` and `server/testimonials.php:16`. The comment at
`blog.php:16-17` calls it a "first-run migration ... lets the endpoint be
deployed without a manual schema step."

The other forty-eight tables require importing `schema.sql` by hand through
phpMyAdmin (`schema.sql:2`). So the project has two different, undocumented
provisioning paths, and which one a table uses is not discoverable without
reading the PHP.

Concrete problems:

- **The two definitions can drift.** They are byte-identical today; nothing
  checks that. A change to `schema.sql` that is not mirrored into the endpoint
  will not take effect on a database that was provisioned by the endpoint, and
  vice versa. Because both use `IF NOT EXISTS`, the drift is silent — the second
  definition is simply skipped.
- **A `CREATE TABLE` runs on every single request**, including every anonymous
  hit on the public blog. It is cheap but not free, and it means the database
  user the web app runs as must hold DDL privileges permanently, which is a
  larger grant than the application otherwise needs.
- **Neither endpoint can add a column later.** `CREATE TABLE IF NOT EXISTS` is a
  no-op once the table exists, so the pattern only ever handles first run.

Either extend the self-creating pattern to everything, or drop it and make
importing `schema.sql` the single documented step. The middle state is the worst
of both.

### 6.6 Where the TypeScript types and the SQL disagree

`src/lib/types.ts` is described as mirroring the schema (`src/lib/types.ts:1`).
Most of it does — I checked each type against the PHP that produces it, and the
casts in `workers.php:21-27`, `users.php:27` and `incidents.php:28` mean the
declared shapes are generally honest. The disagreements below are real.

**a. `DashboardSummary.open_shifts` is computed from the wrong table.**
`src/lib/types.ts:553` declares `open_shifts: number` alongside
`upcoming_sessions`. Both are filled from the **same** table:

```
dashboard.php:27  SELECT COUNT(*) FROM sessions WHERE starts_at > NOW()
dashboard.php:29  SELECT COUNT(*) FROM sessions WHERE worker_id IS NULL AND starts_at > NOW()
```

Line 29 counts unassigned rows in `sessions` — the *training session* table
(`schema.sql:185`) — not `shifts` (`schema.sql:482`), which is where open shifts
live and which has an explicit `status = 'open'` for exactly this. The dashboard
tile reports training sessions with no trainer assigned and labels them open
shifts. This is a bug in `dashboard.php`, not in the type.

**b. `AdminUser.active` has no backing column.** `src/lib/types.ts:378` declares
`active: boolean`, but `users` has no `active` column. It is derived:
`(u.deleted_at IS NULL) AS active` at `users.php:18`, cast at `users.php:27`.
The type is correct about the API response and misleading about the schema —
worth a comment, since anyone reading `types.ts` as a schema mirror will look
for the column.

**c. `TINYINT(1)` maps to `boolean` in some types and `number` in others.**
The SQL type is identical in every case; the TypeScript is not:

| TS field | TS type | Source column | Line |
|---|---|---|---|
| `Worker.right_to_work` | `boolean` | `workers.right_to_work` | `src/lib/types.ts:61` |
| `Worker.avail_days` etc. | `boolean` | `workers.avail_*` | `src/lib/types.ts:71-73` |
| `ShiftTemplate.active` | `boolean` | `shift_templates.active` | `src/lib/types.ts:102` |
| `Incident.riddor` | `number` | `incidents.riddor` | `src/lib/types.ts:185` |
| `Incident.safeguarding` | `number` | `incidents.safeguarding` | `src/lib/types.ts:186` |
| `IncidentAction.done` | `number` | `incident_actions.done` | `src/lib/types.ts:193` |
| `OnboardingTask.done` | `number` | `onboarding_tasks.done` | `src/lib/types.ts:234` |

Both are accurate to their endpoints — `workers.php:21` casts to `bool` while
`incidents.php:28` and `ats.php:77` cast to `int`. So this is a genuine
inconsistency in the API, faithfully mirrored into the types. `if (a.riddor)`
and `if (a.done)` happen to work, but any strict comparison or serialisation
differs by table, and a developer switching between them will get it wrong.

**d. Types are missing for several tables that the app reads.** There is no
TypeScript type for `certificates` (`schema.sql:311`) despite a public verify
page, none for `sites` (`schema.sql:107`) beyond `Client.sites_count`
(`src/lib/types.ts:18`), and none for `sessions` (`schema.sql:185`),
`mandates` (`schema.sql:516`) or `dd_payments` (`schema.sql:531`). Those
responses are untyped at the boundary.

**e. `Enquiry.division` is widened to `string`.** `src/lib/types.ts:366` types it
as `string` where the column is
`ENUM('care','academy','cleaning','security','other')` (`schema.sql:157`).
Widening is safe, but it also means `contact.php:29`'s coercion of unknown values
to `other` is invisible to the type system. Same for `AdminUser.division`
(`src/lib/types.ts:377`), `Client.type` and `Client.status`
(`src/lib/types.ts:7,13`), and `Worker.role`/`Worker.status`
(`src/lib/types.ts:58,60`) — all typed `string` with the permitted values in a
trailing comment, while the equivalent fields elsewhere in the same file are
proper unions.

**f. `Insurance.cover_pence` is a `number` over a `BIGINT`.** `schema.sql:377`
declares BIGINT, `src/lib/types.ts:259` types it `number`. Safe in practice —
JavaScript is exact to 2^53, far beyond any plausible cover amount — but it is
the one place the schema deliberately exceeds INT, and the type does not record
why.

### 6.7 Other issues found

**`invoices.pay_token` has no index.** The column is added at `schema.sql:512`
with no key, and `payments.php:127` looks an invoice up by it:
`... FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.pay_token = ?`.
That is a full table scan on an unauthenticated public endpoint, which gets
slower as the invoice table grows and is a soft denial-of-service target. It
should also be `UNIQUE` — nothing currently prevents two invoices sharing a pay
token, which would let one customer open another's invoice.

**Money columns are signed `INT`, capping at £21,474,836.47.** Applies to
`invoices.net_pence`/`vat_pence`/`total_pence` (`schema.sql:205-207`),
`quotes` (`schema.sql:276-278`), `contracts.rate_pence` (`schema.sql:254`),
`credit_notes.amount_pence` (`schema.sql:469`),
`dd_payments.amount_pence` (`schema.sql:536`),
`rate_cards.charge_pence` (`schema.sql:688`) and
`workers.pay_rate_pence` (`schema.sql:418`). `insurance.cover_pence` is BIGINT
(`schema.sql:377`) because £5m cover was an obvious overflow. The cap is
comfortable for line items and less so for an annual aggregate; nothing enforces
non-negativity either, so a negative invoice total is representable.

**No unique key on `rate_cards(client_id, division, role)`** (`schema.sql:683`).
Duplicate, contradictory rate cards can coexist for one client, and which one
wins depends on row order.

**`compliance_reminders` dedup is an index, not a unique key.**
`KEY idx_cred_tier (credential_key, tier)` at `schema.sql:366` is what stops a
60-day nudge firing twice (`schema.sql:358-359`), but an index enforces nothing.
A concurrent or retried digest run can email twice. `UNIQUE` would make the
guarantee structural.

**`dd_payments.gc_payment_id` and `mandates.gc_mandate_id` are not unique**
(`schema.sql:535`, `schema.sql:520`). These are external provider ids arriving
by webhook. Without a unique key, a replayed or duplicated webhook inserts a
second payment row for the same real-world payment.

~~**`bank_holidays` runs out at the end of 2026.**~~ Fixed by migration 023,
which seeds to the end of 2035 from the tested `rg_bank_holidays()` generator.

~~**No expiry on `shift_offers`.**~~ Fixed by migration 027: `expires_at` is set
to the shift's start when the offer is created, `offer.php` refuses an expired
link with 410, and a unique key on `(shift_id, worker_id)` means a re-broadcast
refreshes the existing link rather than sending a second live one.

~~**`notifications` cannot track per-user read state for a broadcast.**~~ Fixed
by migration 025 (`notification_reads`). The original note, for the record: A
`user_id IS NULL` row (`schema.sql:808`) has one `read_at` shared by everyone.

**`shift_templates.weekday TINYINT` is not range-constrained**
(`schema.sql:780`). The comment says 1–7 ISO; the column accepts −128 to 127.
Same for `testimonials.rating TINYINT DEFAULT 5` (`schema.sql:855`), which
accepts any value a five-star widget will then try to render.

**`user_2fa.secret` is stored in plaintext** (`schema.sql:647`). A read of that
one table is enough to mint valid TOTP codes for every user, which removes the
second factor entirely. It should be encrypted with a key held outside the
database. There is also no recovery-code table, so a lost authenticator needs
manual intervention.

**`sms_log` stores recipient numbers and message bodies indefinitely**
(`schema.sql:820`) with no retention policy anywhere in the schema. Message
bodies routinely contain names, addresses and shift details.

**`counters` has no protection against reuse.** `next_seq()` is atomic
(`schema.sql:74-75`), but nothing links a counter to the table it numbers, so
resetting or mis-keying one produces duplicate document numbers that the
`UNIQUE` keys on `invoices.number`, `quotes.number` and `credit_notes.number`
will then reject at insert time rather than at allocation time.

**The `items` table is dead** (`schema.sql:10`). A smoke test in the production
schema that nothing reads.

**Missing indexes on columns the app filters by.** `clients` has no index at all
beyond its primary key, despite `deleted_at IS NULL` appearing in essentially
every query against it. `workers` likewise has none, despite the expiry digest
scanning `dbs_expiry` and `sia_expiry`. `shifts.status` and `shifts.client_id`
are unindexed. `audit_log.user_id` is unindexed and joined.

**Index names are reused across tables** — `idx_status` on `invoices`,
`contracts`, `quotes` and `jobs`; `idx_client` on `sites` and `jobs`. This is
legal in MySQL, where index names are per-table, but it makes any global
`information_schema` query about indexes ambiguous to read.

---

# Migrations — applied and verified locally

Everything below lives in `server/migrations/`. It has been **applied to a real MariaDB
database and verified end to end**: schema imported, migrations applied, fixtures seeded,
backfill run, the API exercised against the result.

What was verified: 22 people created from 23 source records with no duplicates; the UNIQUE
`match_key` genuinely refuses a second person with the same identity (error 1062); migration
004's precondition genuinely blocks on an unlinked row and reports the count.

It has **not** been applied to production. Apply with `php server/migrate.php` (dry run) then
`--go`. See `docs/ARCHITECTURE.md` §7a for the ordering.

## `people` — migration 001

The single human identity. Candidate, worker, learner and login become roles a person holds.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK | |
| `full_name` | VARCHAR(160) | |
| `preferred_name` | VARCHAR(80) NULL | |
| `date_of_birth` | DATE NULL | |
| `email` | VARCHAR(190) NULL | indexed |
| `phone` | VARCHAR(40) NULL | indexed |
| `address_line1`, `address_line2`, `city`, `postcode` | VARCHAR NULL | |
| `emergency_contact_name` / `_phone` / `_relationship` | VARCHAR NULL | |
| `match_key` | VARCHAR(200) NULL | **UNIQUE.** `e:<email>` or `p:<digits>`, from `rg_person_match_key()`. The database itself refuses a second person with the same key — the guarantee the old copy-on-onboard behaviour could not make. NULL where a person has neither an email nor a usable phone; MySQL permits many NULLs in a UNIQUE index |
| `notes` | TEXT NULL | |
| `created_at`, `updated_at`, `deleted_at` | | soft delete |

## `person_services` — migration 001

Which services a person may work in, and whether they currently may. This is what
`workers.division` could never express: one person eligible for Security and Cleaning at once,
with a different answer for each.

| Column | Type | Notes |
|---|---|---|
| `person_id` | INT | PK part, FK to `people` ON DELETE CASCADE |
| `service` | ENUM(care, academy, cleaning, security) | PK part |
| `active` | TINYINT(1) | whether the business intends this person for this service — separate from whether they currently may |
| `eligibility_status` | ENUM(eligible, eligible_with_warnings, not_eligible, unassessed) | **calculated, never set by hand** |
| `eligibility_reason` | VARCHAR(500) NULL | why, in words |
| `eligibility_score` | TINYINT UNSIGNED NULL | |
| `assessed_at` | DATETIME NULL | |

## Columns added to existing tables — migration 001

`person_id INT NULL` on `workers`, `applicants`, `users` and `enrolments`, each indexed.
Nullable, because the backfill has not run and because an unmatched row must stay visible for
review rather than being dropped.

`workers.payroll_no VARCHAR(20) NULL UNIQUE` — unique, permanent, never reused. It belongs to
the employment record rather than the person, because a person may hold other roles without
ever being employed.

## Foreign keys — migration 002

`fk_workers_person`, `fk_applicants_person`, `fk_users_person`, `fk_enrolments_person`, all
`ON DELETE RESTRICT` — because removing a person goes through the deletion-approval workflow,
never a cascade that takes their employment history with it.

**A foreign key does not force every row to be linked.** It constrains non-NULL values only, so
unlinked rows pass through it untouched. This was assumed otherwise and corrected after testing:
002 applied cleanly with an unlinked row present.

## Requiring the link — migration 004

`person_id` becomes `NOT NULL` on `workers`, `applicants` and `users`. This is the migration
that actually enforces the spine, and it carries a precondition the runner checks first, so it
stops with a count and a remedy rather than failing mid-chain.

It sets `STRICT_ALL_TABLES` for its own session rather than trusting the server's `sql_mode`:
without strict mode MySQL coerces every unlinked `person_id` to 0, leaving the spine quietly
wrong instead of loudly unfinished.

**Once 004 is applied, anything that inserts one of these rows must supply a `person_id`.**
`server/seed.php` did not, and stopped working entirely against a migrated database — the
documented local setup path, broken by a migration nobody thought to re-check it against. Every
insert of a worker, applicant or user goes through `find_or_create_person()`.

`enrolments` is deliberately excluded — it carries only a `learner_name`, with no email or
phone to match on, so those rows are linked by hand as the Academy module is rebuilt. Forcing
`NOT NULL` there would mean inventing links.

## `compliance_requirements` — migration 003

The catalogue of everything that can be required. Seeded with 13 entries: right to work,
identity, DBS, SIA licence, references, contract, mandatory training, Care Certificate, first
aid, moving and handling, COSHH, conflict management, NMC registration.

| Column | Type | Notes |
|---|---|---|
| `req_key` | VARCHAR(64) UNIQUE | matches the key the evaluator expects |
| `label`, `description` | | shown to people |
| `evidence_source` | ENUM | where to load the evidence from |
| `expires` | TINYINT(1) | a requirement that should expire but has no date is incomplete evidence, not valid evidence |
| `requires_verification` | TINYINT(1) | an unverified document is a claim |
| `active` | TINYINT(1) | |

## `compliance_rules` — migration 003

What each service requires, and how strongly. `worker_role NULL` applies to every role in the
service; a row naming a role sits alongside it.

| Column | Type | Notes |
|---|---|---|
| `service` | ENUM | |
| `worker_role` | ENUM NULL | NULL = all roles |
| `requirement_id` | INT | FK, CASCADE |
| `weight` | ENUM(mandatory, recommended, optional) | mandatory blocks; recommended warns; optional is recorded only |

UNIQUE on `(service, worker_role, requirement_id)`.

**Seeded to reproduce what `server/scheduling.php` already enforces in code** — right to work
everywhere, DBS for Care, SIA for Security — so wiring the engine in changes no behaviour on
day one. Everything beyond that starts as `recommended`, so the business raises a requirement
to mandatory deliberately rather than discovering overnight that half the workforce is blocked.


## `data_requests` — migration 020

A person's route to ask about their own data, and the record of what was done about it. Written
by `server/data-request.php`; read by the same file's staff queue.

| Column | Type | Notes |
|---|---|---|
| `ref` | VARCHAR(24) | `DSR-NNNN` from `next_seq()`. UNIQUE |
| `kind` | ENUM | access, correction, deletion, objection, restriction, portability, withdraw_consent, other |
| `name`, `email` | VARCHAR | The requester, as they gave it |
| `relationship` | VARCHAR(120) NULL | In their own words: worker, applicant, client contact. Tells staff where to look |
| `detail` | TEXT NULL | Optional |
| `status` | ENUM | received, verifying, in_progress, completed, refused, withdrawn |
| `acknowledged_at`, `completed_at` | DATETIME NULL | Set as the request moves |
| `outcome_note` | VARCHAR(1000) NULL | Required, ten characters minimum, to close as completed or refused |
| `handled_by` | INT NULL | FK to `users`, SET NULL |
| `ip` | VARCHAR(45) NULL | Who submitted it |

**No identity documents.** A public form is the wrong place to collect a passport scan, and
gathering more personal data to answer a question about personal data is the wrong instinct.
Verification happens afterwards, over the address on the request.

**No deadline in the schema.** How long a response takes, and how it is counted, is the
business's to state with advice; a number written here would be trusted and would go stale.

## `consent_log` — index added by migration 020

`ADD INDEX idx_consent_email_kind (email, kind, id)`. Marketing consent is the latest row for
`kind = 'marketing'`, checked before every send, so it has to be a lookup and not a scan. No new
table: an opt-out is a row with `granted = 0`, never a deletion — erasing the record that
somebody unsubscribed is how they get signed up again by the next import.


## `applicants.stage` — widened by migration 031

`ENUM('applied','screened','interview','vetting','offer','onboarding','onboarded','rejected')`.

`onboarding` is new, and sits between `offer` and `onboarded`. `ats.php?action=onboard` creates
a pending worker and a checklist of unfinished tasks, and it used to set the stage to
`onboarded` in the same transaction — so the pipeline said a candidate was through onboarding at
the moment none of it had been done, and no stage was left to mean cleared and working.

The migration reclassifies existing rows by what is true of them rather than in bulk: an
`onboarded` row with outstanding tasks, or with no checklist at all, becomes `onboarding`; one
whose checklist is finished keeps the word, because the word is then accurate. The checklist is
the evidence and is untouched either way.

Reaching `onboarded` is the activation step's job, and activation is Phase 3 of
`docs/LEAN-CMS-LAUNCH-PLAN.md`. Until it exists, nothing writes `onboarded` automatically.

## `counters.payroll` — seeded by migration 032

Not a new table. `next_payroll_no()` formats `next_seq($conn, 'payroll')`, and `next_seq()`
starts a counter it has never seen at 1. `workers.payroll_no` is UNIQUE (migration 001).

On any database that already holds numbered workers and has no `payroll` row in `counters` —
one where the numbers predate the counter, or one restored from a dump without it — the next
worker created would be offered `RG-000001`, the unique key would refuse it, and the conversion
would fail with a 500 that explained nothing. The migration raises the counter to the highest
number already in use. It never lowers it and never renumbers anybody, so re-running changes
nothing.

`server/backfill-payroll.php` fills in workers that have no number at all: report by default,
`--go` to allocate, safe to run twice, and it refuses to start if the counter is behind a number
already in use. Archived workers are included — a leaver's payroll number is still cited by the
records of the work they did.


## Recruitment detail — migration 033

Four additions. Not a workflow engine: the stage model stays a fixed list in
`RG_STAGES` / `RG_STAGE_EXITS` in `server/pure.php`.

**`applicants.recruiter_id`** `INT NULL`, FK → `users(id)` **ON DELETE SET NULL**. Who is dealing
with this candidate, which was previously answered in somebody's head. `SET NULL` rather than
cascade: a recruiter leaving must not take their candidates with them.

**`applicants.outcome_reason`** `VARCHAR(500) NULL`. Why they left the pipeline. Cleared when they
come back into it, because then they did not leave.

**`applicants.stage`** gains `withdrawn`. A candidate who takes another job is not a candidate the
company turned down, and recording both as `rejected` loses the difference between a pipeline
problem and a market one.

**`applicant_stage_history`** — `from_stage`, `to_stage`, `reason`, `changed_by`, `changed_at`.
`audit_log` already records the change and is the wrong place to read it from: it is a security
record spanning every table, and a recruiter looking at one candidate should not be handed a tool
that pages through everybody's. Existing applications are backfilled with their current stage,
`changed_by` NULL — nobody can now say who moved them, and inventing an attribution is worse than
admitting there is none.

**`applicant_interviews`** — `scheduled_at` (wall-clock DATETIME, like `shifts.starts_at`),
`interviewer_id`, `location`, `outcome` ∈ scheduled/passed/failed/no_show/cancelled, `notes`,
`decided_at`, `decided_by`, `deleted_at`. A cancelled interview is recorded as cancelled, not
erased.

**`onboarding_tasks`** gains `owner_id`, `due_on`, `done_at`, `done_by`. A checklist nobody owns
and nothing is due on cannot be chased, which is the only thing a checklist is for. Un-ticking a
task clears `done_at` and `done_by`: "Right to work verified" with nobody's name against it is not
evidence that anybody verified anything. Tasks already marked done keep NULL — `NOW()` would be a
lie about when the work happened.

## `feature_switches`: migration 036

Which optional areas of the system the owner has switched on. One row per switch; the names
are fixed in code (`rg_feature_defaults()` in `server/pure.php`) and `server/features.php` can
set a switch but not create one. Created by the migration only, not in `schema.sql`.

| Column | Type | Notes |
|---|---|---|
| `name` | VARCHAR(40) PK | `finance`, `online_payments`, `sms_whatsapp`, `inbox`, `ai_assistant` |
| `enabled` | TINYINT(1) NOT NULL | |
| `updated_by` | INT NULL | FK → `users(id)` **ON DELETE SET NULL**. NULL for a seeded row nobody has changed |
| `updated_at` | DATETIME NULL | UTC, set on change |

Seeded with `INSERT IGNORE`, so re-running never undoes the owner's choice: everything on
except `ai_assistant`, which the 2026 plan retires. That preserves what production did before
the table existed. A missing row, or a missing table on a server whose PHP is ahead of its
migrations, reads as the same default. What each switch does when off is in `docs/API.md`
§54c; `finance` only changes the navigation.

## The person profile: migration 037

Re-runnable throughout. Applied locally by the database suite; **not** applied to production.

**`people.emergency_contact2_name`** `VARCHAR(160) NULL`, **`emergency_contact2_phone`**
`VARCHAR(40) NULL`, **`emergency_contact2_relationship`** `VARCHAR(80) NULL`. A second emergency
contact beside the first from migration 001. Both are written only through
`rg_save_emergency_contacts()` in `server/people-lib.php`, which validates them with
`rg_validate_emergency_contacts()` and audits the names of the changed fields, never the values.

**`workers.suspended_at`** `DATETIME NULL`, **`suspended_by`** `INT NULL`,
**`suspension_reason`** `VARCHAR(255) NULL`. Who suspended a worker, when and why, written by
`workers.php?action=suspend` and cleared by `reinstate`. No foreign key on `suspended_by`, so the
migration stays re-runnable (MySQL has no `ADD CONSTRAINT IF NOT EXISTS`).

**`onboarding_template_items`**: `id`, `service` (the division ENUM), `label` `VARCHAR(160)`
(the same width as `onboarding_tasks.label`, which it becomes), `sort_order`, `active`,
`created_by` FK → `users(id)` **ON DELETE SET NULL**, `created_at`, `updated_at`.
`UNIQUE (service, label)`. One table keyed by service rather than a template table and an item
table, because there is exactly one checklist per service. Items are retired with `active = 0`,
never deleted. Seeded with defaults for each service from the checks in
`docs/Plans 2026/01-BUSINESS-AND-SCOPE.md`; checks that apply only to some people (an NMC PIN,
a DBS for a cleaner on some sites, first aid where required) are left for the office to add to
the one person, because a task that does not apply cannot be finished and would block approval.

**`enrolments.learner_name`** becomes `NULL`-able. A training record added from the person
profile links through `enrolments.person_id` (migration 001) and reads the name from `people`;
copying the name into this row as well would be a second copy of the person.

## The rota: migration 038

Applied locally by the database suite; **not** applied to production. Re-runnable apart from
`fk_shifts_site`, which MySQL cannot add `IF NOT EXISTS` (as with migrations 027 and 029).
`schema.sql` carries a pointer to it, not a copy.

**`shifts.is_draft`** `TINYINT(1) NOT NULL DEFAULT 0`. Whether workers can see the shift.
`status` keeps its four values and its meaning (who is on it; whether it happened); being a
draft is a separate fact. The default is 0 so every shift that already existed counts as
published, which they all were; every office path that creates a shift sets 1 explicitly.
`rg_shift_state()` derives the word the rota shows (`draft`, `open`, `offered`, `assigned`,
`completed`, `cancelled`). Indexed `(is_draft, starts_at)`.
**`published_at`** `DATETIME NULL`, **`published_by`** `INT NULL`: set when a draft is
published; NULL on shifts that predate drafts.
**`cancel_reason`** `VARCHAR(255) NULL`, **`cancelled_at`** `DATETIME NULL`,
**`cancelled_by`** `INT NULL`: a cancellation needs a reason, and it is what the worker is told.
**`site_id`** `INT NULL`, FK `fk_shifts_site` → `sites(id)` **ON DELETE SET NULL**, indexed
`(site_id, starts_at)`. Backfilled where the shift's client and `site_name` match a site exactly.
`site_name` stays: it is what older shifts carry and it is history.

**`sites`** gains `postcode` `VARCHAR(12)`, `access_notes` `VARCHAR(1000)`, `contact_name`
`VARCHAR(160)`, `contact_phone` `VARCHAR(40)` and `active` `TINYINT(1) NOT NULL DEFAULT 1`. A
closed site takes no new shifts and is kept, because shifts point at it.

**`site_requirements`**: `site_id` FK → `sites(id)` **ON DELETE CASCADE**, `requirement_id` FK →
`compliance_requirements(id)` **ON DELETE CASCADE**, `created_by`, `created_at`; primary key
`(site_id, requirement_id)`. What a site insists on beyond the service's own rules.
`rg_load_rules($conn, $service, $role, $siteId)` adds these as mandatory rules, so the same
evaluator decides; the site verdict is never written to `person_services`.

**`availability_blocks`**: `id`, `person_id` FK → `people(id)` **ON DELETE CASCADE**,
`starts_at`, `ends_at` (wall-clock DATETIME, like shifts), `note` `VARCHAR(255)`, `created_by` FK
→ `users(id)` **ON DELETE SET NULL**, `created_at`, `deleted_at`. A period the worker has marked
unavailable: a warning the office acknowledges, never a block. Keyed by person, because somebody
unavailable is unavailable for every service.

**`app_settings`**: `name` `VARCHAR(60)` PK, `value` `VARCHAR(255)`, `updated_by` FK →
`users(id)` **ON DELETE SET NULL**, `updated_at`. Numbers the owner may change; the names and
defaults are fixed in `rg_setting_defaults()`, and a value that is not a whole number or is out of
bounds reads as the default. Seeded `rest_gap_min_hours = 11` with `INSERT IGNORE`.

**`timesheets.status`** becomes `ENUM('draft','pending','approved','rejected','queried')`.
`draft` is made from a clock-in and clock-out (`rg_timesheet_from_clock()`), `queried` is the
office asking the worker (the reason in `decision_note`). `pending` keeps its meaning, submitted
and waiting, so every existing row keeps its place. Only `approved` is paid.
**`source`** `ENUM('office','clock') NOT NULL DEFAULT 'office'` and **`submitted_at`**
`DATETIME NULL`. Corrections are not a table: each one is an audit row with the values before
and after and the reason (`timesheets.php?action=correct`), which is the entry's history.

**`service_requests.owner_id`** and **`contact_enquiries.owner_id`** `INT NULL`, indexed. Who in
the office owns the request (`requests.php`). No foreign key: users are archived, not deleted.

## Careers, forms and company details: migration 039

Re-runnable throughout (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`). Created by the
migration only, not in `schema.sql`. Applied locally by the database suite; **not** applied to
production.

**`website_forms`**: the forms directory. `id`, `title` `VARCHAR(160)`, `purpose`
`ENUM('recruitment','training','other')`, `service` ENUM of the four services, NULL for any,
`google_form_id` `VARCHAR(200)` (the id only, never the pasted address; checked by
`rg_parse_google_form()`), `display` `ENUM('embedded','link')`, `active` `TINYINT(1)`, `owner_id`
FK → `users(id)` **ON DELETE SET NULL**, `integration_id` FK → `integrations(id)` **ON DELETE SET
NULL** (the intake key its Apps Script posts to), `last_reviewed_at` `DATE` (set to today on
create and by "reviewed"), `created_by` FK → `users(id)`, `created_at`, `updated_at`,
`deleted_at`. Previous values are kept in `audit_log` (`website_form.update`), not a history
table.

**`vacancies`**: roles for `/careers`. `id`, `slug` `VARCHAR(80)` UNIQUE (fixed once
`published_at` is set), `title`, `service` ENUM NOT NULL, `location`, `hours_text`, `pay_text`
`VARCHAR(160) NULL` (free text, optional), `summary` `TEXT NOT NULL`, `requirements`, `checks`
(`TEXT`, one item per line; `checks` prefilled per service), `status`
`ENUM('draft','published','closed')`, `form_id` FK → `website_forms(id)` **ON DELETE SET NULL**,
`owner_id` FK → `users(id)` **ON DELETE SET NULL**, `published_at` (first publication, kept on
reopening), `closed_at`, `created_by`, `created_at`, `updated_at`, `deleted_at` (archive).

**`company_settings`**: one row, `id = 1`. `trading_name`, `legal_name`, `company_no`, `phone`,
`urgent_phone`, `email`, `office_hours`, `area`, `registered_address`, `instagram_url`,
`facebook_url`, `linkedin_url`, `tiktok_url`, `whatsapp_url` (empty means "not shown"),
`updated_by` FK → `users(id)` **ON DELETE SET NULL**, `updated_at`. Seeded from
`src/lib/company.ts`. A separate table rather than `app_settings` (migration 038), whose rows are
owner-tunable numbers as name/value pairs of 255 characters: this is one public record with a
fixed field list, read whole by the website.
