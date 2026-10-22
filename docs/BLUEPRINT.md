# Blueprint: the model, the modules as built, and the gaps it started from

**Status:** Part 1 is the model. Part 2 describes the modules as built at the end of the 2026
build (sections S1 to S10, 10 October 2026). Part 3 is the gap analysis of 2026-08-31, kept as
history: item-level status is [`docs/PROGRESS.md`](PROGRESS.md), not this file.

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

This is the single most important consequence of the person-centred model. It was impossible
to express while `workers.division` was the only service field; `person_services` (person by
service, with eligibility per service) now carries it.

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
├── SERVICES        Healthcare · Cleaning · Security · Training (ids care · cleaning · security · academy)
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

## Part 2: Modules as built

What the code does at the end of the 2026 build. Endpoints are listed in
[`docs/API.md`](API.md) and tables in [`docs/SCHEMA.md`](SCHEMA.md); this part says how they fit
together for the people who use them.

### 2.1 The office app: eight destinations

The sidebar is data in `src/lib/nav.ts`: eight destinations, each one page with its secondary
pages beneath, plus **Finance** (invoicing, quotes, contracts, payroll) only while the owner has
it switched on. A link shows only if `src/lib/access.ts` lets the account open it. The table of
destinations and their secondary pages is in [`docs/DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md),
"Office navigation".

| Destination | What it is for |
|---|---|
| **Today** (`/app`) | Tiles for unfilled shifts, checks to review, evidence expiring in 30 days and approvals waiting; today's shifts; "Needs action" naming the people, each with a button to the place to act. Built on the Action Centre (`action-centre.php`), which obeys service scope and zones |
| **Recruitment** (`/app/ats`) | Applicant board by stage, applicant detail, interviews, tasks, and **Import from spreadsheet** (`/app/ats/import`). Stages move only as `rg_stage_transition()` allows |
| **People** (`/app/workers`) | The staff list and one profile per person (2.2); Training records beneath |
| **Compliance** (`/app/compliance`) | Verification queue (oldest first), right-to-work checks, onboarding lists, expiry list |
| **Rota** (`/app/scheduling`) | The rota (2.3), Timesheets, Requests (website cover requests and enquiries in one list with an owner), Clients with their Sites, Jobs, Field ops, Incidents |
| **Website** (`/app/blog`) | Posts, Vacancies, Forms (the forms directory), Testimonials, Company details and social links |
| **Reports** (`/app/reports`) | Recruitment pipeline, active and blocked workers with reasons, evidence expiring at 30, 60 and 90 days, unfilled shifts by week, approved hours by week (payroll zone), staff workload. CSV is built on the server from declared columns and recorded in `export_log` |
| **Settings** (`/app/settings`) | The tabs in 2.7; Inbox (while switched on), Comms, Files and Help beneath |

Notifications live in the header bell and the ⌘K palette, which is built from
`src/lib/app-routes.ts`.

### 2.2 The person profile

`/app/people/:personId`, read through `server/people.php`. The header shows each service with
the person's status in it and the one next action the server works out
(`rg_person_next_action()`). Tabs:

| Tab | Contents |
|---|---|
| Overview | Services and status per service, payroll number, contact details, start date, next shift |
| Checks and documents | Every requirement for every service the person works in, with a calculated chip (Valid, Due soon, Expired, Awaiting review, Missing), the evidence file and where it came from, verifier, date checked, expiry. Upload evidence, verify with what was checked, reject with a reason |
| Training | Courses, certificates and renewal dates; records go to `enrolments` against the person |
| Shifts | Upcoming and past shifts |
| Employment | Contract evidence (agreed version, sent, accepted, signed copy, review, new version), approval and suspension, pay rate history, leave |
| Emergency contacts | Two contacts, saved through `rg_save_emergency_contacts()` |
| Activity | The audit trail for the person |

A section outside the account's zone comes back as `{restricted: true}` and the tab says so.
**Approval** is offered only when `rg_approval_blockers()` is empty: onboarding finished and a
signed employment contract that has been reviewed. "Download profile for client" produces a PDF
with client-safe fields only (compliance zone, audited).

### 2.3 The rota

One scheduling engine (`server/scheduling.php`, `server/rota-lib.php`) for every service.

- **Draft and publish.** Every new shift is a draft that workers, offers, the client portal and
  the field board never see. "Publish drafts" publishes the week shown, as filtered, and tells
  each booked worker once. "Copy last week" and weekly patterns make drafts.
- **Changes are told.** Editing, moving or taking somebody off a published shift tells them what
  changed, in the app and by email. Cancelling needs a reason, which the worker is told.
- **Offers.** Offers by name or broadcast, accepted by text link (`offer.php`) or in the staff
  portal; the first eligible acceptance wins.
- **One assignment check on every path** (`rg_assignment_check()` in `compliance-lib.php`):
  employment state and evidence (`rg_deployment_check()`), a clash, and approved **leave** block.
  A period the worker marked unavailable, and a **rest gap** under the minimum, are warnings the
  office must acknowledge, and the acknowledgement is audited. The minimum rest is
  `app_settings.rest_gap_min_hours` (11 by default), measured in Europe/London so the clock
  changes count. It is set on the Rota rules card in Settings (2.7).
- **Sites as records**, with address, access notes, a contact and required training, which joins
  the service's rules as mandatory for shifts at that site.
- **Screen.** A week by site or by worker on desktop, one day at a time on a phone, conflicts
  written on each shift, and a side panel to edit, assign (eligibility and reasons first),
  acknowledge warnings, offer, cancel and read the history. No dragging is needed.
- **Timesheets from the clock.** Clocking out makes a draft timesheet; the worker confirms the
  break and submits; the office approves, queries with a reason, or corrects with a reason, with
  the values before and after kept.

### 2.4 The staff portal (`/m`)

Phone first and installable. Four tabs in `src/pages/worker/`, served by `server/me.php`, which
takes the worker from the login on every action and never from the request.

| Tab | Shows and does |
|---|---|
| **Home** | Next shift with site, address and a map link opened only on tap, site contact and notes; clock in from 15 minutes before the start, clock out, check call, report a problem, panic alert; what stops the worker being booked and what to do; training due; messages |
| **Rota** | Published shifts by day, offers to accept or decline, open shifts to pick up with the server's reason on refusal, a calendar file |
| **Records** | Checks with status chips and upload of a new copy (it goes to "received" and never verifies itself), training and certificates, timesheets to confirm and submit, gross pay by month |
| **Me** | Contact details (written to `people`), emergency contacts, unavailable days, leave requests and balance, the office's contact details |

Offline, the shell loads and each tab says so; no personal data is cached.

### 2.5 The website, careers and the forms directory

The public site (Healthcare, Cleaning, Security, Training, Careers, About, Contact and a Request
staff button) reads company details, social links, vacancies, forms and posts from public
endpoints that return named public fields only. Enquiries go to the queue that owns them: cover
requests to `bookings.php`, the cleaning quote to `quotes.php?action=request`, care, training and
general enquiries to `contact.php` with a type.

- **Vacancies** (`server/vacancies.php`): draft, published, closed. `/careers` lists published
  roles; `/careers/:slug` shows the role, the checks, then one way to apply: the linked Google
  Form, or a short native form when the role has none.
- **Forms directory** (`server/website-forms.php`): only a Google Forms responder address is
  accepted and only its id is stored (`docs/RULES.md` rules 26 to 28). A form loads only when the
  visitor presses the button; "open in a new tab" and the phone number are always shown.
  `/training` lists active training forms the same way.
- **Form intake**: an Apps Script per form posts each response to `forms-intake.php` with a key
  and a shared secret; the applicant appears in Recruitment at "Applied", linked to one person.
  Set-up is in [`docs/runbooks/google-forms-intake.md`](runbooks/google-forms-intake.md).
- **`/links`**: the link-in-bio page for the social profiles.

### 2.6 Spreadsheet import

Recruitment, Import from spreadsheet (`server/sheet-import.php`; owner, admin and manager only):
upload a Google Sheet downloaded as **CSV** (a spreadsheet file is refused with instructions to
download it as CSV), map columns (the mapping is saved and reused), preview every row, decide
uncertain matches, tick rows, apply, and read a report with a failures download. Identity is
written through `people`; for existing staff only phone, email, address, postcode, the first
emergency contact and availability can change. The uploaded file is never stored, and applying
the same row twice creates nothing.

### 2.7 Settings

| Tab | Who | What |
|---|---|---|
| Security | Everyone | Two-factor authentication, sessions |
| Users and roles | Owner (`super_admin`, `admin`) | Accounts and roles |
| Features | Office (owner, admin, manager); only an owner changes anything | The feature switches (finance, online payments, SMS and WhatsApp, inbox), and the **Rota rules** card: the minimum rest between shifts, which an owner edits and the office reads (`scheduling.php?action=settings`, 0 to 24 hours) |
| Form intake | Office | Each form's last submission and failures, forms not reviewed for 90 days, intake secrets (shown once) |
| System health | Office, unscoped | When each scheduled job last ran, the email queue, form intake, the restore test |
| Audit log | Owner | The audit trail |
| Data requests | Owner | Requests to archive or delete a record (`server/governance.php`). **Four-eyes rule:** somebody other than the person who asked must decide, and both halves are recorded with a reason. A delete that would take other records with it is refused once with the counts, and goes ahead only when the owner confirms having read them |
| Data and GDPR | Owner | Subject access export and erasure |

The **finance** switch hides the Finance pages in the office app only; the endpoints keep their
role and zone checks and are not refused. Online payments and the inbox are refused on the
server while off, and SMS and WhatsApp sends are logged as skipped.

### 2.8 Underlying capabilities

Built before 2026 and still in use:

| Capability | Where it lives |
|---|---|
| Certificate issue and public verification | `certificates` · `server/certificates.php` · `/verify/:id` |
| Credential expiry engine | `server/expiry-alerts.php`: 90/60/30/expired tiers, deduplicated through `compliance_reminders`, run by cron |
| Regulatory report packs | `server/reports.php`: `cqc_pack`, `sia_acs`, `coshh`, `skills_for_care` |
| Reference checks | `worker_references` · `server/references.php` |
| Incidents with RIDDOR and safeguarding flags | `incidents`, `incident_actions` · `server/incidents.php` |
| Authentication | Opaque bearer tokens, bcrypt, TOTP 2FA, session list with revoke, rate limiting, Google SSO, idle lock screen |
| Audit log | `audit_log` · `server/audit.php` |
| Field operations | `shift_events`: clock in and out, check calls, panic, with location · `server/field.php` |
| Time and pay records | `timesheets`, `worker_pay_rates`, `pay_rules`, `bank_holidays` · `server/payroll.php`, `server/pay-lib.php` |
| Commercial pipeline (Finance) | Quotes, contracts, invoices, credit notes; Stripe and GoCardless behind the online payments switch |
| Client portal | `/portal` · `server/portal.php`, restricted until its future is decided |
| Document storage | `files` · `server/files.php`, `server/files-lib.php`: randomised stored names, MIME allowlist, streamed behind an auth check |
| Email queue | `email_queue`, drained by `server/send-queued-mail.php` |
| Backup and restore test | `server/backup.php`, `server/restore-check.php`; [`docs/runbooks/backup-and-restore.md`](runbooks/backup-and-restore.md) |

---

## Part 3: The gaps found on 2026-08-31 (history)

Kept as the record of where the work started. Most of these are closed; the current state of
each is in [`docs/PROGRESS.md`](PROGRESS.md) and the evidence in [`docs/CHANGELOG.md`](CHANGELOG.md).

### Tier 1 — architectural. These block everything downstream.

#### The identity defect — FIXED

**A person could exist three times in this database.**

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

**Fixed in Milestone 1.** A `people` table is now the identity, and `workers`, `applicants`,
`users` and `enrolments` carry `person_id`. `find_or_create_person()` sits in front of every
creation path — the public application, self-registration, the learner handoff, worker creation
and user creation — and `people.match_key` is UNIQUE, so the database itself refuses a second
person with the same identity.

Verified against a real database: two applications, an onboarding and a login creation for one
human produce **one** person record, carrying two applications, one employment record and one
login. Before the fix that was three or four separate identities.

Two paths were still outside the spine afterwards, and both are closed now. `server/seed.php`
inserted users, workers and applicants with no `person_id` at all — which stopped being merely
inconsistent once migration 004 made the column `NOT NULL`, at which point seeding a migrated
database failed outright. And `server/backfill-people.php --go` inserted a fresh person for
every group regardless of existing links, so running it a second time duplicated every identity
it had created the first time. A rule the tooling does not follow is a rule that comes back.

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

**Fix:** Milestone 2. The decision logic now exists in `server/pure.php` —
`rg_evaluate_eligibility()` takes a rule set and a person's evidence and returns a verdict, a
score and a reason, with mandatory failures blocking and expiring credentials warning without
blocking. It is tested against the boundary cases that matter, including that a credential
expiring *today* does not stand someone down. What remains is the storage for the rules, and
wiring the evaluator into the endpoints.

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

#### Client-side role gating was dead code — FIXED

`src/auth/RequireAuth.tsx` accepted a `roles` prop that **no route ever passed**, so `/app`,
`/portal` and `/m` were gated identically on "is logged in" and a `client`-role account could
load `/app/payroll`.

Now `src/lib/access.ts` holds one path-to-roles map, read by **both** the router guard and the
sidebar, so a visible link always leads somewhere the user may go. It also gates `/portal` to
accounts with a `client_id` and `/m` to accounts with a `worker_id`, because those surfaces
scope every query to that link and would otherwise render an empty or erroring page.

The posture is unchanged and correct: this is a user-experience boundary, and the server
re-checks everything. What made the old comment only half true was that several read endpoints
carried `require_auth` with no `require_role`; the worst of those are now fixed above.

#### Three read endpoints leaked across account types — FIXED

Found during the Milestone 0 audit and fixed immediately, ahead of the rest of Milestone 5,
because `/portal` is an externally-facing surface. Each was reachable by **any** authenticated
account, including a client portal login, because the endpoint called `require_auth()` and
never `require_role()`:

| Endpoint | Was exposed | Now |
|---|---|---|
| `server/workers.php` GET | Every worker's `pay_rate_pence`, `dbs_status`, `dbs_expiry`, `sia_number` and contact details — the role checks in this file were only on the write paths | Staff only; pay fields stripped for all but `super_admin`, `admin`, `manager` |
| `server/inbox.php` | The entire shared company mailbox, and sending replies as the company | `super_admin`, `admin`, `manager` |
| `server/field.php` POST | Posting field events — clock-ins, check-ins and **panic alerts** — against any shift id | Staff for any shift; a worker only for a shift assigned to them |

The fix introduced `is_staff()`, `require_staff()` and `linked_worker_id()` in
`server/lib.php`. The important lesson is in the first of those: **role alone could not express
the boundary**, because a worker's login is an ordinary account with `users.worker_id` set and
may carry any role. Anything gated on "internal staff" must check the link, not the role.

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

## Part 4: Sequence

The milestone order of 2026-08-31 (spine, compliance engine, verification, deployment gate,
then permissions, employment, communications, intake and governance) has been delivered; the
2026 sections that followed are recorded in [`docs/DECISIONS-2026.md`](DECISIONS-2026.md). What
remains is [`docs/ROADMAP.md`](ROADMAP.md).
