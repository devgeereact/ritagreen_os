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
`server/config.php`, and carries `server/tests/guards.php`, which fails if any endpoint returns
before it authorises. On the client, `src/lib/__tests__/access.test.ts` covers the permission map
and `homeFor()`, and `src/lib/__tests__/app-routes.test.ts` the page registry. Most rows still
read _none_; the compliance engine rows in particular must not stay that way, see
`docs/ROADMAP.md` Milestone 2.

**"unapplied" means production.** Migrations 001–020 are applied and verified against a local
database. None has been applied to the live one, which is a separate deliberate act — see
`docs/ROADMAP.md`.

---

## Identity and access

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| A person exists once | `people` (migration 001, unapplied in production) | `find_or_create_person()` in `server/lib.php`, `server/backfill-people.php`, `server/seed.php` | `src/pages/app/Workers.tsx` | `server/tests/run.php` (matching) |
| A person may serve several services | `person_services` (migration 001, unapplied in production) | `server/compliance.php` `?action=eligibility_all` | `src/components/app/eligibility-dialog.tsx` | `server/tests/run.php` (evaluation) |
| Candidate becomes worker without duplication | `applicants.worker_id` | `server/ats.php` | `src/pages/app/ATS.tsx` | none |
| Staff and workers authenticate | `users`, `tokens` | `server/auth.php` | `src/pages/auth/Login.tsx` | none |
| Privileged accounts use 2FA | `user_2fa` | `server/auth.php` | `src/components/app/force-2fa.tsx` | none |
| A session can be revoked | `tokens` | `server/auth.php` | `src/pages/app/Settings.tsx` | none |
| Idle sessions lock | — | `server/auth.php` | `src/components/app/lock-screen.tsx` | none |
| Roles restrict what staff can do | `roles`, `users.role` | `require_role()` in `server/lib.php` | `src/auth/RequireAuth.tsx` | none |
| Staff, worker and client accounts are distinguished | `users.worker_id`, `users.client_id` | `is_staff()` in `server/pure.php` | `src/lib/access.ts` | both suites |
| Navigation hides what the user cannot open | none | none | `src/lib/nav.ts`, `src/components/app-sidebar.tsx`, `src/components/global-search.tsx`, `src/layouts/Footer.tsx` | `src/lib/__tests__/access.test.ts`, `src/lib/__tests__/nav.test.ts` |
| The office app has eight destinations, Finance only when switched on | none | none | `src/lib/nav.ts`, `src/components/nav-main.tsx`, `src/lib/app-routes.ts` | `src/lib/__tests__/nav.test.ts`, `src/lib/__tests__/app-routes.test.ts` |
| The owner switches optional areas on and off | `feature_switches` (migration 036) | `server/features.php`, `feature_enabled()` in `server/lib.php` | `src/components/app/features-card.tsx`, `src/hooks/use-features.ts` | `server/tests/run.php`, `server/tests/db/cases/120_feature_switches.php` |
| A switched-off area is refused by the server | `feature_switches` | `server/inbox.php`, `server/ai.php`, `server/payments.php`, `server/gocardless.php`, `send_sms()` in `server/lib.php` | hidden in `src/layouts/AppLayout.tsx`, `src/pages/app/Dashboard.tsx` | `server/tests/db/cases/120_feature_switches.php` |
| A worker acts only on their own shifts | `shifts.worker_id` | `server/field.php` | `src/pages/worker/WorkerApp.tsx` | none |
| Routes are role-gated in the client | — | — | `src/lib/access.ts` via `src/auth/RequireAuth.tsx` | `src/lib/__tests__/access.test.ts` |
| Per-service scoping is enforced | `users.scope_to_division` | `service_scope()` in `server/lib.php` | `src/lib/access.ts` | none |
| Sensitive zones are separately permissioned | `zone_permissions` | `can_view_zone()` in `server/lib.php` | planned settings screen | none |
| Reading a record is authorised, not just writing it | — | every endpoint, immediately after `require_auth()` | `src/lib/access.ts` | `server/tests/guards.php` |
| Navigation and page actions match what the server allows | — | — | `src/lib/access.ts`, `src/lib/app-routes.ts` | `src/lib/__tests__/access.test.ts`, `src/lib/__tests__/app-routes.test.ts` |
| Every authenticated page names itself | — | — | `useDocumentTitle()` in `src/hooks/use-document-title.ts` | `src/lib/__tests__/app-routes.test.ts` (`titleFor`) |

## Recruitment

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| The public can apply | `applicants` | `server/ats.php` | `src/pages/public/Careers.tsx` | none |
| Applications move through stages | `applicants.stage` | `server/ats.php` | `src/pages/app/ATS.tsx` | none |
| Onboarding generates tasks | `onboarding_tasks` | `server/ats.php` | `src/pages/app/ATS.tsx` | none |
| A learner can become a candidate | `applicants.source` | `server/ats.php` | `src/pages/app/Academy.tsx` | none |
| Google Forms submissions reach the system | `integration_submissions` | `server/forms-intake.php` | planned settings screen | `server/tests/run.php` (mapping) |
| A stalled integration raises an alert | `integrations.expect_within_hours` | `server/action-centre.php` | planned | none |

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
| Requirements are configurable per service | `compliance_rules` (migration 003, unapplied in production) | `rg_load_rules()` in `server/compliance-lib.php` | planned rule builder | `server/tests/run.php` (evaluation) |
| Requirements carry a weight | planned | planned | planned | none |
| Eligibility is calculated, with a reason | planned storage | `rg_evaluate_eligibility()` in `server/pure.php` | planned | `server/tests/run.php` |
| Requirements carry a weight that decides blocking | planned storage | `server/pure.php` | planned | `server/tests/run.php` |
| Right to work has an evidence trail | `right_to_work_checks` | `server/right-to-work.php` | planned | none |
| A check records who verified it, and when | `right_to_work_checks.verified_by` | `server/right-to-work.php` | planned | none |
| SIA licences are verified, not just recorded | `workers.sia_number` | `server/compliance.php` | `src/pages/app/Compliance.tsx` | none |
| DBS is verified, not just recorded | `workers.dbs_status` | `server/compliance.php` | `src/pages/app/Compliance.tsx` | none |
| Verification is a workflow | `verifications` | `server/verifications.php` | planned | none |
| Only legal state transitions are allowed | `verifications.status` | `server/verifications.php` | planned | none |

## Operations

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Clients and sites are recorded | `clients`, `sites` | `server/clients.php`, `server/sites.php` | `src/pages/app/Clients.tsx`, `src/components/rota/sites-tab.tsx` | `server/tests/db/cases/160_rota.php` |
| A site's required training is part of eligibility | `site_requirements` | `rg_load_rules()` in `server/compliance-lib.php` | `src/components/rota/sites-tab.tsx`, shift panel | `server/tests/db/cases/160_rota.php` |
| Shifts are scheduled | `shifts` | `server/scheduling.php` | `src/pages/app/Scheduling.tsx` | `server/tests/db/cases/160_rota.php` |
| Workers never see a draft; a week is published | `shifts.is_draft` | `server/scheduling.php`, `server/me.php`, `server/offer.php`, `server/field.php`, `server/portal.php` | `src/pages/app/Scheduling.tsx` | `server/tests/db/cases/160_rota.php` |
| A change after publishing tells the worker | `notifications`, `email_queue` | `rg_notify_worker()` in `server/rota-lib.php` | shift panel | `server/tests/db/cases/160_rota.php` |
| Last week can be copied as drafts | `shifts` | `server/scheduling.php?action=copy_week` | `src/pages/app/Scheduling.tsx` | `server/tests/db/cases/160_rota.php` |
| A shift can be reassigned or cancelled | `shifts` | `server/scheduling.php` | `src/components/rota/shift-panel.tsx` | `server/tests/db/cases/160_rota.php` |
| Approved leave blocks every assignment path | `leave_records` | `rg_assignment_check()` in `server/compliance-lib.php` | shift panel | `server/tests/run.php`, `server/tests/db/cases/160_rota.php` |
| Unavailability and a short rest gap need acknowledging | `availability_blocks`, `app_settings` | `rg_assignment_check()`, `rg_rest_gap_warnings()` | shift panel | `server/tests/run.php`, `server/tests/db/cases/160_rota.php` |
| Cover requests and enquiries have an owner | `service_requests`, `contact_enquiries` | `server/requests.php` | `src/pages/app/Requests.tsx` | `server/tests/db/cases/160_rota.php` |
| Shifts are created from a cover request | `service_requests`, `shifts` | `server/bookings.php?action=confirm` | `src/pages/app/Requests.tsx` | `server/tests/db/cases/160_rota.php` |
| Work orders are raised and tracked | `jobs` | `server/jobs.php` | `src/pages/app/Jobs.tsx` | none |
| A work order can be corrected or archived | `jobs` | `server/jobs.php` | `src/pages/app/Jobs.tsx` | none |
| A work order names who is doing it | `jobs` | `server/jobs.php` | `src/pages/app/Jobs.tsx` | none |
| An unpaid break is not paid | `timesheets` | `rg_deduct_break()` in `server/pure.php` | `src/pages/app/Timesheets.tsx` | `server/tests/run.php` |
| Hours can be traced to the shift worked | `timesheets`, `shifts` | `server/timesheets.php` | `src/pages/app/Timesheets.tsx` | none |
| Clocking out makes a draft timesheet the worker submits | `timesheets` | `rg_timesheet_from_clock()` in `server/rota-lib.php`, `server/me.php` | staff portal (S4) | `server/tests/run.php`, `server/tests/db/cases/160_rota.php` |
| Hours can be queried and corrected with history | `timesheets`, `audit_log` | `server/timesheets.php` | `src/pages/app/Timesheets.tsx` | `server/tests/db/cases/160_rota.php` |
| A worker can see their own hours and pay | `timesheets` | `server/me.php` | `src/pages/worker/WorkerApp.tsx` | none |
| A pay rate is priced as at the date worked | `worker_pay_rates` | `server/pay-lib.php` | `src/pages/app/Payroll.tsx` | `server/tests/run.php` |
| Premiums follow the worked interval | `pay_rules`, `bank_holidays` | `rg_premium_segments()` in `server/pure.php` | `src/pages/app/Payroll.tsx` | `server/tests/run.php` |
| Bank holidays are known beyond this year | `bank_holidays` | `rg_bank_holidays()` in `server/pure.php` | — | `server/tests/run.php` |
| A rate below the configured floor is visible | `pay_floors` | `server/pay-lib.php` | `src/pages/app/Payroll.tsx` | none |
| Hours are approved or rejected with a reason | `timesheets` | `server/timesheets.php` | `src/pages/app/Timesheets.tsx` | `server/tests/run.php` |
| A panic alert reaches somebody | `notifications`, `notification_reads` | `server/notifications.php` | `src/components/app/*` | none |
| A panic alert can be acknowledged | `shift_events` | `server/field.php` | `src/pages/app/FieldOps.tsx` | `server/tests/run.php` |
| A lone worker can make a check call | `shift_events` | `server/field.php` | `src/pages/worker/WorkerApp.tsx` | none |
| Recurring shifts are generated as drafts | `shift_templates` | `server/shift-templates.php` | `src/components/rota/dialogs.tsx` | none |
| Workers are matched on skills | `skills`, `worker_skills` | `server/skills.php` | `src/pages/app/Scheduling.tsx` | none |
| Open shifts are broadcast | `shift_offers` | `server/offer.php` | `src/pages/public/Offer.tsx` | none |
| An ineligible worker cannot be scheduled | `workers` | `server/scheduling.php` | `src/pages/app/Scheduling.tsx` | none |
| No path can assign an ineligible worker | — | `rg_deployment_check()`, `rg_assignment_check()` in `server/compliance-lib.php` | `server/scheduling.php`, `server/me.php`, `server/offer.php` | `server/tests/db/cases/160_rota.php` |
| The office is shown what needs attention | — | `server/action-centre.php` | planned | none |
| Field events are logged | `shift_events` | `server/field.php` | `src/pages/app/FieldOps.tsx` | `server/tests/run.php` (status derivation) |
| Hours are recorded and approved | `timesheets` | `server/timesheets.php` | `src/pages/app/Timesheets.tsx` | none |
| Pay is calculated from rules | `pay_rules`, `bank_holidays` | `server/pay-lib.php` | `src/pages/app/Payroll.tsx` | `server/tests/run.php` (premium selection) |

## Employment records

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Workers have a payroll number | `workers.payroll_no` (migration 001, unapplied in production) | `next_payroll_no()` in `server/lib.php`, called by `server/ats.php` on onboarding | `src/pages/app/Workers.tsx` | none |
| Employment contracts have a lifecycle | `employment_contracts` | planned endpoint | planned | none |
| Written-statement particulars are structured | `employment_contracts` fields | planned endpoint | planned | none |
| Leave and absence are recorded | `leave_records` | `server/leave.php` | planned | `server/tests/run.php` (balance) |
| Worker status follows workflow events | `workers.derived_status` | `rg_derive_worker_status()` in `server/pure.php` | `src/pages/app/Workers.tsx` | `server/tests/run.php` |

## Training

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Courses are recorded | `programmes` | `server/academy.php` | `src/pages/app/Academy.tsx` | none |
| Learners are enrolled | `enrolments` | `server/academy.php` | `src/pages/app/AcademyDetail.tsx` | none |
| Certificates are issued | `certificates` | `server/certificates.php` | `src/pages/app/AcademyDetail.tsx` | none |
| Certificates are publicly verifiable | `certificates.public_id` | `server/certificates.php` | `src/pages/public/Verify.tsx` | none |
| External training can be recorded | `training_providers`, `enrolments.external_course` | `server/academy.php` | planned | none |
| Training links to the person | `enrolments.person_id` | `server/academy.php` | `src/pages/app/Academy.tsx` | none |

## Documents

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Documents are stored safely | `files` | `server/files.php` | `src/pages/app/Files.tsx` | none |
| Documents are not publicly reachable | — | `public/.htaccess` blocks the uploads path | — | none |
| Only permitted roles browse documents | — | `server/files.php` | `src/pages/app/Files.tsx` | none |
| Documents carry expiry and verification | `files.expires_on`, `verification_status` | `server/files.php` | `src/pages/app/Files.tsx` | none |

## Communications

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| The office is emailed on an enquiry | `contact_enquiries` | `server/contact.php` | `src/pages/public/Contact.tsx` | none |
| Each website enquiry reaches the right queue (staff, cleaning quote, care, training, general) | `service_requests`, `contact_enquiries` | `server/bookings.php`, `server/quotes.php?action=request`, `server/contact.php` (`type`) | `src/components/public/forms/`, `src/pages/public/Request.tsx` | `server/tests/db/cases/130_website_enquiries.php` |
| Old website addresses still land | none | `public/.htaccess` (301) | `src/routes/legacy-redirects.tsx` | `src/lib/__tests__/public-routes.test.ts` |
| Staff can reply from the app | — | `server/inbox.php` | `src/pages/app/Mailbox.tsx` | none |
| Workers are reachable by SMS or WhatsApp | `sms_log` | `send_sms()` in `server/lib.php` | `src/pages/app/Comms.tsx` | none |
| Staff see in-app notifications | `notifications` | `server/notifications.php` | `src/components/notification-dropdown.tsx` | none |
| Mail is templated | `email_templates` | `queue_templated_mail()` in `server/lib.php` | planned editor | `server/tests/run.php` (rendering) |
| Mail leaves the request path | `email_queue` | `server/send-queued-mail.php` | — | none |
| Delivery failures are visible | `email_log` | `server/send-queued-mail.php` | planned | none |

## Governance

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Sensitive actions are audited | `audit_log` | `audit()` in `server/lib.php` | `src/pages/app/Settings.tsx` | none |
| Audit records before and after values | `audit_log.before_json`, `after_json` | `audit_diff()` in `server/lib.php` | `src/pages/app/Settings.tsx` | none |
| A record's own history is retrievable | `audit_log.entity_type`, `entity_id` | `server/audit.php` | planned | none |
| Consent is recorded | `consent_log` | `server/ats.php`, `server/bookings.php`, `server/quotes.php`, `server/auth.php` | the consent checkboxes on the website forms | `server/tests/db/cases/130_website_enquiries.php` (quote) |
| Marketing is opt-in and reversible | `consent_log` (`kind = 'marketing'`) | `server/contact.php`, `server/quotes.php`, `server/unsubscribe.php` | `src/components/public/forms/ContactForms.tsx`, `src/components/public/forms/CleaningQuoteForm.tsx` | `server/tests/run.php` (token), `server/tests/db/cases/130_website_enquiries.php` |
| Subject data can be exported | resolved through `people` | `server/gdpr.php` | `src/pages/app/Settings.tsx` | none |
| A person can make a request | `data_requests` | `server/data-request.php` | `src/pages/public/DataRequest.tsx` | none |
| Deletion requires approval | `deletion_requests` | `server/governance.php` | planned | none |
| Records have a retention policy | `retention_policies` | `server/governance.php` | planned | none |
| Retention periods are acted on | `retention_policies` | `server/retention-sweep.php` | cron, dry run by default | `server/tests/run.php` |
| Incidents are logged and actioned | `incidents`, `incident_actions` | `server/incidents.php` | `src/pages/app/Incidents.tsx` | none |
| The database is backed up | — | `server/backup.php` | cron | none |
| Backups are restore-tested | — | `server/system-health.php` reports it as an explicit unknown | — | none |
| Regulatory packs can be produced | — | `server/reports.php` | `src/pages/app/Reports.tsx` | none |
| Staff workload is visible | — | `server/system-health.php` | planned | none |
| The system reports its own health | — | `server/system-health.php` | planned | none |
| Exports are logged | `export_log` | `server/governance.php` | planned | none |

## People and compliance (section S2)

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| One profile per person, every tab behind its zone | `people`, `workers`, `applicants`, `verifications`, `right_to_work_checks`, `employment_contracts`, `worker_pay_rates`, `leave_records`, `audit_log` | `server/people.php` | `src/pages/app/PersonProfile.tsx`, `src/components/people/` | `server/tests/db/cases/140_person_profile.php`, `server/tests/run.php` (`rg_service_statuses`) |
| A person is read only inside the account's service scope | (none) | `require_person_scope()` in `server/lib.php`, `rg_person_in_scope()` in `server/pure.php` | `src/lib/access.ts` (`/app/people`) | `server/tests/db/cases/140_person_profile.php`, `server/tests/run.php` |
| The profile names the one next action | (none) | `rg_person_next_action()` in `server/pure.php` | `src/pages/app/PersonProfile.tsx` | `server/tests/run.php`, `server/tests/db/cases/150_onboarding_and_approval.php` |
| Each requirement shows a calculated status chip | (none) | `rg_requirement_chip()` in `server/pure.php` | `src/components/people/checks-tab.tsx` | `server/tests/run.php` |
| Emergency contacts are kept, validated and audited | `people.emergency_contact*` (migrations 001, 037) | `rg_save_emergency_contacts()` in `server/people-lib.php` | `src/components/people/emergency-tab.tsx` | `server/tests/db/cases/140_person_profile.php`, `server/tests/run.php` |
| Evidence waiting for review is one queue | `verifications` | `server/verifications.php` `?action=queue` | `src/components/compliance/verification-queue.tsx` | `server/tests/db/cases/140_person_profile.php` |
| Right-to-work checks are recorded and signed off | `right_to_work_checks` | `server/right-to-work.php` | `src/components/compliance/right-to-work-panel.tsx`, `src/components/people/rtw-dialog.tsx` | `server/tests/db/cases/140_person_profile.php` |
| Onboarding starts from the service's checklist | `onboarding_template_items` (migration 037) | `server/onboarding-templates.php`, `server/ats.php` | `src/components/compliance/onboarding-templates.tsx` | `server/tests/db/cases/150_onboarding_and_approval.php` |
| Approval is offered only when the server would accept it | `workers.approved_at` | `rg_approval_blockers()` in `server/pure.php`, `server/workers.php` | `src/components/people/overview-tab.tsx` | `server/tests/db/cases/150_onboarding_and_approval.php`, `server/tests/run.php` |
| A suspension is a recorded decision | `workers.suspended_at`, `suspension_reason` (migration 037) | `server/workers.php` `?action=suspend` | `src/components/people/overview-tab.tsx` | `server/tests/db/cases/150_onboarding_and_approval.php` |
| Contract evidence follows its lifecycle | `employment_contracts` | `server/employment-contracts.php` | `src/components/people/employment-tab.tsx` | `server/tests/db/cases/110_activation.php`, `server/tests/db/cases/150_onboarding_and_approval.php` |
| Training records link to the person | `enrolments.person_id` | `rg_add_training_record()` in `server/people-lib.php` | `src/components/people/training-tab.tsx` | `server/tests/db/cases/140_person_profile.php` |

## Platform

| Requirement | Data | Endpoint | Interface | Test |
|---|---|---|---|---|
| Documentation matches the code | `docs/STATUS.json` | `scripts/docs_sync.py` | `docs/PROGRESS.md` | `scripts/docs_sync.py` `--check` |
| Quality gates run before merge | — | `.github/workflows/ci.yml` | GitHub checks | itself |
| Pure logic is testable without infrastructure | — | `server/pure.php` | — | `server/tests/run.php` |
| Deploys never disturb server files | — | `.github/workflows/deploy.yml` | — | none |
| Development does not touch production | `server/config.local.php.example` | `npm run dev:api` | `vite.config.ts` proxy defaults local | none |
| Fixtures exist without production data | `server/seed.php` | CLI | — | none |
| Schema changes are ordered migrations | `server/schema.sql` is the baseline | `server/migrate.php` | — | none |
| Errors do not leak internals | — | `server/lib.php` returns exception messages | — | none |
| The app is installable and works offline | generated by `vite.config.ts` | — | `src/main.tsx` registers it | none |
| The public site loads nothing third-party | `public/fonts`, `src/fonts.css` | — | `index.html`, CSP in `public/.htaccess` | none |
| One visual identity in the company green | `src/index.css`, `tailwind.config.ts`, `src/lib/services.ts` | none | `docs/DESIGN_SYSTEM.md`, `src/components/brand/Logo.tsx`, `public/favicon.svg` | none (checked in a browser) |
| Sensitive categories are separated by permission | `zone_permissions` | `server/workers.php`, `server/compliance.php`, `server/leave.php` | redacted fields | none |
| Documents are readable only by those entitled | `files` | `server/files.php` | `src/pages/app/Files.tsx` | `server/tests/run.php` |
