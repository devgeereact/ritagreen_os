# Traceability

Requirement → data → endpoint → interface → test.

Its purpose is to make an unimplemented requirement visible. A row with no endpoint is not
built. A row with no test is not protected.

**Convention:** a file path in `backticks` exists in the repository today. Planned work is
described in plain text, never as a backticked path — `scripts/docs_sync.py --check` verifies
every backticked source path resolves, so a fabricated path fails CI.

**Test column:** two suites exist, both dependency-free and both run in CI.
`server/tests/run.php` covers the pure server logic — credential expiry banding, the
staff/worker/client distinction, field status, pay premiums — with no database and no
`server/config.php`. `src/lib/__tests__/access.test.ts` covers the client permission map. Most rows
still read _none_; the compliance engine rows in particular must not stay that way, see
`docs/ROADMAP.md` Milestone 2.

---

## Identity and access

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| A person exists once | planned `people` table | planned | planned unified person profile | none |
| A person may serve several services | planned `person_services` | planned | planned | none |
| Candidate becomes worker without duplication | `applicants.worker_id` | `server/ats.php` | `src/pages/app/ATS.tsx` | none |
| Staff and workers authenticate | `users`, `tokens` | `server/auth.php` | `src/pages/auth/Login.tsx` | none |
| Privileged accounts use 2FA | `user_2fa` | `server/auth.php` | `src/components/app/force-2fa.tsx` | none |
| A session can be revoked | `tokens` | `server/auth.php` | `src/pages/app/Settings.tsx` | none |
| Idle sessions lock | — | `server/auth.php` | `src/components/app/lock-screen.tsx` | none |
| Roles restrict what staff can do | `roles`, `users.role` | `require_role()` in `server/lib.php` | `src/auth/RequireAuth.tsx` | none |
| Staff, worker and client accounts are distinguished | `users.worker_id`, `users.client_id` | `is_staff()` in `server/pure.php` | `src/lib/access.ts` | both suites |
| Navigation hides what the user cannot open | — | — | `src/components/app-sidebar.tsx` | none |
| A worker acts only on their own shifts | `shifts.worker_id` | `server/field.php` | `src/pages/worker/WorkerApp.tsx` | none |
| Routes are role-gated in the client | — | — | `src/lib/access.ts` via `src/auth/RequireAuth.tsx` | `src/lib/__tests__/access.test.ts` |
| Per-service scoping is enforced | `users.division` | planned query filter | planned | none |
| Sensitive zones are separately permissioned | planned | planned | planned | none |

## Recruitment

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| The public can apply | `applicants` | `server/ats.php` | `src/pages/public/Apply.tsx` | none |
| Applications move through stages | `applicants.stage` | `server/ats.php` | `src/pages/app/ATS.tsx` | none |
| Onboarding generates tasks | `onboarding_tasks` | `server/ats.php` | `src/pages/app/ATS.tsx` | none |
| A learner can become a candidate | `applicants.source` | `server/ats.php` | `src/pages/app/Academy.tsx` | none |
| Google Forms submissions reach the system | planned | planned webhook | planned settings mapping | none |
| A stalled integration raises an alert | planned | planned | planned | none |

## Compliance

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Credentials are tracked with expiry | `certifications`, `workers` | `server/compliance.php` | `src/pages/app/Compliance.tsx` | none |
| Expiry is classified | — | `rg_classify()` in `server/pure.php` | `src/pages/app/Compliance.tsx` | `server/tests/run.php` |
| Renewals are chased at 90/60/30/expired | `compliance_reminders` | `rg_reminder_tier()` in `server/pure.php` | email | `server/tests/run.php` |
| A reminder tier fires only once | `compliance_reminders` | `server/expiry-alerts.php` | — | none |
| Evidence can be produced per worker | `files`, `certifications` | `server/compliance.php` | `src/pages/app/Compliance.tsx` | none |
| References are checked | `worker_references` | `server/references.php` | `src/pages/app/Workers.tsx` | none |
| Company insurance is current | `insurance` | `server/insurance.php` | `src/pages/app/Compliance.tsx` | none |
| Requirements are configurable per service | planned `compliance_rules` | planned | planned rule builder | none |
| Requirements carry a weight | planned | planned | planned | none |
| Eligibility is calculated, with a reason | planned | planned | planned | none |
| Right to work has an evidence trail | `workers.right_to_work` (boolean only) | `server/workers.php` | `src/pages/app/Workers.tsx` | none |
| SIA licences are verified, not just recorded | `workers.sia_number` | `server/compliance.php` | `src/pages/app/Compliance.tsx` | none |
| DBS is verified, not just recorded | `workers.dbs_status` | `server/compliance.php` | `src/pages/app/Compliance.tsx` | none |
| Verification is a workflow | `worker_references.status` only | `server/references.php` | `src/pages/app/Workers.tsx` | none |

## Operations

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Clients and sites are recorded | `clients`, `sites` | `server/clients.php` | `src/pages/app/Clients.tsx` | none |
| Shifts are scheduled | `shifts` | `server/scheduling.php` | `src/pages/app/Scheduling.tsx` | none |
| Recurring shifts are generated | `shift_templates` | `server/shift-templates.php` | `src/pages/app/Scheduling.tsx` | none |
| Workers are matched on skills | `skills`, `worker_skills` | `server/skills.php` | `src/pages/app/Scheduling.tsx` | none |
| Open shifts are broadcast | `shift_offers` | `server/offer.php` | `src/pages/public/Offer.tsx` | none |
| An ineligible worker cannot be scheduled | `workers` | `server/scheduling.php` | `src/pages/app/Scheduling.tsx` | none |
| No path can assign an ineligible worker | planned shared gate | planned | planned | none |
| Field events are logged | `shift_events` | `server/field.php` | `src/pages/app/FieldOps.tsx` | `server/tests/run.php` (status derivation) |
| Hours are recorded and approved | `timesheets` | `server/timesheets.php` | `src/pages/app/Timesheets.tsx` | none |
| Pay is calculated from rules | `pay_rules`, `bank_holidays` | `server/pay-lib.php` | `src/pages/app/Payroll.tsx` | `server/tests/run.php` (premium selection) |

## Employment records

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Workers have a payroll number | planned | planned, on `next_seq()` in `server/lib.php` | planned | none |
| Employment contracts have a lifecycle | planned | planned | planned | none |
| Written-statement particulars are structured | planned | planned | planned | none |
| Leave and absence are recorded | planned | planned | planned | none |
| Worker status follows workflow events | `workers.status` (set by hand) | `server/workers.php` | `src/pages/app/Workers.tsx` | none |

## Training

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Courses are recorded | `programmes` | `server/academy.php` | `src/pages/app/Academy.tsx` | none |
| Learners are enrolled | `enrolments` | `server/academy.php` | `src/pages/app/AcademyDetail.tsx` | none |
| Certificates are issued | `certificates` | `server/certificates.php` | `src/pages/app/AcademyDetail.tsx` | none |
| Certificates are publicly verifiable | `certificates.public_id` | `server/certificates.php` | `src/pages/public/Verify.tsx` | none |
| External training can be recorded | planned | planned | planned | none |
| Training links to the person | `enrolments.learner_name` (a string) | `server/academy.php` | `src/pages/app/Academy.tsx` | none |

## Documents

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Documents are stored safely | `files` | `server/files.php` | `src/pages/app/Files.tsx` | none |
| Documents are not publicly reachable | — | `public/.htaccess` blocks the uploads path | — | none |
| Only permitted roles browse documents | — | `server/files.php` | `src/pages/app/Files.tsx` | none |
| Documents carry expiry and verification | planned columns | planned | planned | none |

## Communications

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| The office is emailed on an enquiry | `contact_enquiries` | `server/contact.php` | `src/pages/public/Contact.tsx` | none |
| Staff can reply from the app | — | `server/inbox.php` | `src/pages/app/Mailbox.tsx` | none |
| Workers are reachable by SMS or WhatsApp | `sms_log` | `send_sms()` in `server/lib.php` | `src/pages/app/Comms.tsx` | none |
| Staff see in-app notifications | `notifications` | `server/notifications.php` | `src/components/notification-dropdown.tsx` | none |
| Mail is templated | planned | planned | planned | none |
| Mail leaves the request path | — | currently synchronous in `server/lib.php` | — | none |
| Delivery failures are visible | planned | planned | planned | none |

## Governance

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Sensitive actions are audited | `audit_log` | `audit()` in `server/lib.php` | `src/pages/app/Settings.tsx` | none |
| Audit records before and after values | not captured | — | — | none |
| Consent is recorded | `consent_log` | `server/contact.php` | — | none |
| Subject data can be exported | — | `server/gdpr.php` | `src/pages/app/Settings.tsx` | none |
| Deletion requires approval | planned | planned | planned | none |
| Records have a retention policy | planned | planned | planned | none |
| Incidents are logged and actioned | `incidents`, `incident_actions` | `server/incidents.php` | `src/pages/app/Incidents.tsx` | none |
| The database is backed up | — | `server/backup.php` | cron | none |
| Backups are restore-tested | — | planned procedure | — | none |
| Regulatory packs can be produced | — | `server/reports.php` | `src/pages/app/Reports.tsx` | none |

## Platform

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Documentation matches the code | `docs/STATUS.json` | `scripts/docs_sync.py` | `docs/PROGRESS.md` | `scripts/docs_sync.py` `--check` |
| Quality gates run before merge | — | `.github/workflows/ci.yml` | GitHub checks | itself |
| Pure logic is testable without infrastructure | — | `server/pure.php` | — | `server/tests/run.php` |
| Deploys never disturb server files | — | `.github/workflows/deploy.yml` | — | none |
| Development does not touch production | `server/config.local.php.example` | `npm run dev:api` | `vite.config.ts` proxy defaults local | none |
| Fixtures exist without production data | `server/seed.php` | CLI | — | none |
| Schema changes are ordered migrations | `server/schema.sql` is one re-runnable file | planned migrations directory | — | none |
| Errors do not leak internals | — | `server/lib.php` returns exception messages | — | none |
| The app is installable and works offline | `public/manifest.webmanifest`, `public/sw.js` | — | `src/main.tsx` registers it | none |
