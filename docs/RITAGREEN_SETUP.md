# RITAGREEN_SETUP.md — the transformation brief

> **Superseded, 14 September 2026.** The approved product direction is
> [`LEAN-CMS-LAUNCH-PLAN.md`](LEAN-CMS-LAUNCH-PLAN.md): one company, four services, one person
> record, on the existing React/Vite, PHP and MySQL architecture. The multi-tenant SaaS
> transformation this file briefs — tenants, custom domains, sign-up, per-tenant branding — is
> **not being built**, and neither is the LMS scope it assumes.
>
> This file is kept because Part D is still the fullest written account of what the application
> actually does, surface by surface, and that account is accurate and useful. Read it as a
> description of the system, not as a plan for it. Where Parts A to C describe the
> transformation, treat them as a proposal that was considered and set aside.

**Status:** working brief, written against the codebase as it stood on 2026-09-02.
**Owner:** Gideon Akinlotan.
**Purpose:** a single, self-contained prompt that briefs an executive product team on RitaGreen
OS — what it is, what it runs on, what is built, what is broken, and what it must become — and
then instructs that team to transform it from a one-company internal platform into a
multi-tenant SaaS product that other organisations can sign up to, brand, and run on their own
domain.

**How to use this file.** Paste it whole into a capable model as the opening prompt, or hand it
to a human team as the brief. Every `{PLACEHOLDER}` in the generic version of this prompt has
already been filled in from this repository — nothing needs supplying except judgement. Where a
fact is stated here it is verifiable against `server/`, `src/` or `docs/`; where an intention is
stated it is labelled as an intention. That distinction is `docs/RULES.md` rule 19 and it
applies to this file too.

**Part D is the full account of the application as it stands** — every surface, every lifecycle,
every scheduled job, the setup from an empty machine to a live server, and everything the system
deliberately refuses to do. Read Part D first if you want to understand the product; read Parts
A–C if you are being asked to transform it.

**Related documents.** This file is the brief. It does not replace the pack:
[`PRD.md`](PRD.md) (why) · [`BLUEPRINT.md`](BLUEPRINT.md) (what and the gap) ·
[`ARCHITECTURE.md`](ARCHITECTURE.md) (how) · [`SCHEMA.md`](SCHEMA.md) ·
[`API.md`](API.md) · [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md) · [`RULES.md`](RULES.md) ·
[`UK_GOVERNANCE.md`](UK_GOVERNANCE.md) · [`ROADMAP.md`](ROADMAP.md) ·
[`PROGRESS.md`](PROGRESS.md) · [`TRACEABILITY.md`](TRACEABILITY.md) ·
[`CHANGELOG.md`](CHANGELOG.md).

---

# PART A — THE PROMPT

## Enterprise Product Transformation Mode

You are my Executive Product Team, consisting of:

* CEO
* Chief Product Officer (CPO)
* Chief Technology Officer (CTO)
* Chief Design Officer (CDO)
* Principal Software Architect
* Enterprise Solutions Architect
* Multi-Tenancy / Platform Architect
* UX Research Lead
* UI Design Director
* Design Systems Architect
* Senior Frontend Engineer
* Senior Backend Engineer
* Database Architect
* AI Systems Architect
* DevOps Engineer
* Security Architect
* Privacy Engineer
* Deliverability / Email Infrastructure Engineer
* Product Strategist
* Business Analyst
* QA Lead
* Growth Strategist
* Technical Writer
* UK Employment & Compliance Domain Advisor (non-legal)

Think like the team responsible for rebuilding products such as Linear, Notion, Stripe, Figma,
Vercel, Shopify, Canva, Slack, or Atlassian — and, for domain benchmarking, like the teams
behind Deputy, Planday, Bright HR, Access People Planner, CoolCare, Nourish, Log my Care,
TimeGate, Rota Horizon, Skedulo, Personio, HiBob and Workable.

Your mission is not to make cosmetic improvements.

Your mission is to completely evaluate, modernize, restructure, simplify, and future-proof the
application while preserving its core value — and, on top of that, to take a system built for
**one company with four divisions** and turn it into a **multi-tenant platform many
organisations can buy, brand and operate independently**.

The goal is a polished, scalable, enterprise-grade product that could confidently compete in
today's UK workforce-compliance market, without losing the single property that makes it worth
building: **compliance status is calculated from evidence, and ineligibility blocks the
action.**

---

## PROJECT INPUT

### Application Name

**RitaGreen OS** — internally, "the operations platform behind RitaGreen Services".

Working brand question for Phase 9: the product name is currently the operator's name. A
platform sold to other organisations cannot credibly be named after one of its customers. Treat
a rename as in scope, and treat "RitaGreen Services" as *the first tenant*, not as the product.

### Current Product

A single React 19 SPA served as static files from cPanel shared hosting, talking to 54 flat PHP
8.4 endpoint files in `server/` over the same domain, backed by MySQL/MariaDB. No framework, no
router, no Composer, no Node on the host. It runs the whole of one UK services company across
four divisions — **Care, Academy, Cleaning, Security** — as one workforce database.

Four surfaces, one bundle, one domain (`ritagreenservices.com`):

| Surface | Path | Audience | Count |
|---|---|---|---|
| Public marketing site | `/` | Anyone | 17 pages, eager-loaded |
| Internal CMS | `/app/*` | Owner and staff | 34 pages, lazy-loaded |
| Client portal | `/portal` | Client accounts | 1 page, scoped by `users.client_id` |
| Worker app | `/m` | Worker accounts | 1 page, scoped by `users.worker_id` |

`homeFor()` in `src/lib/access.ts` routes each account to its surface after login.

### Business Goals

1. **Turn a single-tenant internal system into a SaaS product.** Organisations sign up, get an
   isolated workspace, invite their own staff and workers, configure their own services and
   compliance rules, and operate without ever seeing another organisation's data.
2. **Custom domains per organisation.** A tenant runs the product on their own hostname
   (`ops.acme-care.co.uk`, or a subdomain of the platform, `acme.<platform>.com`), with their
   own logo, colours and legal footer.
3. **Email from the tenant's own domain, not the platform owner's.** When a worker at Acme Care
   gets a credential-expiry reminder, it comes from `alerts@acme-care.co.uk`, is signed with
   Acme's DKIM, aligns with Acme's SPF and DMARC, and replies land in Acme's mailbox. This is
   the headline requirement and the one most likely to be underestimated — see Part B §4.
4. **Keep the compliance guarantee.** Calculated eligibility, evidence-backed verification, and
   an enforced deployment gate must survive multi-tenancy intact and become per-tenant
   configurable rather than per-tenant hard-coded.
5. **Sell into UK regulated services**: domiciliary and residential care, security guarding
   (SIA/ACS), commercial cleaning, training providers, and mixed-service contractors — the same
   shape of business as the first tenant.
6. **Do not become four products.** No payroll processing, no accounting, no LMS, no CRM. See
   `docs/PRD.md` §6 and rule 25.
7. **Commercial model** must be designed, not assumed: per-active-worker pricing, per-service
   modules, tiers, trial, self-serve versus sales-assisted, and what happens to a tenant's data
   when they stop paying.

### Current Screens

**Public (`src/pages/public/`, eager):** Home · Services · ServiceDetail · Sectors · About ·
Accreditations · Careers · Apply · Contact · Quote · RequestCover · Resources · Article ·
Verify (public certificate verification) · Offer (tokenised shift acceptance, no login) ·
Legal · NotFoundPublic.

**Auth (`src/pages/auth/`):** Login · Register (creates an *applicant*, never a user) ·
ForgotPassword · ResetPassword · GoogleCallback.

**Internal CMS (`src/pages/app/`, lazy):** Dashboard · Workers · Compliance · ATS · Academy ·
AcademyDetail · Scheduling · Jobs · FieldOps · Timesheets · Payroll · Clients ·
ClientDetail · Bookings · Enquiries · Quotes · Contracts · Invoicing · Incidents · Files ·
Reports · Analytics · Insights · Comms · Mailbox · Notifications · Blog · ContentStudio ·
Testimonials · Settings · Profile · Help · SmokeTest.

**Portal:** ClientPortal — cover requests, assigned workers, invoices.
**Worker:** WorkerApp (`/m`) — own compliance, shifts, open shifts to claim, clock in/out.

Audit every one of them in Phase 2 and Phase 3. Several are thin, several duplicate each other
(Analytics/Insights/Reports; Comms/Mailbox/Notifications), and the
CMS is thirty-four links deep for a business whose owner mostly wants to know what needs
attention today.

### Current Design System

Navy and mint, v2. Documented in `docs/DESIGN_SYSTEM.md`, implemented as HSL custom properties
in `src/index.css` and consumed through Tailwind semantic tokens.

| Role | Token | Value |
|---|---|---|
| Primary — navy | `--primary` | `210 82% 27%` / `#0C447C` |
| Accent — mint | `--accent` | `160 51% 58%` / `#5DCAA5` |
| Surface tint | `--secondary` | `209 72% 94%` / `#E6F1FB` |
| Status | `--destructive` · `--warning` · `--success` | expired · expiring · valid |

Type: **Source Sans 3** (UI), **Plus Jakarta Sans** (display only), **Source Code Pro** (data).
Radius from one variable (`--radius: 0.625rem`). Four elevations. Motion restrained — interface
motion ≤200ms. Components: shadcn/ui `new-york` over Radix, 37 vendored into
`src/components/ui/`. Icons: Lucide, one set. Dark mode via `next-themes`, every token
redefined under `.dark`.

Division accents (`src/lib/divisions.ts`) are a dot or a rule, never a page background:
Care `#0C447C` · Academy `#B7791F` · Cleaning `#5DCAA5` · Security `#1B2A3C`.

The repository previously carried **four competing brand palettes**; three were retired.
Multi-tenancy reintroduces exactly this risk at scale — see Part B §3 on tenant theming, and do
not let a tenant's brand leak into status colours, which must stay semantic.

### Application Specification

From `docs/PRD.md`:

**The problem is fragmentation, not websites.** Worker information lives across websites, Google
Forms, Gmail, spreadsheets, Google Drive, paper, certificates, WhatsApp and individual staff
members' memories. Nobody can answer, quickly and with evidence: has this person been verified,
and by whom? When does their right to work expire? Is their mandatory training still valid? Does
this officer still hold an active SIA licence? Has their contract been signed? Where is their
certificate? Which services are they approved to work in?

**Three outcomes:**
1. One permanent record per human.
2. Compliance is calculated, never remembered.
3. Ineligibility is enforced, not reported.

**Six principles:** person-centred · service-aware · compliance-driven · evidence over
assertion · the server is the source of truth · no silent loss.

**Non-goals:** full payroll processing · accounting · an LMS · a full CRM · complex rota
management · AI hiring decisions · biometric monitoring or surveillance.

**Compliance positioning (rule 22):** the system *supports* compliance. It never *ensures* it.
Policy values are configuration owned by the business, not constants owned by a developer
(rule 23). `docs/UK_GOVERNANCE.md` deliberately states no durations or version numbers, because
a stale figure written down is a figure that gets trusted.

### Architecture

```
Browser — React 19 SPA (Vite build, static files)
   │  fetch() with  Authorization: Bearer <token>
   ▼
server/*.php — 54 flat endpoint files, no framework, no router, no Composer
   │  mysqli prepared statements
   ▼
MySQL / MariaDB on cPanel shared hosting (host = localhost)
```

| Layer | Detail |
|---|---|
| Frontend | React 19.2, Vite 7.2, TypeScript 5.9 strict (`noUnusedLocals`, `noUnusedParameters`), React Router 7.11 with one route table in `src/routes/index.tsx` |
| State | `useState`, one `AuthProvider` context, one `useApi` GET hook with a monotonic request-id guard. No Redux, no react-query, no cache, no mutation layer |
| UI | shadcn/ui over Radix, Tailwind 3.4, ApexCharts, FullCalendar, jsPDF, SheetJS |
| Kernel | `server/lib.php` — config load, CORS, error handlers, `json_out()`, `fail()`, `require_auth()`, `require_role()`, `is_staff()`, `linked_worker_id()`, `audit()`, `rate_limit()`, `next_seq()`, mail, SMS/WhatsApp, TOTP, `notify()` |
| Pure logic | `server/pure.php` — no database, no config, no output, no globals; every date function takes an injectable clock. Holds expiry classification, reminder banding, staff/worker/client distinction, field status, person match keys, and `rg_evaluate_eligibility()` |
| Auth | Opaque bearer tokens (32 random bytes, SHA-256 stored, 30-day expiry), bcrypt cost 12, hand-rolled RFC 6238 TOTP, device/session list with per-session revoke, rate limiting, Google SSO matched on verified email only, idle lock screen, `MANDATORY_2FA` flag |
| Mail | Nothing in a web request opens an SMTP socket. `queue_mail()` writes to `email_queue`; `send-queued-mail.php` drains it on cron, claims each row, logs every attempt including skips. Three sends stay synchronous by design: panic alert, password reset, interactive mailbox reply |
| Cron-only | `expiry-alerts.php`, `send-queued-mail.php`, `backup.php` — refused over HTTP unless an `X-Cron-Key` header (or `?key=`) matches `ALERTS_KEY` |
| CLI-only | `migrate.php`, `seed.php`, `backfill-people.php`, `create-admin.php` — refuse HTTP entirely |
| Config | 43 `define()` constants in `server/config.php`, gitignored, hand-placed on the server. `config.sample.php` is the tracked template. **No `.env`, no `VITE_*`, nothing secret in the bundle** |
| Deploy | Push to `main` → GitHub Actions → `npm ci` → `npm run build` → FTPS upload of `dist/` **only**. PHP, `config.php` and `.htaccess` excluded by design; `dangerous-clean-slate` must never be added (rule 16) |
| Dev | `npm run dev` (Vite, port 5122, `strictPort`) + `npm run dev:api` (`php -S 127.0.0.1:8122 -t server`). Proxy target from `RG_API_TARGET`, defaults local, warns when overridden (rule 13) |
| Tests | `server/tests/run.php` (308 assertions, no database, no Composer — pure logic plus `server/tests/guards.php`, which fails if any endpoint returns before it authorises) and two vitest files, `src/lib/__tests__/access.test.ts` and `app-routes.test.ts` (37 tests over the permission map, `homeFor()` and the page registry) |
| CI | typecheck, `eslint --max-warnings 0`, both test suites, build, source-map assertion, `php -l` across every PHP file, a guard against committing `config.php`, `python3 scripts/docs_sync.py --check`. Every one of them blocks |

**Hard host constraints — treat as inputs to every recommendation:**
- No Node on the host. The build happens locally or in CI and ships as static artifacts.
- No Composer, no vendor directory. SMTP is hand-written over `fsockopen`; TOTP is hand-rolled.
- No shell access to install packages; shared hosting, PHP-FPM/LiteSpeed, `DB_HOST=localhost`.
- Uploads live outside the reachable path; `.htaccess` blocks `^uploads/`; documents stream
  through `files.php` behind an auth check with randomised stored names, a MIME allowlist and a
  10 MB cap.
- `public/.htaccess` re-injects the `Authorization` header, which LiteSpeed otherwise strips.
- Cloudflare records for this domain stay **grey-cloud (DNS only)** so cPanel can issue SSL —
  unlike every other project on the same hosting account. Multi-tenant custom domains change
  this materially; see Part B §3.

### Database Schema

MySQL/MariaDB. `server/schema.sql` is the from-scratch baseline, organised in re-runnable
"build passes" where later `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` blocks patch earlier
`CREATE TABLE` statements *in the same file* — so a table's real shape is its `CREATE` plus
every later `ALTER`. Everything new goes in `server/migrations/NNN_name.sql`.

**Baseline tables (50):** `items` `roles` `users` `tokens` `audit_log` `counters` `rate_limits`
`clients` `sites` `workers` `certifications` `contact_enquiries` `programmes` `enrolments`
`sessions` `invoices` `timesheets` `announcements` `contracts` `quotes` `jobs` `certificates`
`files` `compliance_reminders` `insurance` `worker_references` `pay_rules` `bank_holidays`
`credit_notes` `shifts` `mandates` `dd_payments` `incidents` `incident_actions` `shift_events`
`applicants` `onboarding_tasks` `user_2fa` `password_resets` `rate_cards` `service_requests` `consent_log`
`skills` `worker_skills` `shift_templates` `shift_offers` `notifications` `sms_log` `blog_posts`
`testimonials`.

**Migrations applied, 001–018:**

| # | Migration | Adds |
|---|---|---|
| 001 | `people_spine` | `people`, `person_services` |
| 002 | `people_foreign_keys` | `person_id` on `workers`, `applicants`, `users`, `enrolments` |
| 003 | `compliance_rules` | requirement catalogue and service × role rules |
| 004 | `people_required` | `person_id NOT NULL`; sets `STRICT_ALL_TABLES` itself |
| 005–007 | right to work | provisional record, `right_to_work_checks`, verification backlog |
| 008 | `email_queue` | queue, log, templates |
| 009 | `audit_detail` | before/after values, entity reference, IP, reason |
| 010 | `employment_records` | `employment_contracts` with draft→signed→superseded and an access token |
| 011 | `form_intake` | external intake and field mapping |
| 012 | `governance` | `retention_policies` and deletion approval |
| 013 | `soft_delete` | `deleted_at` on applicants, invoices, incidents, timesheets, shifts |
| 014 | `schema_integrity` | six missing foreign keys, `ON DELETE SET NULL`; `client_services` junction |
| 015 | `permission_scope` | per-service scoping |
| 016 | `worker_status_and_providers` | derived worker status, training providers |
| 017 | `verifications` | generalised requested→received→under review→verified/rejected |
| 018 | `align_rules_with_prior_behaviour` | seeded rules matched to prior enforcement |

**The migration runner is deliberately awkward to misuse.** Dry run is the default; applying
takes `--go`; a non-local database additionally takes `--production` and a typed database name;
an edited-since-applied migration is reported as drift; a migration may declare a
`-- @precondition:` query that must return 0. That mechanism exists because **a foreign key does
not force a row to be linked — only `NOT NULL` does**, which was assumed otherwise and
corrected against a real database.

**Known schema debt to rule on:**
- Divisions are a hard-coded ENUM on thirteen tables plus three near-duplicate variants
  (`contact_enquiries.division` adds `other`, `announcements.audience` adds `all`,
  `skills.category` adds `general`) — sixteen columns, seventeen with the TypeScript union.
  Adding or renaming a division is a migration against every one of them. **This is fatal to
  multi-tenancy**: tenant services cannot be an ENUM.
- `clients.divisions` was a comma-separated string, now split into `client_services`, with the
  CSV kept in step until nothing reads it.
- Soft delete is present on most tables that need it, missing on some.

### API Documentation

54 flat endpoint files in `server/`. Dispatch is by HTTP method plus `?action=`. Full detail in
`docs/API.md`. Grouped:

| Group | Files |
|---|---|
| Kernel and shared | `lib.php` · `pure.php` · `compliance-lib.php` · `pay-lib.php` |
| Identity and access | `auth.php` · `users.php` · `me.php` · `portal.php` |
| People and employment | `workers.php` · `ats.php` · `references.php` · `skills.php` · `leave.php` · `contracts.php` |
| Compliance and evidence | `compliance.php` · `verifications.php` · `right-to-work.php` · `expiry-alerts.php` · `certificates.php` · `academy.php` |
| Operations | `scheduling.php` · `shift-templates.php` · `offer.php` · `field.php` · `jobs.php` · `bookings.php` · `timesheets.php` · `payroll.php` · `incidents.php` · `weather.php` |
| Commercial | `clients.php` · `quotes.php` · `invoices.php` · `credit-notes.php` · `payments.php` · `gocardless.php` · `margin.php` · `enquiries.php` |
| Content and comms | `blog.php` · `testimonials.php` · `comms.php` · `inbox.php` · `notifications.php` · `contact.php` · `forms-intake.php` · `send-queued-mail.php` |
| Files and reporting | `files.php` · `reports.php` · `analytics.php` · `dashboard.php` · `action-centre.php` |
| Governance | `audit.php` · `gdpr.php` · `governance.php` · `insurance.php` · `system-health.php` · `backup.php` |
| AI | `ai.php` — OpenRouter proxied server-side so the key never reaches the browser |
| Tooling | `migrate.php` · `seed.php` · `backfill-people.php` · `create-admin.php` · `api.php` |

### Repository

```
src/
  routes/index.tsx      every route, in one file
  pages/                public/ · app/ · auth/ · portal/ · worker/
  components/ui/        37 vendored shadcn components
  lib/                  access.ts · divisions.ts · types.ts · pdf.ts · csv.ts · api client
  providers/            auth context
  hooks/use-api.ts      the one fetch hook
server/
  lib.php               the kernel every endpoint requires
  pure.php              testable domain logic
  *.php                 54 endpoints, one per resource
  migrations/           001–018
  schema.sql            the from-scratch baseline
  tests/run.php         308 assertions, no database
docs/                   the documentation pack
scripts/docs_sync.py    keeps PROGRESS.md in step with STATUS.json
```

Scripts: `npm run dev` · `dev:api` · `dev:prod-api` · `typecheck` (`tsc -b --force`) · `lint` ·
`test` · `test:php` · `build` · `preview` · `seed`.

### User Feedback

There is no formal user research. The evidence available is the audit trail in
`docs/BLUEPRINT.md` Part 3, the operator's own account of the problem in `docs/PRD.md` §2, and
the defects found and fixed. Treat "we have no research" as a Phase 2 finding, not as an excuse:
the first deliverable of UX Research Lead is a plan to get some, from the four real user types
below, before the redesign hardens.

What is known, and is not opinion:

- **A person could exist three times.** `ats.php` copied identity fields between tables and, for
  a learner with no email, fabricated `name@learner.invalid`. Fixed by the person spine;
  `find_or_create_person()` now fronts every creation path and `people.match_key` is UNIQUE.
- **Three read endpoints leaked across account types.** `workers.php` GET exposed every worker's
  pay rate, DBS status and SIA number to any authenticated account including a client portal
  login; `inbox.php` exposed the whole company mailbox and the ability to reply as the company;
  `field.php` POST allowed posting clock-ins and **panic alerts** against any shift id. All
  fixed. The lesson recorded in the blueprint is the important part: **role alone could not
  express the boundary**, because a worker's login is an ordinary account with `users.worker_id`
  set and may carry any role.
- **Client-side role gating was dead code.** `RequireAuth` took a `roles` prop no route passed,
  so `/app`, `/portal` and `/m` were gated identically on "logged in". Now one map in
  `src/lib/access.ts`, read by both the router guard and the sidebar.
- **Failed queries passed silently.** `MYSQLI_REPORT_OFF` plus unchecked `execute()` meant
  `workers.php` returned HTTP 200 and a complete worker object with `id: 0` for a row never
  written.
- **Development used to run against production.** The Vite proxy defaulted to
  `https://ritagreenservices.com`, so every developer read and wrote live workforce data — while
  testing migrations.
- **Reassessment used to send mail.** Folding it into the alerts cron meant recalculating during
  a deploy emailed the real workforce at a time nobody chose. Now `--reassess-only`.

### Competitors

| Segment | Examples | What they do better | Where the opening is |
|---|---|---|---|
| Care compliance | CoolCare, Nourish, Log my Care, Access People Planner | Sector depth, CQC familiarity, established integrations | Usually single-sector; eligibility is reported, not enforced |
| Security workforce | TimeGate, Rota Horizon, Templa | SIA/ACS reporting, licence tracking | Rota-first, compliance-second; heavy, dated interfaces |
| General workforce | Deputy, Planday, Skedulo | Scheduling polish, mobile, integrations | Compliance is a document folder, not an engine |
| HR platforms | Bright HR, Personio, HiBob, Breathe | Employment records, contracts, absence | Not service-aware; cannot say "eligible for Security, not for Care" |
| ATS | Workable, Teamtailor | Recruitment funnel | Hands off at hire; identity restarts downstream |

**The differentiating claim to protect:** one person record, per-service eligibility calculated
from evidence, and an enforced gate that refuses the assignment with a reason. Nobody in the
list above does all four across mixed services in one record.

### Target Users

| User | Sees | Key need |
|---|---|---|
| **Owner / operations director** | Everything in their tenant | "What needs my attention today?" — not a list of records |
| **Staff** (recruiter, compliance officer, scheduler, trainer, finance) | Scoped by role and service | Get through the day's verification and recruitment work without opening fifteen pages |
| **Worker** | Their own record only | Know their own status, upload what is asked, claim shifts, clock in, request leave |
| **Client** (the tenant's customer) | Their own account only | Raise a cover request, see who is assigned, see invoices |
| **Platform operator** (new) | Cross-tenant, no tenant data | Provision, bill, support, monitor, and prove isolation |
| **Auditor / inspector** (indirect) | An exported pack | Evidence assembled, not compiled by hand |

Seven roles today: `super_admin`, `admin`, `manager`, `trainer`, `editor`, `employee`, `client`
— declared in three places that must be kept in sync (`schema.sql`, `auth-provider.tsx`,
`require_role()` call sites). Flat, with no sensitive zones and no enforced per-service query
filter. `docs/UK_GOVERNANCE.md` defines the target zones: GENERAL · EMPLOYMENT · COMPLIANCE ·
HEALTH · DISCIPLINARY · PAYROLL · LEGAL.

### Future Vision

A UK workforce-compliance platform that any regulated-services organisation can sign up to in an
afternoon, point their own domain at, send their own branded mail from, configure their own
services and rules in, and rely on to refuse an unsafe assignment.

The end state, stated as the journey it must support end to end:

```
Organisation discovers the product
  → signs up, creates a workspace, starts a trial
  → verifies a custom domain (app hostname + email sending domain)
  → configures services, roles, and compliance rules from a template
  → invites staff, assigns roles and service scope
  → imports or invites workers; each becomes one person record
  → workers complete their own onboarding: documents, right to work, training
  → the engine calculates eligibility per person per service, continuously
  → schedulers assign work; the gate refuses ineligible assignments with a reason
  → the field app records clock-in, check-in, incident, signature
  → timesheets become pay records and client invoices
  → expiry alerts go out from the tenant's own domain at 90/60/30/expired
  → the owner opens an Action Centre, not a list of tables
  → an inspection is answered by exporting a pack
  → the tenant renews, upgrades, or leaves with their data
```

Everything in Part B exists to make that paragraph true.

---

# PART B — WHAT THE TRANSFORMATION MUST SOLVE

This section is not optional context. It is the substance of the brief, and each numbered item
must be answered explicitly in your output.

## 1. The single-tenant assumption is everywhere

The system was written for one company. Enumerate and cost every place that assumption is baked
in. At minimum:

- **No tenant column anywhere.** 49 baseline tables, none scoped. Decide the isolation model —
  shared schema with a `tenant_id` on every table and enforced at the query layer; database per
  tenant; or hybrid (shared app, database per tenant, which fits cPanel's per-account database
  model surprisingly well). State the trade-offs in cost, isolation, backup granularity,
  migration blast radius and noisy-neighbour risk, then recommend one.
- **`DB_HOST=localhost` and 43 `define()` constants in one hand-placed file.** Tenant
  configuration cannot be PHP constants. Design the replacement: a tenant table, a resolver that
  maps hostname → tenant, and a per-tenant settings store — without putting secrets in the
  client bundle (rule 8) or in a tracked file (rule 7).
- **Divisions are an ENUM on sixteen columns.** Tenants do not have Care/Academy/Cleaning/
  Security. They have *their* services. `person_services` and `client_services` are the correct
  shape; the ENUM is not. Plan the migration and the interim.
- **Roles are an ENUM in three synchronised places.** Tenants need their own roles, or at least
  their own mapping onto a fixed set. Decide which, and say why.
- **`next_seq()` counters are global.** Invoice, quote and payroll numbers must be per tenant and
  still atomic, unique, permanent and never reused.
- **Uploads are one directory.** Per-tenant storage isolation, quota, and the same
  streamed-behind-auth rule.
- **Cron is one schedule.** `expiry-alerts.php` currently sweeps one company. Multi-tenant means
  per-tenant timezone, per-tenant thresholds, per-tenant quiet hours, and a run that does not
  take an hour when there are 200 tenants.
- **Audit, GDPR export and erasure are company-wide.** Each must become tenant-scoped, and the
  platform operator must be able to prove they cannot read tenant data casually.
- **The public marketing site is RitaGreen's.** Decide whether tenants get a public site at all,
  or whether the product ships only the CMS, portal and worker app, and the 17 marketing pages
  become the platform's own site plus an optional tenant careers/verify surface.

## 2. Tenant onboarding, end to end

Design the whole path, screen by screen, with the state machine and the failure modes:

sign-up → email verification → workspace creation → plan selection or trial → service
configuration (from a sector template: care / security / cleaning / training / mixed) →
compliance rule set seeded from that template and then editable → branding (logo, colour, legal
footer) → custom domain → email sending domain → invite staff → set role and service scope →
import workers (CSV, ATS handoff, or invite) → per-person identity matching and the review list
for ambiguous matches → worker self-onboarding → first calculated eligibility → first assignment
attempt → first blocked assignment → first alert → activation.

Define **activation** as a measurable event, not a feeling. Candidate: *a tenant has ten linked
person records, one configured service with a mandatory requirement, and one assignment either
made or correctly refused.*

Specify what must be true before a tenant can send mail, before they can use a custom domain,
and before their first alert goes out — and what the product does while those are pending, so
onboarding never dead-ends waiting on DNS.

## 3. Custom domains

Two distinct things, routinely conflated. Treat them separately.

**(a) The application hostname.** `ops.acme-care.co.uk` serving the SPA and the PHP API
same-origin. On cPanel this means an addon domain or subdomain, a document root, a certificate,
and a DNS record the tenant owns. Note the standing constraint recorded in `CLAUDE.md`: this
account's ritagreen zones stay grey-cloud so cPanel can issue SSL, while every other zone on the
account must stay orange-clouded because the origin holds Cloudflare Origin certificates that
browsers reject. A multi-tenant custom-domain feature cannot inherit that ambiguity — decide the
TLS story explicitly (ACME automation, a proxy tier, or a managed edge) and say what it costs.

**(b) The email sending domain.** `alerts@acme-care.co.uk`. This is the user's headline
requirement and it is a deliverability problem, not a UI problem. Cover: per-tenant DKIM key
generation and the DNS record the tenant must publish; SPF alignment (and the fact that SPF is
*edited*, never added — two `v=spf1` records is a `PermError`); DMARC alignment and what happens
under `p=quarantine`; Return-Path and bounce handling; a verification flow that polls DNS and
tells the tenant exactly which record is wrong; what the product sends *from* while verification
is pending (platform domain with the tenant's name in the display name and a `Reply-To` at the
tenant, never a spoofed From); per-tenant reputation isolation; suppression lists; and complaint
handling.

State plainly whether hand-rolled `fsockopen` SMTP against a shared cPanel mail server can carry
this. If the recommendation is a sending provider with multi-domain support, say so, price it,
and keep `queue_mail()`/`email_queue`/`email_log` as the interface so the change is a driver
swap and not a rewrite.

**(c) Sender identity in the UI.** Every template, every alert, every invoice, every certificate
must render the tenant's brand and the tenant's addresses. Audit every place the string
"RitaGreen" or a RitaGreen address appears in code, templates, PDFs and emails.

## 4. What must not break

These are the properties that make the product worth selling. Any recommendation that weakens
one must say so out loud and justify it.

1. Compliance status is calculated, never set. No editable "compliant" field, anywhere.
2. A person exists once. Link with `person_id`; never copy identity fields.
3. Never hard-delete an employment record.
4. Verification requires evidence: a verifier, a timestamp, an outcome and a document.
5. Money is integers in pence.
6. The server is the source of truth; the browser never holds authoritative compliance state.
7. No secret in a tracked file; nothing secret in the client bundle.
8. Every mutating endpoint checks the role server-side.
9. Prepared statements always.
10. Uploaded files are never served directly.
11. Development never points at production.
12. Schema changes are numbered migrations; a destructive one is preceded by a *restore-tested*
    backup.
13. Branch, pull request, merge — pushing to `main` deploys.
14. Documentation states what the code does, not what the plan says.
15. Compliance language is "supports", never "ensures".
16. Compliance requirements are configuration, not code.
17. Ineligibility blocks the action.
18. Non-goals stay non-goals.

Full text and rationale: `docs/RULES.md`.

## 5. Current honest state

From `docs/PROGRESS.md`, generated from `docs/STATUS.json`: **151 done · 14 partial · 3 to do ·
168 tracked.**

| Module | Milestone | Done | Partial | To do |
|---|---|---|---|---|
| PLATFORM — Engineering platform | 0 | 11 | 3 | 2 |
| FOUNDATION — Person spine | 1 | 16 | 1 | 0 |
| COMPLIANCE — Engine and verification | 2 | 22 | 2 | 0 |
| DOCUMENTS | 3 | 6 | 0 | 0 |
| OPERATIONS — Scheduling, assignments, field | 4 | 10 | 0 | 0 |
| IAM — Identity, access, permissions | 5 | 21 | 1 | 0 |
| EMPLOYMENT — Records, contracts, leave | 6 | 7 | 3 | 0 |
| COMMUNICATIONS | 7 | 11 | 0 | 0 |
| RECRUITMENT and intake | 8 | 10 | 0 | 0 |
| TRAINING and certificates | 8 | 7 | 0 | 0 |
| GOVERNANCE — Audit, retention, data protection | 9 | 13 | 4 | 1 |
| PWA | 9 | 9 | 0 | 0 |
| REPORTING | 9 | 8 | 0 | 0 |

**Everything still open, in full — do not plan around a rosier picture:**

- Automated tests are partial: 308 server assertions and 37 vitest tests; **endpoints and React
  components have no automated coverage of their own.** The endpoint sweep that found the
  authorisation and 404 defects was run by hand against a local database, and only its invariant
  — no endpoint returns before it authorises — is locked in `server/tests/guards.php`.
- CI quality gates now all block, lint included, at zero errors.
- ~~**The migration production guard does not fire on cPanel.**~~ **Fixed.** It was inferred
  from `DB_HOST`, and on shared hosting the production database *is* localhost — so the live
  system read as local. `config.php` now declares `RG_ENV`, read through
  `rg_is_local_database()`, and a config without it counts as production.
- Soft delete is missing on some records that must not be lost.
- Compliance status is read-only where it matters, but **older screens still show raw DBS/SIA
  fields**.
- Right to work: the record exists and the engine reads it, but **enforcement of verification is
  staged behind migration 007 until the legacy backlog is cleared** — a one-row UPDATE and a
  business decision.
- Several read endpoints still carry `require_auth()` with no `require_role()`.
- Employment contract lifecycle: schema and versioning in, **endpoint and document generation
  not**.
- Retention: `retention_policies` is editable with periods deliberately NULL until the business
  sets them; **the sweep that acts on them is not built**.
- Acas particulars are not structured fields.
- GDPR erasure runs immediately, **with no approval step**.
- Backups are not restore-tested; `system-health.php` reports this as an explicit unknown rather
  than assuming it is fine.
- **No workflow rule engine.** Every automation is bespoke code.
- Milestone 1 is applied and verified locally, **not yet applied to production**.

---

# PART C — THE WORK

## OBJECTIVE

Treat this as a complete product transformation.

Assume nothing. Question everything. Every feature should justify its existence. Every screen
should earn its place. Every workflow should become faster. Every interaction should become
clearer. Every system should become simpler.

Every decision should improve usability, scalability, maintainability, accessibility,
performance, security, profitability and customer satisfaction.

Where a recommendation collides with a host constraint (no Node, no Composer, shared hosting,
FTPS deploy) or with a rule in Part B §4, say so explicitly and either justify the collision or
route around it. Do not silently assume infrastructure the project does not have.

## PHASE 1 — PRODUCT AUDIT

Conduct a complete audit. Understand what exists, what works, what does not, what users actually
need, what should be removed, redesigned, automated or simplified.

Evaluate: product vision · user journey · navigation · architecture · database · AI features ·
performance · accessibility · scalability · security · technical debt · developer experience ·
business model · monetization · analytics · documentation · testing · deployment ·
infrastructure · **multi-tenant readiness** · **data isolation** · **email deliverability**.

## PHASE 2 — UX AUDIT

Audit every screen listed under Current Screens.

Evaluate: navigation · user flow · cognitive load · visual hierarchy · information architecture ·
readability · accessibility · interaction design · search · filtering · forms · onboarding ·
settings · dashboards · reports · editors · notifications · mobile responsiveness · loading
states · empty states · error handling · success feedback · micro-interactions · consistency.

Identify: confusing flows · duplicate pages · dead ends · feature overload · hidden actions ·
poor accessibility · visual inconsistencies.

Start with the three that are known to be crowded: Analytics/Insights/Reports;
Comms/Mailbox/Notifications; Workers/Compliance/ATS/Profile now that one person record exists
and the separate applicant and worker views should have merged. Timetable was the fourth and is
resolved: it duplicated Timesheets outright and is now a redirect to it.

## PHASE 3 — UI AUDIT

Review the complete design language: colour system · typography · spacing · grid · cards ·
buttons · tables · charts · forms · dialogs · icons · illustrations · animations · dark mode ·
brand consistency · component reuse · visual balance · modernity · production readiness.

Then answer the multi-tenant question the current system has never faced: **how much of the
visual language is a tenant allowed to change, and what is structurally fixed?** Status colours
are semantic and must not be themable. Say what else is not.

## PHASE 4 — FEATURE AUDIT

Classify every feature: KEEP · IMPROVE · MERGE · SIMPLIFY · AUTOMATE · REPLACE · REMOVE.
Explain every recommendation. Never remove functionality without justification.

Add a fifth column: **TENANT-SCOPED · PLATFORM-SCOPED · BOTH**, because the split is not obvious
and getting it wrong is how a tenant reads another tenant's data.

## PHASE 5 — INFORMATION ARCHITECTURE

Rebuild the product structure: navigation · sidebar · top navigation · workspace hierarchy ·
settings · search · dashboards · admin · notifications · help · account · billing ·
documentation.

Two hierarchies now, not one: the **tenant workspace** and the **platform admin console**. Design
both. Thirty-four flat CMS links is not an information architecture.

## PHASE 6 — WORKFLOW REDESIGN

Optimise every workflow. Reduce clicks, friction, unnecessary decisions and repetition. Introduce
automation where valuable.

The workflows that matter most, in order: verify a credential · onboard a candidate to worker ·
resolve an ambiguous identity match · fill an unassigned shift · answer "why can this person not
work?" · clear the expiry queue · produce an evidence pack · onboard a new tenant.

## PHASE 7 — AI OPPORTUNITIES

Identify opportunities for AI: assistants · agents · automation · search · recommendations ·
generation · editing · analysis · reporting · workflow automation · background processing · human
review · AI memory · prompt management · reasoning.

Only recommend AI where it provides measurable value. The existing use is an OpenRouter proxy
(`server/ai.php`) behind the server so the key never reaches the browser — keep that shape.

**Hard boundary:** no AI recruitment scoring and no automated hiring decisions (`docs/PRD.md`
§6). Document extraction, expiry-date reading, rule-set drafting, evidence summarisation and
natural-language reporting are in scope. A model must never be the thing that decides someone
may work — the engine decides, from rules and evidence, and shows its reasoning.

## PHASE 8 — TECHNICAL MODERNISATION

Review architecture · folder structure · component architecture · API design · database ·
caching · performance · background jobs · observability · CI/CD · testing · developer tooling ·
dependencies · security.

Answer directly: **does flat-PHP-on-shared-cPanel survive multi-tenancy, or does the platform
need to move?** If it survives, prove it against tenant count, cron duration, backup granularity
and the blast radius of one bad migration. If it does not, sequence the move so the first tenant
keeps working throughout, and cost it honestly — including the fact that the deploy pipeline
uploads `dist/` over FTPS today and PHP is placed by hand.

Do not propose a rewrite as the opening move. 54 endpoints, 20 migrations and 308 assertions of
tested behaviour are an asset.

## PHASE 9 — BRAND REPOSITIONING

Review the brand: application name · logo direction · tagline · visual identity · tone ·
messaging · value proposition · market positioning · differentiation · competitive advantages.

The name is a customer's name. The voice today is plain, concrete, British — "Qualified.
Checked. Ready." Decide whether that voice belongs to the platform, to the tenant, or to both,
and recommend a rename if the analysis supports one.

## PHASE 10 — DESIGN SYSTEM

Design a production-ready design system: colours · typography · spacing · components · icons ·
tokens · dark mode · accessibility · motion · responsive behaviour · reusable patterns.

Extend it with a **tenant theming layer**: which tokens are tenant-settable, how a tenant's brand
colour is validated for contrast in both light and dark mode, and how a bad tenant palette is
prevented from making status states unreadable.

## PHASE 11 — ENTERPRISE READINESS

Determine readiness for startups · SMBs · enterprise · government · healthcare · education ·
finance · large teams · global deployment · compliance · **multi-tenancy** ·
internationalisation · audit logs · role-based permissions · API ecosystem.

Include: SSO beyond Google (SAML/OIDC), SCIM provisioning, tenant-level data residency, per-tenant
backup and restore, export on exit, uptime and support commitments, and a security
questionnaire the product could actually pass.

## PHASE 12 — LEGAL & COMPLIANCE

Review privacy · terms · cookie policy · licensing · AI transparency · security disclosures ·
accessibility · UK GDPR and GDPR · CCPA · industry-specific requirements.

Multi-tenancy changes the legal posture fundamentally: the platform becomes a **processor** for
its tenants, who are **controllers**. That requires a DPA, sub-processor disclosure, breach
notification terms, retention configurable per tenant, and an answer to "who deletes what, when a
tenant leaves". `docs/UK_GOVERNANCE.md` holds the current framework and its three governing
rules; extend it rather than restating it.

Identify missing policies.

## PHASE 13 — PRODUCT ROADMAP

Create: immediate fixes · quick wins · Version 1 · Version 2 · long-term vision · innovation
opportunities · competitive roadmap.

Sequence against what already exists (`docs/ROADMAP.md` milestones 0–9, of which 0–4 are
substantially delivered) rather than starting from zero. State explicitly where multi-tenancy
inserts into that sequence and what it blocks.

## PHASE 14 — IMPLEMENTATION STRATEGY

Create a complete rebuild plan: recommended order · dependencies · milestones · development
phases · testing strategy · release plan · migration strategy · risk mitigation.

The migration strategy must cover the case that matters most: **RitaGreen Services becomes tenant
one, on live data, without downtime and without losing a single evidence record.** A destructive
migration is preceded by a restore-tested backup — a backup that has been restored somewhere and
verified, not a backup that exists (rule 15).

---

## OUTPUT FORMAT

Produce the following sections.

**1. Executive Assessment** — overall health of the product.

**2. Product Scorecard** — score and explain each: UX · UI · Architecture · Performance ·
Accessibility · Security · Scalability · Developer Experience · AI Readiness · Brand · Business
Model · Documentation · Legal Compliance · **Multi-Tenant Readiness** · **Email Deliverability**
· **Data Isolation** · Overall Product Maturity.

**3. Strengths** — what should remain.

**4. Weaknesses** — what is holding the product back.

**5. Critical Issues** — highest-priority problems.

**6. UX Redesign Recommendations** — screen by screen.

**7. UI Modernisation Plan** — visual transformation strategy, including tenant theming.

**8. Feature Matrix** — Keep · Improve · Merge · Automate · Remove · Future, each tagged
tenant-scoped, platform-scoped or both.

**9. Information Architecture** — tenant workspace and platform console.

**10. Technical Modernisation** — architecture improvements, with the stay-or-move verdict.

**11. Multi-Tenancy Architecture** — isolation model, tenant resolution, per-tenant
configuration, custom domains, per-tenant email identity, cron and background work, backup and
restore, and the migration path for tenant one.

**12. AI Enhancement Strategy** — where AI creates real value, and where it is forbidden.

**13. Brand Refresh** — identity and positioning, including the naming decision.

**14. Enterprise Readiness Review** — scalability and operational maturity.

**15. Legal & Compliance Review** — required policies and compliance gaps, including the
controller/processor split.

**16. Prioritised Roadmap** — Phase 1 → Phase N, mapped onto the existing milestones.

**17. Production Readiness Checklist** — everything required before the first external tenant.

**18. Success Metrics** — measurable KPIs.

---

## SUCCESS METRICS

Define measurable KPIs, including:

**Product and activation**
- Tenant time-to-activation (sign-up → first calculated eligibility)
- Custom domain verification completion rate, and median time to verify
- Email sending domain verification rate; share of tenant mail sent from the tenant's own domain
- Onboarding completion rate per user type (staff, worker, client)
- Worker self-service adoption: share of documents uploaded by the worker rather than by staff

**Operational value — the ones that justify the product**
- Time to produce a named worker's full compliance position, with evidence (target: under a
  minute; `docs/PRD.md` §8)
- Assignments refused by the gate, and the reason distribution
- Credentials that expired without a 90/60/30 alert having been sent (target: zero)
- Duplicate person records created per month (target: zero, enforced by `people.match_key`)
- Evidence packs exported versus packs assembled by hand

**Engineering**
- Task completion time for the eight core workflows in Phase 6
- Error rates, p95 endpoint latency, cron run duration at tenant scale
- Test coverage on endpoints and components (currently zero — a metric, not a footnote)
- Lint errors (currently 27), and whether lint blocks CI
- Deploy frequency, change failure rate, restore-tested backup recency

**Accessibility, satisfaction, commercial**
- WCAG 2.2 AA conformance per surface
- DAU/WAU by user type; feature adoption by module
- Customer satisfaction and support ticket volume per tenant
- Retention, churn, expansion revenue, and revenue per active worker

---

## NON-NEGOTIABLE PRINCIPLES

Always:

* Challenge assumptions instead of accepting them.
* Recommend simplification before adding complexity.
* Reuse and standardise components wherever possible — extend an existing `ui/` component before
  adding one; reuse `next_seq()` for any sequence, `rg_classify()` for any expiry date, the
  `compliance_reminders` dedupe pattern for any tiered alert, and the `files.php`
  streamed-download-behind-auth pattern for any document.
* Put anything that is a function of its arguments in `server/pure.php` with a test, and give
  every date function an injectable clock.
* Design for long-term maintainability.
* Explain the reasoning behind every major recommendation.
* Remove technical debt where possible.
* Optimise for performance, accessibility, security and scalability.
* Keep one source of truth per fact; when a fact would live in two files, one links to the other.
* State what the code does, not what the plan says.
* Never weaken a rule in Part B §4 silently.

The end result should be an application that feels intentionally redesigned from the ground up,
with every screen, workflow and system working together as a unified, enterprise-quality product
— and that a second organisation, on their own domain, sending their own mail, can trust with the
question of whether one of their people is allowed to work today.

---

# PART D — THE APPLICATION, START TO FINISH

Everything below describes the system **as it is built today**, verifiable against `server/`,
`src/`, `docs/` and `.github/`. Where something is unbuilt or partial it is labelled, and the
full list of open work is Part B §5. Where a detail belongs to another document, that document
is named rather than copied — one source of truth per fact (rule 20).

---

## D1. What the system is

RitaGreen Services is one UK company operating four divisions — **Care, Academy, Cleaning,
Security**. RitaGreen OS is the single operational system behind all four: one company identity,
one worker database, one recruitment pipeline, one compliance engine, one document repository,
one communications centre, one audit trail.

It is a **workforce compliance and operations platform**. It is not four management systems, and
it is not a website CMS that grew. The Academy is a record and enrolment module inside the
platform, not a learning management system: the platform records who was trained, in what, by
whom, when, and when it expires. Course delivery stays where it is.

The product exists because of one problem — **fragmentation**. Worker information lived across
websites, Google Forms, Gmail, spreadsheets, Google Drive, paper, certificates, WhatsApp and
individual staff members' memories, and nobody could answer with evidence: has this person been
verified, and by whom; when does their right to work expire; is their mandatory training still
valid; does this officer still hold an active SIA licence; has their contract been signed; where
is their certificate; which services are they approved to work in.

Everything in the rest of Part D is a mechanism for answering those seven questions in seconds,
with evidence, and for refusing the work when the answer is no.

### The four surfaces

| Surface | Path | Who | What it is for |
|---|---|---|---|
| Public site | `/` | Anyone | Marketing, services, careers and applications, quote and cover requests, public certificate verification, tokenised shift offers |
| Internal CMS | `/app/*` | Owner and staff | The operational system: 34 pages behind a login |
| Client portal | `/portal` | Client accounts | Cover requests, assigned workers, invoices, raising an issue |
| Worker app | `/m` | Worker accounts | Own compliance, shifts, open shifts to claim, clock in and out, payslip |

One React bundle serves all four. `homeFor()` in `src/lib/access.ts` sends an
account to its own surface after login: a `client` role or a `client_id` to `/portal`, a
`worker_id` to `/m`, everyone else to `/app`. The 17 public pages are eager-imported so first
paint is fast; the 34 CMS pages are `lazy()` behind a Suspense boundary, so a public visitor
never downloads the internal application.

---

## D2. How a request actually travels

```
Click in the SPA
  → fetch('/workers.php?action=…', { headers: { Authorization: 'Bearer <token>' } })
  → Apache/LiteSpeed serves the file server/workers.php directly — no router, no rewrite
  → require __DIR__ . '/lib.php'
        loads server/config.php (43 define() constants, gitignored, server-only)
        opens the global mysqli $conn
        installs the error and exception handlers
        emits CORS headers from ALLOWED_ORIGINS (strict in_array, no wildcards)
        answers OPTIONS with 204 before any auth
  → require_auth($conn)      SHA-256 of the bearer token joined to tokens → users
  → require_role(...) / require_staff() / can_view_zone()
  → mysqli prepared statement, bound parameters, never interpolation
  → audit($conn, …) on a mutation
  → json_out($data)  →  {"…"}  or  fail('message', 4xx)  →  {"error": "message"}
```

Dispatch inside a file is `$_GET['action']` plus the HTTP verb, resolved by a sequence of `if`
blocks each ending in `json_out()` (which exits). Falling off the end of a file is
`Unknown action.` 404 or `Method not allowed.` 405. **The file is the route table**, so order
matters: a bare `if (method() === 'GET')` placed before the action handlers swallows every GET.
`docs/API.md` flags each place that happens.

Three responses deliberately break the JSON shape: `files.php?action=download` streams the file
with its own content type, `payments.php?action=public_checkout` 302-redirects to Stripe, and the
cron scripts echo plain text for the cron log.

Money is integer pence in `_pence` fields throughout, VAT is computed at 20% at creation time,
dates are `YYYY-MM-DD` and date-times are MySQL `Y-m-d H:i:s`.

---

## D3. Standing it up from nothing — the developer path

The whole of development runs locally. It did not always: the Vite proxy used to default to
`https://ritagreenservices.com`, so every developer read and wrote live workforce data, including
while testing migrations. That is now rule 13, and the default is local.

```bash
npm install

# 1. A local database (MySQL or MariaDB must already be installed — brew install mariadb, or a container)
mysql -u root -e "CREATE DATABASE ritagreen_os_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root ritagreen_os_dev < server/schema.sql

# 2. Local config — every outbound integration disabled
cp server/config.local.php.example server/config.php

# 3. The migration chain, in the only order that works
php server/migrate.php --go            # applies 001–003, stops at 004 on its precondition
npm run seed -- --confirm              # fixtures with a deliberate spread of compliance states
php server/backfill-people.php --go    # links anything not already linked; safe to re-run
php server/migrate.php --go            # 004 now passes its precondition and applies

# 4. Two processes, every day
npm run dev:api                        # php -S 127.0.0.1:8122 -t server
npm run dev                            # http://localhost:5122 (strictPort — fails loudly if taken)
```

**Why that order.** Migration 002 adds `person_id` and its foreign keys, but *a foreign key does
not force a row to be linked* — it constrains non-NULL values only. Migration 004 is what
requires the link, by making `person_id NOT NULL`, and it sets `STRICT_ALL_TABLES` itself so the
behaviour does not depend on the server's `sql_mode`; without strict mode MySQL would coerce
every unlinked `person_id` to 0 and leave the spine quietly wrong instead of loudly unfinished.
004 declares a `-- @precondition:` query that must return 0, so the runner stops with the count
and the remedy rather than failing mid-chain. This was assumed to work the other way round and
corrected against a real database.

**Seeded logins**, all sharing the fixture password printed by `npm run seed`:
`admin@local.invalid` for the office, `amara.login@local.invalid` for `/m`,
`priya@local.invalid` for `/portal`. The fixtures include staff logins for each role, four
clients with sites, ten workers whose compliance is deliberately mixed — valid, inside the
30-day window, expired, and one with no right to work — plus certifications, applicants across
the pipeline, assigned and open shifts, programmes, enrolments, enquiries and an incident. That
spread is what makes the compliance and scheduling paths testable without editing rows by hand.

`seed.php` refuses to run unless it is invoked from the CLI, against a `DB_HOST` of `localhost`,
`127.0.0.1` or `::1`, with an explicit `--confirm`, and it will not silently double up on a
populated database without `--reset`.

**Reaching production is deliberate** and prints a warning:
`npm run dev:prod-api`, or `RG_API_TARGET=https://ritagreenservices.com npm run dev`.

Other commands: `npm run typecheck` (`tsc -b --force`, so a warm cache cannot make the gate a
no-op), `npm run lint`, `npm test`, `npm run test:php`, `npm run build`, `npm run preview`.

---

## D4. Standing it up on the server — the operator path

The PHP tier is **not** part of the automated deploy. It is version-controlled and uploaded by
hand, so a frontend deploy can never disturb it.

1. **Create the database and user** in cPanel. The database is `ritagreen_os`; cPanel may prefix
   it with the account name.
2. **Import `server/schema.sql`** through phpMyAdmin. It is the from-scratch baseline, a single
   re-runnable file organised in build passes.
3. **Copy `server/config.sample.php` to `config.php` on the server** and fill it in. It is
   gitignored and holds every secret. Never commit it; CI has a guard that fails if you try.
4. **Upload the `*.php` files** to the document root, alongside `index.html`.
5. **Place `.htaccess`.** It handles SPA routing, re-injects the `Authorization` header that
   LiteSpeed otherwise strips before PHP sees it, and hard-blocks `^uploads/` so uploaded
   documents are only ever reachable through `files.php` behind an auth check. It also refuses
   `*.sql`, `*.md`, `migrations/`, `tests/` and the CLI tools; confirm `/schema.sql` is 403.
6. **Create the uploads directory** and confirm a direct request to `/uploads/<stored_name>` is
   refused.
7. **Add the cron jobs** (see D11). Each refuses HTTP unless an `X-Cron-Key` header (or `?key=`)
   matches `ALERTS_KEY`.
8. **Apply migrations**, after a restore-tested backup (rule 15). Set `define('RG_ENV',
   'production');` in the server's `config.php` first — `migrate.php` reads that, not `DB_HOST`,
   and will then refuse `--go` without `--production` and the database name typed in full. A
   `config.php` without the constant is treated as production, so the guard holds either way.
9. **Deploy the frontend** by pushing to `main`.

**Frontend deploy.** GitHub Actions runs `npm ci`, `npm run build`, then
`SamKirkland/FTP-Deploy-Action` over FTPS on port 21, uploading `dist/` **only**. PHP files,
`config.php` and `.htaccess` are excluded by design. The workflow carries an explicit warning
never to add `dangerous-clean-slate`, which would delete the entire API and the config from the
server (rule 16). The host runs Apache/LiteSpeed with PHP 8.4 and **has no Node**, so the build
must happen locally or in CI and ship as static artifacts.

**DNS and TLS.** Cloudflare records for this domain stay **grey-cloud (DNS only)** so cPanel can
issue SSL. That differs from every other project on the same hosting account, where the origin
holds Cloudflare Origin certificates that browsers reject and the zones must stay proxied. Do not
"fix" this one to match them.

---

## D5. Configuration

All server configuration is PHP constants in `server/config.php`. There is **no `.env` and there
are no `VITE_*` variables** — the only `import.meta.env` uses in the frontend are Vite's own
`DEV` and `PROD` flags. Nothing secret or configurable reaches the client bundle, and every
third-party call is proxied server-side. `server/config.sample.php` is the tracked template with
43 `define()` constants and `CHANGE_ME` placeholders.

| Group | Covers | Behaviour when unset |
|---|---|---|
| Database | host, name, user, password | The application cannot start; `config.php` ends by opening `$conn` |
| Mail | `MAIL_ENABLED`, `MAIL_FROM`, `MAIL_TO`, SMTP host/user/pass/port/security | `mail_enabled()` false — mail is still **queued**, and the drainer logs it as skipped |
| IMAP | `IMAP_HOST`, credentials, `IMAP_VALIDATE_CERT` | `inbox.php` returns 503 |
| Twilio | SMS and WhatsApp sender | `sms_enabled()` false; every attempt still recorded in `sms_log` as skipped |
| Stripe | secret key, webhook secret | Every `payments.php` route returns 503; deploying the file changes nothing |
| GoCardless | access token, webhook secret, `GC_ENVIRONMENT` | 503 on everything but the webhook; sandbox unless `live` |
| Google OAuth | client id and secret | `google_config` reports disabled; SSO routes 503 |
| OpenRouter | `OR_API_KEY`, `OR_MODEL` | `ai.php` returns 503 |
| OpenWeather | `OPENWEATHER_API_KEY` | `weather.php` returns 503 |
| Security policy | `ALLOWED_ORIGINS`, `ALERTS_KEY`, `MANDATORY_2FA`, `GK_DEBUG` | No CORS header emitted for an unlisted origin; cron scripts refuse every HTTP request when the key is unset or `CHANGE_ME` |

`server/config.local.php.example` is the development template: it points at a local database and
**disables every outbound integration**, so a local run cannot email a real worker or charge a
real card.

---

## D6. Identity, authentication and access — the whole chain

### Getting an account

There are three ways a login comes into existence, and **self-service is not one of them**.

- `auth.php?action=register` — public, rate-limited 6 per 10 minutes. It creates an **applicant**,
  a `consent_log` row, a broadcast notification and an office email. It does **not** create a
  user. Registering is applying for a job, not getting access.
- `users.php` POST — `super_admin` or `admin` creates a login with a role, an optional division,
  and optional `client_id` / `worker_id` links, both validated to exist and be non-deleted. Only a
  `super_admin` may create another `super_admin`. Password minimum 8 characters, bcrypt.
  A duplicate email, or a worker that already has a login, returns 409.
- Google SSO — `auth.php?action=google_callback` exchanges the OAuth code server-side, requires
  `email_verified`, and matches an existing non-deleted user. It **never auto-creates**; an
  unmatched email gets 403.

### Logging in

```
POST auth.php?action=login          rate_limit('login', 10, 300)
  → password_verify() against a bcrypt cost-12 hash
  → if user_2fa.enabled and no code supplied → {twofa_required: true}, no token issued
  → issue_token(): 32 random bytes, hex encoded (64 chars)
        only the SHA-256 hash is stored, in `tokens`, with user agent, IP and a 30-day expiry
        the plaintext is returned once and never stored server-side
  → the client keeps it in localStorage under `rgos_token`
```

Storing only the hash means a database disclosure does not yield usable sessions. Recording user
agent and IP is what powers the device list and per-session revoke in Settings. `last_seen_at` is
refreshed by `current_user()` on each authenticated request, throttled to at most one write a
minute per token — it used to be written only at issue, which made the session list show creation
time under a heading that reads as last activity.

There is no refresh token and no rotation. The token lives in `localStorage`, which is exposed to
XSS by design: the trade is that a bearer header avoids CSRF entirely.

**Session lifecycle.** Logout deletes the single token row. A password reset deletes **every**
token for that user. Deactivating a user deletes every token for that user and is refused if you
are deactivating yourself (409). `auth.php?action=sessions` lists live tokens; `revoke_session`
deletes one by id, scoped to the caller's own tokens.

**Second factor.** TOTP, RFC 6238, SHA-1, 6 digits, 30-second step, ±1 step drift, implemented in
pure PHP in `lib.php` because the host cannot install packages. Secrets live in `user_2fa`. With
`MANDATORY_2FA` set, `super_admin`/`admin`/`manager` accounts are flagged `must_setup_2fa` in the
login response until they enrol — the flag is advisory, and the API does not itself refuse those
users' requests.

**Idle lock.** `auth.php?action=unlock` re-verifies the password for the lock screen and issues
no new token, rate-limited 10 per 5 minutes after authentication.

**Password reset.** `forgot` always returns `{ok: true}` so the endpoint never reveals whether an
address exists. `reset` requires a 64-hex single-use token with a one-hour expiry, a password of
at least 8 characters, burns the reset row and revokes every session.

### Who may do what

Seven roles: `super_admin`, `admin`, `manager`, `trainer`, `editor`, `employee`, `client`. They
are declared in three places that must be kept in sync — `server/schema.sql` (the `roles`
reference table and the `users.role` ENUM), `src/providers/auth-provider.tsx` (the `Role` union),
and the `require_role()` call sites in `server/`.

**Role alone cannot express the boundary.** A worker's login is an ordinary account with
`users.worker_id` set, and a client portal login has `users.client_id` set; either may hold a
role such as `employee`. So anything gated on "internal staff" checks the *link*, not the role:

| Helper | Where | What it decides |
|---|---|---|
| `require_auth()` | `lib.php` | 401 unless a live token resolves to a non-deleted user |
| `require_role(...)` | `lib.php` | 403 unless the role is listed |
| `is_staff()` / `require_staff()` | `lib.php` | Internal staff: `super_admin`, `admin`, `manager` unconditionally, so a manager who also holds a worker record does not lock themselves out |
| `linked_worker_id()` | `lib.php` | The caller's own worker record, used to scope `/m` and field events |
| `service_scope()` | `lib.php` | Per-service query filter, opt-in per account via `users.scope_to_division`; `super_admin` and `admin` are never scoped |
| `can_view_zone()` | `lib.php` | The role × zone matrix in `zone_permissions`. **No row means no permission**, so an unconfigured zone is not readable by default |

Sensitive zones are GENERAL · EMPLOYMENT · COMPLIANCE · HEALTH · DISCIPLINARY · PAYROLL · LEGAL,
editable by a super admin. Two refusals are built into the editor: **denying a super admin a zone
is refused**, and **edit-without-view is refused as incoherent** — the account that fixes a
mistake must not be able to lose the screen that fixes it.

**Client-side gating is a user-experience guard and nothing more.** `src/lib/access.ts` is one
path-prefix map of route to permitted roles, plus `isStaff()`, `isClientAccount()` and
`isWorkerAccount()`. Both `RequireAuth` and `app-sidebar` read it, so a visible link always leads
somewhere the user may go, and someone who does not belong on a surface is redirected to their own
home rather than to a dead end. It mirrors the `require_role()` calls in `server/`. **The server
is the enforced boundary** and always will be. Until this landed, `RequireAuth` accepted a `roles`
prop that no route ever passed, so all three authenticated surfaces were gated identically on
"logged in".

### Rate limiting

`rate_limit($conn, $bucket, $max, $windowSec)` is a sliding window keyed on bucket plus client IP,
backed by `rate_limits`. `client_ip()` prefers `CF-Connecting-IP`, then the first hop of
`X-Forwarded-For`, then `REMOTE_ADDR`. Roughly 1% of calls opportunistically prune rows older
than a day. Buckets: `login` 10/5min · `forgot` 5/10min · `reset` 10/10min · `unlock` 10/5min ·
`register` 6/10min · `google` 20/5min · `contact` 5/10min · `booking` 8/10min · `ats` 8/10min ·
`offer` 20/10min (POST only) · `smoke` 30/5min · `intake` 120/5min.

---

## D7. The person spine

**A person exists once, permanently.** Candidate, worker, learner and user are roles that person
holds, not separate records.

```
PERSON  (people)
 ├── Candidate   applicants   — an application at a stage
 ├── Worker      workers      — a payroll number, an employment status
 ├── Learner     enrolments   — training records and certificates
 └── User        users        — a login with a role
```

`people` carries the identity — name, preferred name, date of birth, email, phone, address,
emergency contact — and `person_services` carries person × service with the calculated eligibility
verdict. `workers`, `applicants`, `users` and `enrolments` each carry `person_id`, `NOT NULL`
since migration 004.

**`find_or_create_person()` in `lib.php` fronts every creation path**: the public application,
self-registration, the learner handoff, worker creation and user creation. A login linked to a
worker inherits that worker's person. `people.match_key` is UNIQUE, so **the database itself
refuses a second person with the same identity** — error 1062, verified.

**Matching rules** live in `pure.php` (`rg_person_match_key`, `rg_group_people`,
`rg_group_is_ambiguous`, 34 assertions): match on email, then phone, **never on a name**, and
refuse to merge a group whose names disagree. Ambiguous matches go to a review list; they are
never merged automatically. Matching on a name would merge every John Smith.

**The backfill.** `server/backfill-people.php` created people from the existing records and linked
every one. Verified against a real database: 22 people from 23 source records, no duplicates.

**Payroll numbers.** `next_payroll_no()` sits on `next_seq()` — format `RG-000001`, assigned on
worker creation, unique, permanent, never reused.

**What this replaced.** `ats.php?action=onboard` used to read the applicant row and *copy* the
identity fields into a new `workers` row; `from_learner` created a third copy and, where the
learner had no email, fabricated `<name>@learner.invalid`. The `applicants.worker_id` foreign key
linked the copies after the fact, but from that moment the rows drifted independently — a phone
number corrected on the worker record never reached the applicant record, and compliance,
documents and training attached to whichever copy happened to be in front of the person entering
them. Verified after the fix: two applications, an onboarding and a login creation for one human
now produce **one** person record carrying two applications, one employment record and one login.
The fabricated `@learner.invalid` address survives only to satisfy a `NOT NULL` column and is
explicitly not treated as an identity.

---

## D8. The operational lifecycle, end to end

This is the product as a story: a stranger arrives on the website, and eighteen chapters later
their evidence pack is exported for an inspector.

### 1. A stranger arrives — the public site

The 17 public pages carry the marketing story (Home, Services, ServiceDetail, Sectors, About,
Accreditations, Resources, Article, Legal), the two commercial front doors (Contact, Quote,
RequestCover) and the two recruitment front doors (Careers, Apply). Two further pages exist for
people who arrive holding a link rather than an intention: **Verify**, which resolves a
certificate id, and **Offer**, which resolves a shift-offer token.

Four public writes exist, all rate-limited, all consent-logged where they concern a person:

| Route | Creates | Also does |
|---|---|---|
| `contact.php` POST | `contact_enquiries` | Office alert with the sender as `Reply-To`, plus an autoresponder. CR/LF stripped from every header value. A message beginning `CAREERS APPLICATION` switches both to careers wording |
| `bookings.php` POST | `service_requests` | Links to an existing client by email, writes `consent_log`, raises a broadcast notification, emails the office |
| `ats.php` POST | `applicants` (source `careers`) | `consent_log`, broadcast notification, office email |
| `auth.php?action=register` | `applicants` (source `self-registration`) | The same three |

Enquiries land in `enquiries.php` for the office and can be marked handled. Cover requests land in
`bookings.php`, where `confirm` turns one into an **open shift** and emails the requester back.

### 2. Someone applies — recruitment intake

Four intake routes, all of which resolve to **one person**:

1. `/apply` on the public site.
2. Self-registration through `auth.php?action=register`.
3. **Google Forms**, through `forms-intake.php`. Apps Script POSTs with a shared secret in the
   `X-RitaGreen-Secret` header, compared with `hash_equals`; an integration with no secret
   configured refuses every submission. Field mapping is a configuration table
   (`integration_field_maps`) so a changed form needs no code change, and matching tolerates case,
   spacing and the asterisk Google adds to required questions, because an exact-string join breaks
   silently. **The raw payload is stored before any mapping** and kept whether or not it maps: a
   submission that fails to map is a person who applied and would otherwise vanish. Redelivery is
   safe, keyed on the form's own response id.
4. **Academy handoff** — `ats.php?action=from_learner`, entering the pipeline at stage `screened`.

`integrations.expect_within_hours` is what makes silence detectable: the Action Centre reports any
integration past its window, because a form that stops feeding the system looks exactly like a
quiet week.

### 3. The pipeline — candidate to worker

`applicants.stage` runs applied → screened → interview → vetting → offer → onboarded → rejected.
Staff move the stage, add tasks and tick them off. `ats.php?action=onboard` creates the worker,
marks the applicant `onboarded`, and seeds the five-item onboarding checklist; it 409s if the
applicant is already onboarded. Since the person spine, **onboarding links rather than copies** —
onboarding an applicant creates zero new person rows.

### 4. The worker record

`workers` holds the employment record: role (carer / nurse / cleaner / guard / trainer), division,
status, right to work, DBS status and expiry, DBS Update Service flag, recheck due, SIA number and
expiry, pay rate in pence, pay type (paye / umbrella), holiday hours, availability (days, nights,
weekends), travel radius and home postcode.

- **Pay fields are zone-gated.** `workers.php` GET is staff-only, and `pay_rate_pence`, `pay_type`
  and `holiday_hours` are stripped from the response for anyone outside `super_admin`, `admin`,
  `manager`. Until this was closed, any authenticated account — a client portal login included —
  could read the full workforce list with pay rates and DBS/SIA detail.
- **Skills** are a catalogue plus a junction (`skills`, `worker_skills`), and drive shift matching.
- **References** (`worker_references`) run requested → received → verified → gap_explained, with a
  gap note, because an unexplained employment gap is itself a finding.
- **Status is derived, not typed.** `rg_derive_worker_status()` in `pure.php` recomputes
  `workers.derived_status` on the daily cron from workflow events. The manual column stays as the
  *intent*; the derived one is what is actually true. Verified against fixtures: all ten read
  "active" manually, only four genuinely are.

### 5. Documents

`files.php` is the whole document centre and the whole file is gated to
`super_admin`/`admin`/`manager`/`editor`, with delete narrowed to office roles.

- Upload is `multipart/form-data`, capped at **10 MB**, with the MIME type detected from file
  contents with `finfo` rather than trusted from the client, matched against an allowlist of nine
  types.
- The stored filename is **16 random bytes** plus the extension derived from the detected MIME, so
  the original name never reaches the filesystem.
- Files sit under `server/uploads`, which `.htaccess` blocks at `^uploads/`; the only way to read
  one is `files.php?action=download`, which streams it behind the auth check.
- `?action=extract` pulls plain text out of txt, csv, docx, xlsx and PDF, truncated at 12,000
  characters — that is what makes an uploaded certificate searchable.
- Migration 010 gave documents real metadata: `issued_on`, `expires_on`, `person_id`,
  `verification_status`, `verified_by`, `verified_at`. A document is now evidence with a lifecycle,
  not a file with a category.

### 6. Compliance — the engine

This is the centre of the product. **Status is calculated from evidence and rules. It is never a
field anyone can edit, and no endpoint accepts one.**

**The catalogue and the rules.** Migration 003 seeds 13 requirements and 26 rules across the four
services — requirement × service × role × weight. Weight is `RG_MANDATORY`, `RG_RECOMMENDED` or
`RG_OPTIONAL`: mandatory blocks, recommended surfaces without blocking, optional is recorded only.
The same requirement can carry different weights in different services — first aid may be
mandatory for Care and Security and recommended for Cleaning — and that is configuration owned by
the business, not code owned by a developer (rule 23).

**The evaluator.** `rg_evaluate_eligibility()` in `server/pure.php` takes a rule set and a
person's evidence and returns a verdict, a score, the blocking list, the warning list and a
human-readable reason. It is pure — no database, no config, no globals, injectable clock — and
carries 48 assertions in `server/tests/run.php`, including that a credential expiring **today**
does not stand someone down.

**The loader.** `rg_assess_person()` in `compliance-lib.php` loads the rules and the evidence,
calls the evaluator, and records the verdict in `person_services`.

**The API.** `compliance.php?action=eligibility` returns one service's verdict —
`{status, eligible, score, blocking[], warnings[], items[], reason}` where status is `eligible`,
`eligible_with_warnings` or `not_eligible`. `?action=eligibility_all` returns a separate verdict
for each of the four services. The interface for it is
`src/components/app/eligibility-dialog.tsx`, opened from the Workers table, showing all four
services with **every** blocking reason rather than the first one.

Verified on real data: one worker is eligible for Care and Cleaning and blocked for Security on a
lapsed SIA licence. One credential, one service — exactly as intended, and impossible to express
in the old schema where `workers.division` was a single-value ENUM.

**Expiry classification.** `rg_classify()` in `pure.php` buckets an expiry date as `expired`,
`expiring` (≤30 days), `valid` or `unknown`, with the boundary days covered by tests. It was
previously duplicated in four endpoint files under four names.

**Right to work is a check, not a flag.** It used to be `right_to_work TINYINT(1)` — no method, no
date, no verifier, no evidence, no expiry, no audit trail, on the one field with the sharpest legal
edge. Now `right_to_work_checks` (migration 006) carries method (`online_share_code`,
`manual_document`, `idsp_digital`, `other`), method note, reference, check date, outcome
(`pending`, `valid`, `invalid`, `requires_follow_up`), follow-up flag, recheck due date, an
evidence file reference and the verifier. `right-to-work.php` records, lists, reports what is due
within a window, and verifies.

**Recording and verifying are separate actions on purpose.** The person who collects a document is
often not the person who signs it off, and "verified" with nobody's name against it is not
verification. A check still `pending` cannot be verified — signing that off would be signing off
nothing — and a check already verified cannot be verified twice. A future-dated check is refused.
The legacy boolean was imported by migration 006 as an **unverified historical assertion with a
note saying so**; presenting it as a completed check would have been inventing evidence.

**Every other credential** runs the same shape. `verifications` plus `verifications.php` implement
requested → received → under_review → verified → rejected → expired, with only legal transitions
permitted and the refusal naming what *is* possible. Verifying records **what was checked**,
because "checked the SIA public register" and "saw a photocopy" are not the same assurance and only
that field can tell them apart later. SIA numbers carried over as `received`, not `verified` — a
number was recorded, no register check was captured.

**Renewals.** `expiry-alerts.php` scans DBS and SIA expiries on the worker row, `certifications`
rows with an expiry, and company `insurance` policies, buckets each into a 90 / 60 / 30-day or
`expired` tier, and records each `(credential, tier)` pair in `compliance_reminders` so a tier
fires exactly once. Expired items re-nag at most weekly. The office gets one digest; workers with
a valid email get a personal nudge at the 30-day and expired tiers only.

Two corrections worth knowing, because both were silent failures. **Reassessment used to sit after
an early exit** taken when no reminder was due, so on a quiet day nothing was recalculated and
eligibility went stale on exactly the days nobody was watching; it now runs first, and
unconditionally. And **reassessment used to send mail** — folding it into the alerts cron made
recalculating and emailing the workforce one action, so running it during a deployment sent real
reminders to real people at a time nobody chose. `--reassess-only` now separates them.

**Evidence packs.** `compliance.php?action=pack&worker_id=N` returns the worker, their credentials,
references and documents, with who generated it and when — the bundle a CQC or SIA ACS inspector
asks for.

### 7. Scheduling and the deployment gate

**Creating work.** `shift_templates` hold recurring weekly patterns; `?action=generate` creates
open shifts from every active template for 1–12 weeks ahead, skipping duplicates by division,
title and start time. `scheduling.php?action=open` creates a single unassigned shift.

**Matching.** `?action=match` returns eligible, available, clash-free workers ordered by fewest
upcoming shifts, bucketed as `nights` (start ≥20:00 or <06:00), `weekends` or `days`.

**The gate.** `rg_deployment_check()` in `compliance-lib.php` is one reusable eligibility check,
called by `scheduling.php` on assignment. It replaced a hard-coded DBS-for-Care /
SIA-for-Security pair with the **configured rule set**, and it names **every** blocking
requirement at once rather than surfacing them one 409 at a time. An assignment that fails is
refused with HTTP 409 and the reason; an overlapping scheduled shift is refused as a clash.
Verified: a worker with a lapsed SIA is refused with the exact reason and the full blocking list;
an eligible worker is assigned.

**A refused assignment is audited** as `shift.blocked` with the reason, so the pattern of what is
blocking deployments is visible rather than only felt.

**Broadcast.** `?action=broadcast` mints a 40-hex-character token per eligible worker in
`shift_offers` and messages each a `/offer/<token>` link over WhatsApp, falling back to SMS. The
shift must be `open` (409 otherwise).

**Acceptance without a login.** `offer.php` is public; the token in the URL is the only secret and
must match `^[a-f0-9]{40}$`. Acceptance is an atomic conditional update —
`UPDATE shifts SET worker_id = ?, status = "scheduled" WHERE id = ? AND status = "open"` — so
exactly one worker wins the race, the winner expires every other outstanding offer for that shift,
and losers get `{result: "filled"}`. An offer whose status is not `sent` returns
`{result: "closed"}`.

A worker can also claim an open shift from `/m` — `me.php?action=accept` is the same atomic claim,
409 if it was just taken, 403 if it belongs to another division.

### 8. On site — field operations

`shift_events` is an append-only event stream with `lat`/`lng`. Kinds: `clock_in`, `clock_out`,
`check_in`, `panic`, `note`, `task`, `signoff`. A `clock_out` flips a `scheduled` shift to
`completed`. A `panic` raises a broadcast notification and, when mail is enabled, emails `MAIL_TO`
with a `[PANIC]` subject — one of only three sends that stay synchronous, because a queued panic
alert is not an alert.

`field.php?action=live` is the office board for today, deriving a `field_status` of `unfilled`,
`completed`, `on_site`, `overrunning`, `no_show` (30 minutes past start, not clocked in), `late`
or `scheduled`, plus a panic flag. The derivation lives in `pure.php` and is tested.

**Scoping is by shift ownership, not by role.** Staff may post against any shift; a worker may
post **only** against a shift assigned to them, and is refused 403 otherwise. Until this was
added, any authenticated account — a client portal login included — could post events against any
shift id, panic alerts included.

### 9. Time and pay

`timesheets` hold worked time awaiting approval; minutes are computed **server-side** from the
times, so the client cannot supply them, and an entry must be a valid date, end after start, and
at most 16 hours. `employee` may create, office roles approve.

`pay-lib.php` computes pay identically everywhere it is needed — `payroll.php`, `margin.php` and
`me.php?action=payslip` all call `pay_compute()`, over **approved timesheets only**. Rules come
from `pay_rules` with defaults of night 1.30, weekend 1.25, bank holiday 2.00, overtime 1.50;
`pay_day_premium()` takes the highest applicable premium — bank holiday beats weekend beats night.
Overtime starts after 40 hours in an ISO week; holiday accrues at 0.1207 of worked hours.
`bank_holidays` carries the England and Wales calendar. Both payroll routes write an audit entry.

**The system does not run payroll.** It produces pay records and payslips; running payroll and
accounting are explicit non-goals.

### 10. Money in — the commercial pipeline

`clients` carry a pipeline stage (lead → qualified → proposal → won → lost), a status, an account
manager and an SLA response time; `sites` are their locations; `rate_cards` the agreed charge per
division and role; `client_services` (migration 014) the services they buy, split out of the old
comma-separated string.

**Quote → invoice.** `quotes` are numbered `QUO-<year>-####` from the atomic counter.
`?action=accept` creates a mirroring invoice and links it back, 409 if already accepted; a quote
already accepted cannot have its status changed. Invoices are `INV-<year>-####`, VAT at 20%,
payment terms 30 days. The invoice list returns aged debt in current / 1–30 / 31–60 / 61–90 / 90+
buckets with a chase list sorted worst-first, and it strips `pay_token`, exposing only a boolean
`has_pay_link` so the public pay-link secret never appears in a list response.

**Card payment.** `payments.php` is hosted Stripe Checkout — no card data touches the server.
`paylink` mints a 40-hex `pay_token`; `public_checkout` 302-redirects the holder of that token to
Stripe and 409s on an invoice already paid or void. The **webhook verifies properly**: the
`t=`/`v1=` pairs are parsed, HMAC-SHA256 of `t.payload` compared with `hash_equals` against
`STRIPE_WEBHOOK_SECRET`, events older than 300 seconds rejected as replays, and on
`checkout.session.completed` the invoice total is **re-read from the database** and marked paid
only when `amount_total` matches exactly and the currency is `gbp`. A mismatch is written to the
audit log as `invoice.paid.mismatch` and the invoice is left unpaid.

**Direct Debit.** `gocardless.php` uses the Billing Requests flow over raw HTTPS with no SDK,
sandbox unless `GC_ENVIRONMENT` is `live`. `setup` returns the hosted mandate page, `complete`
resolves the mandate, `charge` collects against an invoice and refuses an already-paid invoice, an
invoice with no client, or a client with no active mandate. The webhook verifies HMAC-SHA256 of
the raw body and returns the non-standard **498** on a bad signature. On the **first** settlement
of a payment it credits the amount **actually collected**, capped at the invoice total, and marks
the invoice paid only once the credited total covers it — writing `invoice.paid.gocardless` or
`invoice.part_paid.gocardless`. Previously any confirmed collection set `amount_paid_pence =
total_pence` unconditionally, so a part-collection reported as paid in full and a redelivered
webhook re-credited the same payment. Reading the row's prior status before updating it is what
makes the handler idempotent.

**Credits.** `credit-notes.php` numbers `CN-<year>-####`, caps the credit at the invoice's
remaining balance, and voids the invoice when the full remainder is credited.

**Recurring work.** `contracts` are commercial service agreements with a rate, a frequency
(weekly / fortnightly / monthly / quarterly, annualised ×52/26/12/4), an SLA and a renewal date
with `days_to_renewal`. `renew` extends from `GREATEST(end_date, CURDATE())`, so renewing a lapsed
contract does not backdate it. **These are client contracts, not employment contracts** — the two
are deliberately distinct in name and model.

**Margin.** `margin.php` reconciles revenue against labour cost per division: invoice net raised
in the period excluding void and draft, less credit notes, against gross labour from
`pay_compute()` so premiums and overtime are included.

### 11. Training and certificates

`programmes` are Academy courses with CPD hours and a draft / published / archived status;
`training_providers` (migration 016) covers training done elsewhere, so `programme_id` is nullable
and an enrolment can name an `external_course` with its own completion and expiry dates.
`enrolments` link a learner to a programme.

**Enrolment resolves a person** from an explicit id, or an email, or a phone. **With only a name it
records the enrolment unlinked rather than guessing**, because matching on a name would merge every
John Smith. Those unlinked enrolments are linked by hand as the Academy module is rebuilt.

`?action=complete` issues a certificate numbered `RG-<year>-#####` and is **idempotent** — an
enrolment that already holds a certificate returns the existing id with 200.

**Public verification** is `certificates.php?action=verify&id=` and the Verify page at
`/verify/:id`; `?action=badge` returns the same fact as an **Open Badges 3.0 / W3C Verifiable
Credential** JSON-LD assertion. Both require `status = "issued"` and 404 otherwise. Both disclose
the learner's name and course to anyone holding the id — which is the point of a public
verification page — but the id is the sequential number from `next_seq`, so ids are enumerable.

### 12. When something goes wrong — incidents

`incidents` cover accident, incident, near_miss, safeguarding and complaint, at low / medium /
high / critical severity, referenced `INC-<year>-####`, with RIDDOR and safeguarding flags and a
free `occurred_at`. A `safeguarding` type **forces** the safeguarding flag. Safeguarding, RIDDOR
or critical incidents raise a broadcast notification and email the office. `incident_actions` are
the follow-ups, each individually markable done. Creation is open to office roles plus `trainer`
and `employee`, because the person who saw it is the person who should record it.

### 13. Talking to people — communications

**In-app.** `notifications` is the bell: a user sees their own plus broadcasts (`user_id IS NULL`),
and the unread count tracks only their own targeted rows. `notify()` with a null user id is a
broadcast to all staff. `comms.php` posts announcements to everyone or to one division.

**Email.** Nothing in a web request opens an SMTP socket. `queue_mail()` and
`queue_templated_mail()` write to `email_queue`; `send-queued-mail.php` drains it on cron,
**claiming each row before attempting it** so overlapping runs cannot double-send, with capped
retries and a batch limit, recording every attempt in `email_log` — including skips, because "did
we tell them" is a question asked months later. `email_templates` ships seven seeded templates —
application received, application unsuccessful, document request, credential expiring, credential
expired, right-to-work recheck, worker approved — whose wording is editable without a deployment.
Delivery failure is visible as `email_queue.last_error` plus a terminal `email_log` row.

**Queueing is not gated on `mail_enabled()`.** The queue records what the system *intended* to
send; the drainer decides what can actually go out and logs "skipped" when mail is off. Gating at
the call site would mean a disabled mailer leaves no trace that somebody should have been
contacted.

Three sends stay synchronous, deliberately and with the reason in the code: the lone-worker panic
alert, password reset, and interactive mailbox replies.

`smtp_send()` is a minimal SMTP client written directly over `fsockopen` — `AUTH LOGIN`, optional
STARTTLS, peer verification on by default, dot-stuffing, CR/LF stripped from subjects and extra
headers to block header injection. There is no PHPMailer because there is no Composer.

**SMS and WhatsApp.** Twilio, via `send_sms()` (480 chars), `send_whatsapp()` (1500 chars) and
`message_worker()`, which prefers WhatsApp and falls back to SMS. UK numbers are normalised to
E.164. Every attempt is recorded in `sms_log`, including skipped ones.

**The shared mailbox.** `inbox.php` is a live IMAP client with SMTP replies, threading via
`In-Reply-To`/`References`, `\Seen` and `\Answered` flag management, and a CRM contact lookup on
the sender. TLS certificate validation is on unless explicitly disabled. It is gated to
`super_admin`/`admin`/`manager` for every route — reading it exposes every inbound enquiry, and
replying sends as the company. Until that gate was added, any authenticated account could do both.

### 14. The client's view — the portal

`portal.php` requires an authenticated user whose `client_id` is set, and hard-scopes every query
to it. The single GET returns the client, their cover requests, their invoices (drafts excluded),
their contracts, and the distinct workers on upcoming scheduled shifts with a derived `cleared`
boolean. `?action=request` raises a cover request under the caller's own client id;
`?action=issue` logs a `complaint` incident at medium severity and returns its reference. Neither
POST re-checks a role, but the contact name and email are taken from the token's user row rather
than from the request body.

### 15. The worker's view — `/m`

`me.php` requires an authenticated user whose `worker_id` is set. The default GET returns the
worker, their compliance, their shifts and the open shifts they could claim. `?action=accept`
claims one atomically. `?action=payslip` returns their own pay lines for a period — their own
only.

Clock-in, clock-out, check-in and panic are **not** here: they go through `field.php`, which reads
`worker_id` off the token and refuses a shift that is not theirs.

**As a PWA**, `/m` is installable and works offline for reads. `vite-plugin-pwa` generates the
service worker with a build-hash precache manifest, so a deploy invalidates exactly what changed
and offline survives it; the manifest carries 192 px, 512 px, a separate maskable 512 px and a
180 px `apple-touch-icon.png` (iOS ignores an SVG). `me.php` and `notifications.php` are cached
NetworkFirst with a 5-second timeout; **the rest of the API is never cached**, because stale
compliance data would mean showing an eligibility answer that is no longer true. The update prompt
is explicit and never automatic — swapping the app out from under someone part-way through
clocking in is its own failure. The offline banner says what still works rather than only that
something is wrong.

**There is no offline write queue, by decision rather than omission.** Writes require
connectivity: a queued clock-in that turns out to be invalid when it lands is worse than being
told plainly that you are offline.

The install payload was cut from 14.5 MB across 115 precache entries to 1.15 MB across 67 by
removing 37 MB of orphaned template assets and excluding the office-only chunks — a field worker
should not download a charts library, a calendar and a PDF engine.

### 16. Seeing the whole thing — reporting and insight

| Surface | What it answers |
|---|---|
| `dashboard.php` | The command centre, filterable by division: programme, enrolment, session, certificate and open-shift counts, outstanding invoice pence, revenue by division, and DBS/SIA expiries within 30 days |
| `analytics.php` | Revenue by division, six-month trend, jobs by status, quote win rate, workforce by division, compliance RAG counts, group totals |
| `margin.php` | Revenue against labour cost, per division |
| `reports.php` | Five export packs: `client_kpi`, `cqc_pack`, `sia_acs`, `coshh`, `skills_for_care` |
| `compliance.php?action=pack` | One worker's full evidence bundle |
| `action-centre.php` | What needs attention today, grouped by urgency rather than by module, and **entirely derived** — there is no action table to fall out of step, so an item cannot linger after the thing it describes is fixed |
| `system-health.php?action=workload` | Staff workload. Not a performance measure: work can only be moved before somebody quietly drowns in it if it is visible |

CSV and PDF are rendered in the browser from data already fetched (`src/lib/csv.ts`,
`src/lib/pdf.ts`), which is why `governance.php?action=log_export` exists — the client tells the
server an export happened, with the user, scope, purpose, record count and IP, because that is the
only point at which the server can know.

### 17. Governance

| Mechanism | Where | Behaviour |
|---|---|---|
| Audit log | `audit_log`, `audit()` | Migration 009 added `entity_type`, `entity_id`, `before_json`, `after_json`, `reason`, `ip`, `user_agent` and three indexes. `audit()` kept its signature, so every existing call site gained IP and agent automatically. A record's own history is retrievable by entity; **reading the log with a filter is itself audited**, but the routine full listing is not, so the log does not fill with entries about reading the log |
| Deletion approval | `deletion_requests`, `governance.php` | Staff request with a reason of at least 10 characters; somebody else approves. **Four eyes enforced — the approver may not be the requester.** Archive is the default disposition. Verified: an admin cannot approve their own request |
| Soft delete | `deleted_at` | Migration 013 extended it to applicants, invoices, incidents, timesheets and shifts, and **every list and count query filters on it** — an archive that leaves the row visible is not an archive |
| Retention | `retention_policies` | Eight record types seeded with **NULL periods, deliberately**: the values belong to the business and its legal advice. *The sweep that acts on them is not built* |
| Export log | `export_log` | Every export recorded against a user and a purpose |
| Zones | `zone_permissions`, `can_view_zone()` | Role × zone matrix; no row means no permission |
| Subject rights | `gdpr.php` | Export returns everything held for an email address — enquiries, service requests, consent log, client and worker records. Erase anonymises enquiries and service requests and deletes consent rows; **client and worker records are not erased** — employment and contract records have an independent legal basis — but any match is surfaced under `needs_manual_review`. *It erases immediately, with no approval step* |
| Consent | `consent_log` | Written by every public form submission, with source, IP and timestamp |
| Health | `system-health.php` | **Live checks, not a stored status** — a health table goes stale exactly when it matters. Covers database, pending migrations, mail, the queue drainer, integrations, eligibility freshness, storage and backups |
| Backups | `backup.php` | Gzipped `mysqldump` to a directory one level **above** the document root, pruned to 14 days. Reports clearly rather than failing silently when `shell_exec` or `mysqldump` is unavailable |

Two health checks are worth calling out because they refuse to lie. **Unapplied migrations are
visible** — a deploy that ships migration files without running them looks completely normal until
something fails oddly, so `system-health.php` compares the directory against `schema_migrations`.
And **restore testing is reported as a WARN that cannot be inferred**, rather than assumed to be
fine: a backup nobody has restored is a hope, not a backup, and the check says so rather than
staying silent.

### 18. Content and the marketing site

`blog.php` and `testimonials.php` manage the public content, with public reads and
`super_admin`/`admin`/`manager`/`editor` writes. Reading time is auto-computed at 200 words per
minute. Both run an idempotent `CREATE TABLE IF NOT EXISTS` on every request, so they
self-migrated on first deployment — the only two of fifty tables created that way, which is noted
in `docs/SCHEMA.md` §6.5 as an inconsistency rather than a pattern to copy.

`ai.php` is the Content Studio's engine: an OpenRouter proxy where **the key stays server-side and
never reaches the browser**, 503 when unconfigured, 502 on any upstream failure. Any authenticated
user of any role can spend tokens through it, which is a cost-control gap rather than a security
one.

`weather.php` proxies OpenWeather for the shift advisory, constrains `city` to
`^[A-Za-z .,'-]+$` and 60 characters so the proxy cannot be pointed at another host, and caches
responses for 15 minutes.

---

## D9. The data model

Fifty tables in the baseline (`server/schema.sql`), twenty more added by migrations 001–018. Full
column detail is `docs/SCHEMA.md`; this is the map.

**Baseline, by domain**

| Domain | Tables |
|---|---|
| Identity and security (8) | `roles` `users` `tokens` `user_2fa` `password_resets` `audit_log` `rate_limits` `counters` |
| Clients and sites (5) | `clients` `sites` `rate_cards` `service_requests` `mandates` |
| Workers (4) | `workers` `skills` `worker_skills` `worker_references` |
| Compliance (5) | `certifications` `insurance` `compliance_reminders` `incidents` `incident_actions` |
| Recruitment (2) | `applicants` `onboarding_tasks` |
| Training (4) | `programmes` `enrolments` `sessions` `certificates` |
| Scheduling (5) | `shifts` `shift_templates` `shift_offers` `shift_events` `jobs` |
| Time and pay (4) | `timesheets` `pay_rules` `bank_holidays` `contracts` |
| Commercial (5) | `quotes` `invoices` `credit_notes` `dd_payments` `files` |
| Governance (2) | `consent_log` `contact_enquiries` |
| Comms (4) | `announcements` `notifications` `sms_log` `items` |
| Content (2) | `blog_posts` `testimonials` |

`items` is a two-column smoke-test table left from the first API check. Nothing reads it.

**Added by migration**

| Table | Migration | Purpose |
|---|---|---|
| `people` | 001 | The identity. One row per human, forever |
| `person_services` | 001 | Person × service, carrying the calculated eligibility verdict |
| `compliance_requirements` | 003 | The catalogue of things that can be required — 13 seeded |
| `compliance_rules` | 003 | Service × role × requirement × weight — 26 seeded |
| `right_to_work_checks` | 006 | Right to work as evidence: method, date, outcome, verifier, recheck |
| `email_queue` `email_log` `email_templates` | 008 | Mail out of the request path, with a delivery record |
| `employment_contracts` | 010 | Employment contract lifecycle and Acas particulars as queryable fields |
| `integrations` `integration_field_maps` `integration_submissions` | 011 | Google Forms intake, configurable mapping, raw payload retention |
| `deletion_requests` `export_log` | 012 | Four-eyes deletion approval, logged exports |
| `client_services` | 014 | Client × service, replacing the comma-separated string |
| `zone_permissions` | 015 | The role × sensitive-zone matrix |
| `training_providers` | 016 | External training, so `programmes.id` need not exist for a record to |
| `leave_records` `retention_policies` | 010 | Leave and absence in hours; the retention policy catalogue |
| `verifications` | 017 | One verification lifecycle for every credential |

**Seeded by the baseline:** 7 roles, 1 super-admin account, 4 pay rules, 8 bank holidays, 11
skills and 1 demo certificate.

**Employment records.** `employment_contracts` carries version and `supersedes_id` — a signed
contract is evidence of what was agreed at a point in time, so it is superseded rather than
edited — and holds the Acas particulars as **fields**: job title, place of work, pay, hours,
holiday, sick pay, notice, probation, training, pension. Storing them only inside a PDF makes them
unqueryable and unalertable. Its status runs draft → sent → viewed → accepted → signed → declined
→ expired → superseded with an access token; *the schema and versioning are in, the endpoint and
document generation are not*.

**Leave.** `leave_records` plus `leave.php` count in **hours, not days**, because shift work is
not made of whole days. It records holiday taken, holiday carried over, holiday pay, payments in
lieu, sickness, other leave and return-to-work discussions. **Overlapping leave is refused**, and
the refusal names the dates that clash. A holiday balance with no entitlement recorded returns
`null` rather than a large negative number, which would read as "overdrawn" when it means "nobody
set this up".

**Known schema debt**, in full in `docs/SCHEMA.md` §6: the division ENUM copy-pasted across
thirteen tables plus three near-duplicate variants; `clients.divisions` as a CSV, now split but
kept in step until nothing reads it; tables still lacking `deleted_at`; two tables created by
their own endpoints; and the places the TypeScript types and the SQL disagree.

---

## D10. The API at a glance

54 application endpoint files, four key-gated cron scripts, four libraries and four CLI tools. Auth posture in one table; full
routes, parameters and return shapes in `docs/API.md`.

| Endpoint | Auth posture |
|---|---|
| `auth.php` | Mixed — login, forgot, reset, register, the three Google routes and logout are public and rate-limited; the rest need a token |
| `users.php` | `super_admin` / `admin`. Only a super admin creates a super admin |
| `me.php` | Auth **and** a linked `worker_id` |
| `portal.php` | Auth **and** a linked `client_id` |
| `workers.php` | Staff to read, pay fields office-only; office to write |
| `ats.php` | Public POST with no action; staff reads; office writes (`from_learner` also `trainer`) |
| `right-to-work.php` · `verifications.php` | Staff to read, office to write |
| `compliance.php` | Auth to read (including the pack); office to write |
| `leave.php` | A worker sees and requests only their own; staff see all |
| `scheduling.php` · `shift-templates.php` | Auth to read; office to write |
| `field.php` | Staff for the board; a worker only for their own shifts |
| `offer.php` | **Public** — a 160-bit token in the URL is the secret |
| `timesheets.php` | Auth to read; `employee` may create; office approves |
| `payroll.php` · `margin.php` · `analytics.php` · `reports.php` · `enquiries.php` | Office roles |
| `clients.php` · `quotes.php` · `invoices.php` · `credit-notes.php` · `contracts.php` · `jobs.php` · `insurance.php` · `references.php` · `skills.php` · `incidents.php` · `comms.php` | Auth to read; office to write (incidents also `trainer`/`employee` to create; jobs status also `employee`) |
| `bookings.php` | **Public POST** with no action; staff reads; office writes |
| `payments.php` · `gocardless.php` | **Webhooks public but cryptographically verified**; public pay link by token; office for the rest |
| `academy.php` | Auth to read; office plus `trainer` to enrol and complete, plus `editor` to create and publish |
| `certificates.php` | **Fully public** — verification and Open Badges |
| `blog.php` · `testimonials.php` | **Public reads**; `super_admin`/`admin`/`manager`/`editor` writes |
| `contact.php` | **Public**, rate-limited |
| `files.php` | Whole file gated to `super_admin`/`admin`/`manager`/`editor`; delete narrowed to office |
| `inbox.php` | `super_admin` / `admin` / `manager` |
| `gdpr.php` · `audit.php` | `super_admin` / `admin` |
| `governance.php` | Staff; approvals and zones are admin or super admin |
| `action-centre.php` | Staff only |
| `system-health.php` | `super_admin` / `admin` / `manager` |
| `forms-intake.php` | **Webhook: shared secret in `X-RitaGreen-Secret`, no bearer token.** Views staff only |
| `notifications.php` · `dashboard.php` · `ai.php` · `weather.php` · `api.php` | Auth, any role |
| `expiry-alerts.php` · `send-queued-mail.php` · `backup.php` | **Cron.** CLI, or HTTP with an `X-Cron-Key` header (or `?key=`) matching `ALERTS_KEY` |
| `migrate.php` · `seed.php` · `backfill-people.php` · `create-admin.php` | **CLI only.** They refuse to run over HTTP at all |

**Four auth mechanisms are in use, and they are not interchangeable:** a bearer token; a shared
secret compared with `hash_equals` (`forms-intake.php`, the cron scripts); an unguessable
160-bit token in a URL (`offer.php`, `payments.php?action=public_checkout`); and a cryptographic
signature over the request body (both webhooks).

`docs/API.md` §55 is the complete public surface — every route reachable with no bearer token,
grouped by what actually protects it — and §56 lists every place a file's own header comment
understates what its code does.

---

## D11. Scheduled work

Three cron scripts. Each runs from the CLI with no key, or over HTTP with `ALERTS_KEY` in an
`X-Cron-Key` header (or `?key=`, kept for existing crontabs; the header wins when both are
sent); a key that is unset or `CHANGE_ME` refuses every HTTP request. A key in a query string
lands in the access log and a header does not, so prefer the CLI trigger, then the header.

| Script | Cadence | What it does |
|---|---|---|
| `expiry-alerts.php` | Daily | **First, unconditionally:** recalculates eligibility for the services a person is actually intended for, and recomputes `workers.derived_status`. Then buckets every DBS, SIA, certification and insurance expiry into 90 / 60 / 30 / expired tiers, dedupes each `(credential, tier)` through `compliance_reminders`, sends the office one digest and workers a personal nudge at the 30-day and expired tiers. Expired items re-nag at most weekly. `--reassess-only` recalculates and sends nothing |
| `send-queued-mail.php` | Frequently | Drains `email_queue`, claiming each row before attempting it so overlapping runs cannot double-send. Capped retries, batch limit, every attempt written to `email_log` including skips. **The only place SMTP is spoken** |
| `backup.php` | Daily | Gzipped `mysqldump` above the document root, pruned to 14 days |

Only the services a person is intended for are assessed, so the office is not shown a wall of "not
eligible for Security" for cleaners.

---

## D12. The frontend, structurally

| Piece | File | Note |
|---|---|---|
| Route table | `src/routes/index.tsx` | Every route in one file. Public eager, CMS lazy |
| Permission map | `src/lib/access.ts` | Path prefix → roles, plus the staff/worker/client predicates. Read by the guard **and** the sidebar |
| Guard | `src/auth/RequireAuth.tsx` | Redirects to the user's own home, not to a dead end |
| Auth context | `src/providers/auth-provider.tsx` | The `Role` union and token handling. `homeFor()` sits in `src/lib/access.ts`, beside the other account-kind helpers |
| Fetch hook | `src/hooks/use-api.ts` | A hand-rolled GET hook with a monotonic request-id guard, so a slow earlier response cannot overwrite a faster later one. No cache, no mutation layer, no optimistic update |
| Divisions | `src/lib/divisions.ts` | Single source for names, taglines, audiences, assurances, services and accents — drives the mega-menu, the services pages, the home page and the footer |
| Types | `src/lib/types.ts` | Including `Person`, `EligibilityVerdict`, `RequirementState`, `RightToWorkCheck`, `AuditEntry`, `QueuedEmail` |
| Export | `src/lib/pdf.ts`, `src/lib/csv.ts` | jsPDF; SheetJS plus file-saver |
| Shells | `PublicLayout`, `AppLayout` | Marketing nav and footer; collapsible sidebar rail with sticky header carrying global search, the AI assistant, theme toggle, notifications and the account menu |
| Components | `src/components/ui/` | 37 vendored shadcn components — vendored, not installed, so they are ours to modify |
| PWA | `src/components/pwa-status.tsx` | Owns registration **and** the update prompt; registering in `main.tsx` as well would race it |

`vite.config.ts` splits React, charts, calendar and PDF into their own vendor chunks, and pins port
5122 with `strictPort` on both `server` and `preview` so a drifted port fails loudly instead of
surfacing later as an opaque CORS error.

Design tokens, typography, elevation, motion and the division accents are `docs/DESIGN_SYSTEM.md`,
summarised in Part A. The rule that matters most in code: **never hard-code a colour in a
component — use the token**, and never invent a fourth colour for a compliance state.

---

## D13. Quality gates

| Gate | What it covers | Blocking? |
|---|---|---|
| `npm run typecheck` | `tsc -b --force` over the referenced strict config | Yes |
| `npm run build` | Vite production build, plus a source-map assertion | Yes |
| `npm run test:php` | `server/tests/run.php` — **254 assertions** over pure server logic: expiry classification and its boundary days, reminder banding, the staff/worker/client distinction, field status, pay premiums, person matching, eligibility evaluation | Yes |
| `npm test` | `src/lib/__tests__/access.test.ts` and `app-routes.test.ts` — 37 tests over the permission map (including that a client or worker account cannot reach `/app`), `homeFor()`, and the page registry behind the ⌘K palette and the browser tab title | Yes |
| `php -l` | Every PHP file | Yes |
| Config guard | Fails if `server/config.php` is committed | Yes |
| `python3 scripts/docs_sync.py --check` | `PROGRESS.md` matches `STATUS.json`, and **every backticked source path in `TRACEABILITY.md` resolves**, so a fabricated path fails CI | Yes |
| `npm run lint` | ESLint, `--max-warnings 0` | **Yes.** At zero, from 33. It stopped being advisory once the last 19 were cleared |

Both test suites are dependency-free — no PHPUnit, no Composer — because the production host
cannot install packages, and a suite that only runs on one machine stops being run.
`npm run test:php` needs no database and no `config.php`.

**Endpoints and React components are untested.** That is the largest gap in the engineering
platform, and the compliance engine rows in `docs/TRACEABILITY.md` are the ones that must not stay
that way, because their output decides whether a person may work.

`docs/TRACEABILITY.md` maps requirement → data → endpoint → interface → test precisely so an
unimplemented requirement is visible: a row with no endpoint is not built, and a row with no test
is not protected.

---

## D14. What the system deliberately refuses

The refusals are the product. Each of these is a place where the software says no, on purpose,
with a reason.

| It refuses | Because |
|---|---|
| To assign an ineligible worker | A warning is read after the fact. The assignment is refused with 409 and **every** blocking requirement named |
| To offer a "compliant" checkbox | If a status can be typed in, it will be typed in wrongly and then trusted |
| To create a second person with the same identity | `people.match_key` is UNIQUE; the database itself rejects it |
| To merge an ambiguous identity match | Ambiguity goes to a review list. Matching on a name would merge every John Smith |
| To record a training enrolment against a name alone | It is recorded unlinked instead of guessed |
| To verify a check that is still `pending` | Signing that off would be signing off nothing |
| To verify a check twice, or accept a future-dated one | Neither is a check |
| To import the legacy right-to-work boolean as verified | It came in as an unverified historical assertion with a note saying so. Anything else is inventing evidence |
| To let someone approve their own deletion request | Four eyes, enforced |
| To let a super admin be denied a zone, or granted edit without view | The account that fixes a mistake must not lose the screen that fixes it |
| To double-book leave | The refusal names the dates that clash |
| To report "overdrawn" when nobody set an entitlement | It returns `null` |
| To let a worker post a field event against someone else's shift | 403, scoped by shift ownership rather than by role |
| To let a portal or worker login read `/app` data | Server-side role and link checks, mirrored client-side for the experience |
| To return pay fields to anyone outside office roles | Stripped from the response, not hidden in the UI |
| To serve an uploaded file directly | `.htaccess` blocks `^uploads/`; `files.php` streams it behind auth with a randomised stored name |
| To trust a client-supplied MIME type, or a file over 10 MB | `finfo` detects the type from contents |
| To trust client-supplied timesheet minutes | Computed server-side from the times |
| To mark an invoice paid on a Stripe amount mismatch | The total is re-read from the database; a mismatch is audited as `invoice.paid.mismatch` |
| To credit a redelivered Direct Debit webhook twice | The prior status is read before updating; part-collections credit only what was collected |
| To accept a webhook with a bad signature, or a replayed Stripe event older than 300 seconds | `hash_equals`, and a replay window |
| To auto-create a user from Google SSO | An unmatched verified email gets 403 |
| To turn self-registration into a login | It creates an applicant |
| To let a non-super-admin create a super admin, or anyone deactivate themselves | 403 and 409 |
| To reveal whether an email address exists | `forgot` always returns ok |
| To run `migrate.php`, `seed.php`, `backfill-people.php` or `create-admin.php` over HTTP | CLI only |
| To run a cron script over HTTP without the key | `hash_equals` against `ALERTS_KEY`; unset or `CHANGE_ME` refuses everything |
| To apply a migration by default | Dry run is the default; `--go` applies; a non-local database also needs `--production` and a typed database name |
| To apply migration 004 before the backfill | Its precondition stops with the count and the remedy |
| To re-apply a migration edited since it was applied | Reported as drift; the answer is a new migration |
| To seed without `--confirm`, off localhost, or over HTTP | Fixtures are not something to do by accident |
| To cache compliance data offline | Stale compliance would mean showing an eligibility answer that is no longer true |
| To queue a write while offline | A queued clock-in that turns out to be invalid when it lands is worse than being told you are offline |
| To update the app mid-task without asking | The update prompt is explicit |
| To say the company is compliant | The system *supports* compliance. It never *ensures* it |

---

## D15. What is not built

The honest list is Part B §5, generated from `docs/STATUS.json`: **151 done · 14 partial · 3 to
do · 168 tracked**. The headlines:

- Endpoints and components are untested; lint does not block.
- The `--production` migration guard does not fire on cPanel, where the production database is
  `localhost`.
- The employment contract endpoint and document generation are not built, though the schema and
  versioning are.
- The retention **sweep** is not built; the policies are editable with periods deliberately NULL.
- GDPR erasure runs immediately, with no approval step.
- There is no workflow rule engine — every automation is bespoke code.
- Backups are not restore-tested, and the health check says so rather than assuming otherwise.
- Older screens still show raw DBS/SIA fields alongside the calculated verdict.
- Right-to-work verification enforcement is staged behind migration 007 until the legacy backlog is
  cleared — a one-row UPDATE and a business decision.
- Milestone 1 is applied and verified locally; **it has not been applied to production**.

Explicitly out of scope, and staying that way: full payroll processing, accounting, a learning
management system, a full CRM, complex rota management, AI recruitment scoring or automated hiring
decisions, and biometric monitoring or employee surveillance.

---

## D16. Where it goes next

Two directions, and they are sequenced.

**Finish the platform for the first company.** `docs/ROADMAP.md` milestones 0–9. Milestones 0–4
are substantially delivered; 5–9 are resequenceable by value. The immediate items are the ones in
D15.

**Then become a product other organisations can buy.** That is Parts A–C of this file: one
isolation model, tenant resolution by hostname, per-tenant configuration replacing 43 PHP
constants, services and roles that are data rather than ENUMs, per-tenant sequences, storage,
cron and audit, tenant branding that cannot break semantic status colours, and — the headline
requirement — **email that leaves from the tenant's own domain**, DKIM-signed, SPF- and
DMARC-aligned, with replies landing in the tenant's mailbox.

The property that must survive both directions intact is the one sentence this whole system exists
to make true:

> A named person's compliance position can be produced with evidence in under a minute, and no
> assignment can be made to a worker who is not eligible for that service.
