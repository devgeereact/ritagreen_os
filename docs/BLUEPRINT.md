# Blueprint — the target model and the gap to it

**Status:** baseline, written against the codebase as it stood on 2026-08-31.

This document is the spine of the programme. It states the target architecture, then records
honestly what is already built, what is missing, and what is actively wrong.

Read `docs/PRD.md` first for why. This is the what.

---

## Part 1 — The target model

### 1.1 Person-centred

A person exists once, permanently.

```
PERSON
 ├── Candidate        (an application, at a stage)
 ├── Worker           (a payroll number, an employment status)
 ├── Learner          (enrolments, certificates)
 └── User             (a login, a role)
```

There is never a "Security John Smith" and a "Cleaning John Smith". There is one John Smith
who is eligible for Security and for Cleaning.

### 1.2 Service-aware

Eligibility is per person **per service**, not a single flag on the person.

```
John Smith
  Care      NOT ELIGIBLE — mandatory training expired
  Cleaning  ELIGIBLE
  Security  ELIGIBLE
```

This is the single most important consequence of the person-centred model. It is impossible
to express in the current schema, where `workers.division` is a single-value ENUM.

### 1.3 Compliance-driven

```
Requirements for (service, role)
        +
Evidence on file for this person
        ↓
   COMPLIANCE ENGINE
        ↓
ELIGIBLE  |  NOT ELIGIBLE + reason
```

Compliance status is a **calculated value**. It is never a field a person can edit. If the
SIA licence has expired, the person is not eligible for Security; no one has to remember to
change anything.

Every requirement carries a weight:

| Weight | Meaning |
|---|---|
| Mandatory | Absence blocks eligibility |
| Recommended | Absence is surfaced but does not block |
| Optional | Recorded only |

The same requirement may carry different weights in different services. First aid may be
mandatory for Care and Security but recommended for Cleaning. This is configuration, owned by
the business.

### 1.4 Enforced, not advisory

```
Attempt to assign a person to work
        ↓
   Compliance engine
        ↓
PASS → assignment created
FAIL → assignment refused, with the specific blocking reason
```

The system must make it impossible to deploy someone who is not eligible. A dashboard warning
is not sufficient — warnings are read after the fact.

### 1.5 Verification is a workflow

"Verified: yes" is not verification. Every check moves through a lifecycle and records who,
when, what was checked, the outcome, the evidence and any notes.

```
REQUESTED → RECEIVED → UNDER REVIEW → VERIFIED
                                    → REJECTED
```

### 1.6 The information hierarchy

```
RITAGREEN
├── PEOPLE          candidates · workers · learners
├── SERVICES        Care · Academy · Cleaning · Security
├── CLIENTS         organisations · sites
├── RECRUITMENT     applications · pipeline · tasks
├── ONBOARDING      checklists · contracts · activation
├── COMPLIANCE      rules · checks · verifications · eligibility
├── TRAINING        enrolments · records · certificates · providers
├── DOCUMENTS       with metadata, expiry and verification
├── CONTRACTS       employment contracts, versioned
├── ASSIGNMENTS     who works where, gated on eligibility
├── LEAVE & ABSENCE
├── TASKS
├── COMMUNICATIONS  templates · queue · delivery log
├── REPORTS
└── GOVERNANCE      audit · retention · permissions
```

---

## Part 2 — What already exists

This system is not a blank page. The following is built, working, and must not be rebuilt.

| Capability | Where it lives |
|---|---|
| Recruitment pipeline, 7 stages | `applicants.stage` · `server/ats.php` |
| Onboarding task checklist | `onboarding_tasks` · `ONBOARDING_CHECKLIST` in `server/ats.php` |
| Certificate issue and public verification | `certificates` · `server/certificates.php` · `/verify/:id` · Open Badges 3.0 assertion |
| Credential expiry engine | `server/expiry-alerts.php` — 90/60/30/expired tiers, deduplicated through `compliance_reminders`, run by cron, office digest plus personal nudges |
| Compliance evidence pack | `server/compliance.php?action=pack&worker_id=N` |
| Regulatory report packs | `server/reports.php` — `cqc_pack`, `sia_acs`, `coshh`, `skills_for_care` |
| Reference checks with gap explanation | `worker_references` · `server/references.php` |
| Company insurance register | `insurance` · `server/insurance.php` |
| Incidents with RIDDOR and safeguarding flags | `incidents`, `incident_actions` · `server/incidents.php` |
| Authentication | Opaque bearer tokens (`server/auth.php:35`), bcrypt cost 12, hand-rolled TOTP 2FA, device/session list with per-session revoke, rate limiting, Google SSO, idle lock screen |
| GDPR export and erasure | `server/gdpr.php` · `consent_log` |
| Audit log | `audit_log` · `server/audit.php` |
| Clients, sites, rate cards | `clients`, `sites`, `rate_cards` · `server/clients.php` |
| Scheduling with a compliance guard | `server/scheduling.php` — already refuses to match or assign a worker with a lapsed DBS (Care), lapsed SIA (Security), or no right to work |
| Shift broadcast and token acceptance | `shift_offers` · `server/offer.php` — worker accepts from an SMS link, no login |
| Field operations | `shift_events` — clock in/out, check-in, panic, signature, lat/lng · `server/field.php` |
| Time and pay records | `timesheets`, `pay_rules`, `bank_holidays` · `server/payroll.php`, `server/pay-lib.php` |
| Commercial pipeline | quotes → contracts → invoices → credit notes, Stripe Checkout and GoCardless Direct Debit with signature-verified webhooks |
| Worker mobile app | `/m` · `server/me.php` — own compliance, shifts, open shifts to claim, clock in/out |
| Client portal | `/portal` · `server/portal.php` |
| Document storage | `files` · `server/files.php` — randomised stored names, MIME allowlist, 10 MB cap, streamed downloads behind an auth check, uploads directory blocked in `.htaccess`, text extraction from docx/xlsx/PDF |
| Notifications | `notifications` · notification dropdown |
| Marketing content CMS | `blog_posts`, `testimonials` · AI Content Studio via OpenRouter proxy (`server/ai.php`) |
| Database backup | `server/backup.php` — gzipped mysqldump above the document root, 14-day prune |

The blueprint's assumption that these need building is wrong. The work is elsewhere.

---

## Part 3 — The gaps

### Tier 1 — architectural. These block everything downstream.

#### The identity defect

**A person can exist three times in this database.**

`server/ats.php` `?action=onboard` reads the applicant row and **copies** the fields into a
new `workers` row:

```php
$stmt = $conn->prepare(
    'INSERT INTO workers (name, email, phone, role, division, status) VALUES (?, ?, ?, ?, ?, "onboarding")'
);
$stmt->bind_param('sssss', $app['name'], $app['email'], $app['phone'], $wrole, $app['division']);
```

`server/ats.php` `?action=from_learner` creates a *third* copy, and where the learner has no
email address it fabricates one:

```php
$emailVal = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ($name . '@learner.invalid');
```

The `applicants.worker_id` foreign key links the two copies after the fact, but the data is
duplicated, and from that moment the two rows drift independently. A phone number corrected on
the worker record does not reach the applicant record.

Consequence: compliance, documents and training attach to whichever copy happened to be in
front of the person entering them.

**Fix:** Milestone 1. A `people` table becomes the identity; `candidates`, `workers`, `users`
and `enrolments` carry `person_id`. `onboard` links, never copies.

#### One person, one service

`workers.division ENUM('care','academy','cleaning','security')` — a single value.

A cleaner who also holds an SIA licence and covers security shifts cannot be represented. In
practice this forces a second worker record for the same human, which is the identity defect
again by another route.

**Fix:** Milestone 1. `person_services` — person × service, with per-service eligibility.

#### Compliance is a report, not an engine

`server/compliance.php` does one thing: it classifies expiry dates.

```php
function classify(?string $expiry): array
{
    if (!$expiry) return ['status' => 'unknown', 'days' => null];
    $days = (int) floor((strtotime($expiry) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0) return ['status' => 'expired', 'days' => $days];
    if ($days <= 30) return ['status' => 'expiring', 'days' => $days];
    return ['status' => 'valid', 'days' => $days];
}
```

That is a useful primitive and it should be kept. But there is no requirement catalogue, no
per-service rule set, no notion of mandatory versus recommended, no calculated eligibility
verdict, and no reason string. The system can say "this certificate expires in 12 days". It
cannot say "this person may not work in Security, because their SIA licence has expired".

The only place requirements are encoded at all is hard-coded inside `server/scheduling.php`.

**Fix:** Milestone 2.

#### Right to work is a boolean

```sql
right_to_work TINYINT(1) NOT NULL DEFAULT 0
```

No check method. No check date. No verifier. No evidence. No expiry or recheck date. No
outcome. No audit trail. The one field that most needs an evidence trail has none.

This is the weakest point in the system in legal terms.

**Fix:** Milestone 3. A `right_to_work_checks` table with method, date, verifier, evidence
file reference, outcome, expiry and follow-up flag.

### Tier 2 — substantive missing modules

| # | Gap | Note |
|---|---|---|
| 5 | No payroll number generator | `next_seq()` at `server/lib.php:173` is the right primitive — it already provides atomic, collision-free sequences for invoice and quote numbers |
| 6 | No employment contract lifecycle | `contracts` is a **commercial client contract**. There is no draft → sent → viewed → accepted → signed employment contract, and no versioning |
| 7 | No structured written-statement fields | Acas expects specific particulars. Storing them inside a PDF makes them unqueryable |
| 8 | No leave and absence module | Holiday records must be retained; the system has nowhere to put them |
| 9 | Worker status is set, not derived | `workers.status` is one of active/onboarding/inactive, changed by hand. It should be a consequence of workflow events |
| 10 | No Google Forms intake | The office's existing front door has no route into the system |
| 11 | No email templates, queue or delivery log | `send_mail()` (`server/lib.php:209`) opens a socket and talks SMTP **inside the web request**. A slow mail server slows the app; a failed send is lost |
| 12 | Document metadata is thin | `files` has a category but no issue date, expiry, verification status or verifier |
| 13 | No retention engine | Nothing expires, nothing is reviewed, nothing is archived on a schedule |
| 14 | No Action Centre | The owner navigates to find work rather than being shown it |
| 15 | No workflow rule engine | Every automation is bespoke code |
| 16 | No staff workload view | Work cannot be rebalanced because it cannot be seen |
| 17 | No system health or integration monitoring | If an integration stops feeding the system, nobody finds out until someone notices the absence |
| 18 | Verification is not a workflow | Only `worker_references.status` has a lifecycle. Nothing else does |

### Tier 3 — permissions and data protection

#### Client-side role gating is dead code

`src/auth/RequireAuth.tsx` accepts a `roles` prop:

```tsx
export default function RequireAuth({ roles }: { roles?: Role[] }) {
```

**No route in `src/routes/index.tsx` ever passes it.** `/app`, `/portal` and `/m` are gated
identically on "is logged in". A `client`-role account can load `/app/payroll`.

The component's own comment says role gating there is "a UX convenience only; every mutating
endpoint re-checks the role server-side". That is the correct posture — but it is only half
true, because several read endpoints carry `require_auth` with no `require_role` at all,
including `dashboard.php`, `field.php`, `notifications.php`, `ai.php` and `weather.php`.

#### Three read endpoints leak across account types

Verified during the Milestone 0 audit. Each is reachable by **any** authenticated account,
including a `client` portal login, because the endpoint calls `require_auth()` and never
`require_role()`:

| Endpoint | Exposure |
|---|---|
| `server/workers.php` GET | Every worker's `pay_rate_pence`, `dbs_status`, `dbs_expiry`, `sia_number` and contact details. The role checks in this file are only on the write paths |
| `server/inbox.php` | The entire shared company mailbox, and the ability to send replies as the company |
| `server/field.php` POST | Posting field events — clock-ins, check-ins and **panic alerts** — against any shift id |

These are more urgent than most of Milestone 5, because the client portal is an
externally-facing surface. They should be fixed ahead of the rest of that milestone.

#### Roles are flat

Seven values in an ENUM, enumerated in three places that must be kept in sync:
`server/schema.sql` (the `roles` table and the `users.role` ENUM), `src/providers/auth-provider.tsx`,
and `require_role()` calls throughout `server/`.

There is no per-service scoping enforced as a query filter, and no notion of a sensitive zone.
`users.division` exists but is not used to restrict what a staff account can read.

The target adds:

- Per-service scope: a Care recruiter sees Care candidates.
- Sensitive zones — health, payroll, disciplinary, legal — each with its own permission rule,
  so a worker profile is not one undifferentiated blob visible to everyone with access to it.

### Tier 4 — engineering risk

These are not in the original blueprint. The audit found them and they matter more than
several things that are.

| # | Risk | Detail |
|---|---|---|
| 19 | **Development runs against production** | `vite.config.ts` proxies every `*.php` request to `https://ritagreenservices.com`. There is no local PHP or MySQL anywhere in the repository. Every developer reads and writes live workforce data |
| 20 | **No tests, no CI gates** | There is not one test file. `.github/workflows/deploy.yml` deploys to production on push to `main`. Nothing runs a typecheck, a lint or a PHP syntax check first |
| 21 | **The schema is not migrations** | `server/schema.sql` is a single re-runnable file organised in "build passes", where later `ALTER TABLE` blocks patch earlier `CREATE TABLE` statements in the same file. The only upgrade path is replaying it |
| 22 | **Exception messages leak to callers** | `server/lib.php` converts fatals into JSON containing the exception message |
| 23 | **PWA is unreliable** | `public/sw.js` is hand-written with no build-hash awareness, so hashed assets are only cached opportunistically and offline breaks after each deploy. `public/manifest.webmanifest` has no 192 or 512 px PNG icons, which fails Android installability |
| 24 | **Four brand palettes in one repository** | See `docs/DESIGN_SYSTEM.md` |

Risk 19 is a hard blocker on Milestone 1. Migrating identity while pointed at the live
database is not an acceptable risk.

---

## Part 4 — Sequence and why

```
Milestone 0   Documentation pack               ← establishes the contract
              ─────────────────────────────
Prerequisite  Local development environment    ← blocks all schema work
              ─────────────────────────────
Milestone 1   Person spine                     ← unblocks everything
Milestone 2   Compliance engine                ← needs the spine
Milestone 3   Verification: RTW, SIA, DBS      ← needs the engine's requirement catalogue
Milestone 4   Deployment gate                  ← needs a verdict to enforce
Milestone 5   Permissions and zones
Milestone 6   Employment records
Milestone 7   Communications
Milestone 8   Intake and integrations
Milestone 9   Governance, PWA, hardening
```

The order is not negotiable through Milestone 4. Each depends on the one before it. From
Milestone 5 onward the order is a judgement about value, and can be resequenced.

Full detail in `docs/ROADMAP.md`.
