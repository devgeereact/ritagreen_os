# Lean CMS launch proposal

> **Status: complete and superseded, 10 October 2026.** Phases 0 to 5 of this plan were built, with the owner's 2026
> extension, in sections S0 to S10; Phase 6 (the Academy integration) is deferred for want
> of a provider. The decisions that extended
> and replaced it are in [`docs/DECISIONS-2026.md`](DECISIONS-2026.md); item-level progress is
> [`docs/PROGRESS.md`](PROGRESS.md); the browser evidence for the launch journey is
> [`docs/TESTING.md`](TESTING.md), "2026 launch journeys". What remains is
> [`docs/ROADMAP.md`](ROADMAP.md). Kept as history; do not work from it.

14 September 2026. Proposal for approval, not an implemented replacement roadmap.

## Recommendation

Finish one company management system for RitaGreen: recruit people, verify evidence, onboard workers, assign eligible workers and show the owner what needs attention. Keep the existing React/Vite, PHP and MySQL architecture. Do not rebuild in Next.js, introduce multiple tenants, or build an LMS.

The launch unit is a complete journey: application → review → verification → signed contract evidence → approval → active worker → eligible assignment → expiry follow-up. A collection of green module checkboxes is not a launch unit.

Assumptions: RitaGreen is the only organisation; Care, Cleaning, Security and Academy are services within it; existing accounting and course delivery remain external. Existing production usage of optional features has not been established, so their removal requires a usage and dependency check.

## What the review established

Repository inventory, targeted source tracing, documentation comparison and local automated checks were completed. This was not a line-by-line audit of every file or a production assessment. Secret configuration and account details were deliberately excluded. No application code or data was changed.

| Finding | Evidence | Consequence |
|---|---|---|
| The foundation already exists | `server/lib.php`, `server/auth.php`, `server/users.php`, migrations 001–004 and 015, `src/lib/access.ts` | Repair and complete it; do not rebuild accounts, people or payroll identifiers |
| LMS scope has entered the UI | `src/pages/app/AcademyDetail.tsx` renders `QuizBuilder` and generates assessments; `server/academy.php` calls itself an LMS | Remove assessment generation and LMS language; retain training evidence |
| Important backend functions are not connected to the frontend | No `src/` references to `action-centre`, `verifications`, `forms-intake`, `system-health` or `employment_contracts` | Build usable screens around existing functions after checking their permissions |
| Employment contracts are not the existing Contracts screen | Migration 010 defines `employment_contracts`; `server/contracts.php` operates client commercial contracts | Implement a small employment evidence workflow; do not mistake client contracts for onboarding |
| Onboarding terminology is premature | `server/ats.php` sets application stage to `onboarded` while creating a worker with status `onboarding` and seeding unfinished tasks | Separate starting onboarding from completing it |
| Recruitment stages are freely selectable | `server/ats.php` accepts any value from the stage list; no transition prerequisites in that branch | Add explicit transition rules and audit reasons |
| Service scope is incomplete in ATS source | The list uses `service_scope()`, but the individual read and stage/task/onboard branches do not apply it | Reproduce direct-ID access across services and repair before launch |
| Verification precedence needs regression coverage | `rg_load_evidence()` in `server/compliance-lib.php` unsets a rejected requirement without marking it as processed, allowing an older verified row to be considered; subsequent certifications can overwrite evidence | Test newest rejection versus older success and legacy evidence, then enforce one authoritative result |
| Checklist policy is inconsistent | ATS checklist says two or more references; the evidence loader treats at least one verified reference as met | Management must select the required count per service; use the same rule throughout |
| Activation and deployment need to be tested together | `rg_derive_worker_status()` considers tasks/contracts; `rg_deployment_check()` itself assesses compliance without loading those activation facts | Verify every assignment caller blocks unapproved, suspended and incomplete workers; do not assume a derived dashboard status enforces this |
| Recruitment detail is thin | Stage and label/done tasks exist; no dedicated interview record or recruitment task owner/due date found in schema and migrations | Add the smallest structured records needed by recruiters |
| Deployment is not chained to CI in repository configuration | `.github/workflows/ci.yml` and `deploy.yml` independently trigger on main; deploy runs install/build/upload | Gate deployment on all required checks for the exact commit and coordinate PHP/schema/frontend versions |
| Documents conflict | PRD/ROADMAP exclude LMS; `RITAGREEN_SETUP.md` proposes tenants, custom domains and AI; the recent operations design proposes Today/Rota/Pay | Adopt one active scope and mark older proposals superseded only after approval |

Source findings above are not claims of reproduced live exploits. The checked-in PHP tests explicitly exclude database-backed behaviour. The documentation checker verifies generated progress and referenced paths, not whether every feature works.

## Product structure

Use seven main destinations: **Today, Recruitment, People, Compliance, Assignments, Reports, Settings**. Notifications stay in the header. Documents, employment contracts, leave and training records belong inside the person's profile. Clients and sites sit within Assignments initially. Avoid a separate menu item for every table.

Today shows urgent blockers, overdue tasks, pending approvals, failed messages and approaching expiries. Every item links to an action the current user may perform. Owner sees company totals; staff see only permitted services and records. Do not expose the existing Action Centre until its aggregate queries obey those boundaries.

Use one person profile with identity, applications, service memberships, worker record, documents, checks, contract evidence, training and history. An applicant need not have a login. A worker login links to that same person. Never create a second identity merely because a person joins another service.

Present Owner, Staff and Worker clearly. Preserve existing stored roles initially and map them to explicit capabilities. Staff permissions should cover recruitment, verification, workforce administration and management, scoped to permitted services. Keep sensitive data zones. A role label or hidden button is not authorisation. Existing client accounts remain restricted until their future is decided.

## Sequential build phases

### Phase 0: Establish a safe baseline and freeze scope

Reuse current architecture, tests and migrations. Create a disposable local database with synthetic data, outbound integrations disabled, and a documented rebuild procedure. Capture current route/API usage and record exact removal candidates. Establish an exact-commit release gate and a database/document restore rehearsal before authorising release.

Exit: local application starts from documented steps, baseline journeys have reproducible tests, and no test depends on production credentials or data. Resolve conflicting active plans. Do not estimate a firm launch date until this baseline is known.

### Phase 1: Foundation and one person record

Reuse authentication, account links, people, service memberships, payroll sequence and audit helpers. Keep one organisation with fixed services; do not build a hierarchy designer. Finish server-side permissions for list, detail, mutation, files, search, exports and aggregates. Make one person profile usable.

Payroll numbers are permanent identifiers such as the existing `RG-000001`, not a new payroll product. Preserve existing numbers. Verify concurrent generation and retry behaviour. Make worker conversion transactional and prevent duplicate workers from retries or simultaneous submissions, while allowing an explicit future rehire policy.

Exit: Owner/Staff/Worker journeys pass; cross-person and cross-service access is refused; retries cannot duplicate an identity or worker conversion; critical changes have attributable audit events.

### Phase 2: Recruitment with essential communications

Reuse public applications, Google Forms intake, the ATS and queued email. Add a practical candidate detail view, named recruiter, interview date/interviewer/outcome/notes, tasks with owner and due date, stage history and rejection reasons. Keep a fixed stage model, not a configurable workflow designer.

Suggested labels: Applied → Screening → Interview → Verification → Offer → Onboarding → Hired, with Rejected/Withdrawn exits. Implement these through compatible migrations or documented mappings to current values. No AI scoring.

Connect existing intake mapping and failure records to Settings. Use authenticated, replay-safe submission ingestion and a recoverable failed-submission queue. Provide a CSV/manual fallback. Verify a completed Google submission using a sandbox form before relying on the integration.

Bring SMTP configuration, transactional templates, queue visibility, failed-send retry and in-app notifications here, rather than waiting for a later communications phase. Initially omit IMAP, SMS, WhatsApp and marketing automation.

Exit: a submission becomes the correct application exactly once on redelivery; staff can review it without database edits; malformed submissions are visible; messages cannot silently disappear.

### Phase 3: Verification and onboarding through activation

Reuse right-to-work checks, verifications, references, compliance rules, files, employment contract tables and onboarding tasks. Add the missing staff screens. Identity, RTW, SIA, DBS where applicable, references and certificate checks record evidence, reviewer, date, outcome and any recheck date. Keep service-specific policies, including reference counts and mandatory training, under management control.

Use simple contract evidence: upload the agreed version, record when sent, attach the signed copy, record acceptance and reviewer approval. Preserve superseded versions. Do not build a document designer or electronic signature platform. Existing documents can be prepared and signed externally.

Starting onboarding creates a pending worker. Activation requires the applicable checks, required tasks, signed contract evidence and an authorised approval. Recalculate when evidence changes, not only at the next daily cron. Every retained assignment path must enforce current approval and eligibility at the point of writing.

Exit: missing, rejected or expired mandatory evidence blocks activation/assignment; incomplete onboarding cannot be bypassed by changing a stage; rejected evidence cannot be revived by an older success or a legacy field.

### Phase 4: Workforce and lean worker portal

Reuse workers, clients/sites, scheduling, leave and worker account links. Launch simple assignments: worker, client, site, service, start/end and status. Keep the current assignment engine; do not merge jobs/shifts tables for neatness. Choose one office-facing assignment view and retain compatibility for existing records.

Worker portal: own status and reasons, requested documents, uploads, assignments, training evidence, leave requests and notifications. Prove ownership on every request. Staff manage absence and approval from the person record. Training here means provider, course name, completion date, expiry and certificate evidence only.

Exit: an approved eligible worker can be assigned; an ineligible or unavailable worker cannot; another worker's record cannot be accessed; approved leave conflicts are handled consistently. Mobile and keyboard journeys work.

### Phase 5: Owner visibility and launch gate

Connect the existing Action Centre, system health, expiry alerts and reporting with correct access scopes. Deliver a small set of reliable reports: recruitment pipeline, pending approvals, active/blocked workers by service, expiring evidence, overdue tasks and staff workload. Count the whole filtered dataset, not just the first page. Export only authorised fields and record the export.

Complete privacy controls, configured retention, data request handling, deletion review, protected uploads, cron monitoring, backups and a tested restore. These are launch gates and have been designed into earlier phases, not postponed feature work. Have a named person own each operational failure queue and the restore procedure.

Exit: all launch journeys and negative tests pass on a representative environment; backup restoration succeeds for database and documents; release/rollback is rehearsed; staff complete a guided pilot and can handle failures without a developer. Independent security testing covers retained sensitive flows before full launch.

### Phase 6: Optional external Academy integration after launch

Only build this when a real provider/API is confirmed. Import training enrolments/completions/certificate metadata into the existing person record using stable provider IDs and replay-safe updates. Include failed-import visibility and reconciliation. Keep a manual evidence fallback.

No course delivery, lessons, quizzes, assessments, gradebooks, video hosting, SCORM or learner progression. Internal training evidence in Phase 4 is not dependent on this integration.

## Removal and simplification proposal

| Category | Target | Proposed treatment |
|---|---|---|
| Explicitly outside the requested product | `QuizBuilder` and quiz parsing in `src/pages/app/AcademyDetail.tsx` | Remove the feature, imports and its tests if any; retain the training detail page and enrolment evidence |
| Optional AI and weather | `src/components/app/ai-assistant.tsx`, `weather-widget.tsx`, `src/pages/app/Insights.tsx`, `ContentStudio.tsx`, `server/ai.php`, `weather.php` | Trace all consumers, then remove unused launch features and endpoints as a cohesive change |
| Financial expansion | Payroll calculation UI, invoices, quotes, payments, GoCardless, margins and client portal | Exclude from proposed launch scope; verify actual business use and upstream/downstream dependencies before retirement. Preserve historical records, financial obligations and accounting handoffs |
| Advanced field operations | Panic/check calls, location tracking, shift broadcasting and complex recurring rota features | Defer expansion; do not remove a safety function used by workers without a replacement process and explicit decision |
| Duplicate operational surfaces | Scheduling, Jobs, FieldOps, Bookings, Timesheets, Payroll | Reuse useful portions of the Today/Rota/Pay design only where retained scope needs them. Do not delete these files just because that design lists them |
| Multi-tenant transformation | `docs/RITAGREEN_SETUP.md` | Mark superseded and retain in history after plan approval; do not implement tenant onboarding or custom domains |
| Documentation duplication | PRD, BLUEPRINT, ROADMAP, STATUS/PROGRESS, TRACEABILITY, CLAUDE, README | Keep distinct responsibilities and correct stale facts. STATUS remains the source for generated PROGRESS. Do not create another competing progress ledger |
| Possible template leftovers | `src/assets/react.svg`, `src/components/file-manager/`, unused UI components and dependencies | Investigate imports, dynamic loaders, routes, public URLs and build/deploy references. No claim that these are all dead |
| Preserve | migrations, schema, tests, access controls, audit/retention, backups, deployment rules, public legal pages, existing employment and certificate evidence | Never remove merely to reduce file count |

For each deletion record the exact path, reason, replacement, callers, affected routes/endpoints, data impact and verification. Distinguish code deletion from data erasure. Remove routes, imports, server exposure, jobs/configuration and dependency entries together where applicable; hiding a link alone does not retire a feature. Rebuild after dependency changes. Preserve old URLs where they still have a meaningful replacement. No schema drops as launch tidying.

## Verification and evidence

Completed locally on 14 September 2026:

| Check | Result |
|---|---|
| `npm run typecheck` | PASS |
| `npm run lint -- --max-warnings 0` | PASS |
| `npm test` | PASS, 44 tests in 3 files |
| `npm run test:php` | PASS, 410 assertions |
| `php -l` for all 70 PHP files | PASS |
| `npm run build` | PASS, Vite build and generated service worker |
| Build output checks | PASS, index/manifest/service worker exist, no source maps |
| `python3 scripts/docs_sync.py --check` | PASS, limited to its documented structural checks |

Review rounds: (1) inventory and scope conflicts; (2) trace key code paths and execute baseline checks; (3) challenge removal dependencies, evidence precedence and launch prerequisites. Repeating unchanged unit tests would not resolve the remaining runtime uncertainty.

NOT TESTED: database-backed HTTP journeys, migrations on a fresh database, browser usability/accessibility, cross-account runtime access, real SMTP/Google Forms, dependency vulnerability status, production configuration, deployed PHP parity, restore/recovery and penetration testing. The existing `server/config.php` was not used for runtime tests because its environment and outbound destinations were not established. These checks belong to the isolated baseline and launch gate, not to a claim that this review proved the application safe.

Current verdict: **NOT READY FOR PRODUCTION on the evidence of this review.** This is not a statement that every existing workflow is broken. It means the proposed CMS launch has substantive missing journeys and unverified release requirements. No numerical readiness score or promise of perfection would be justified.

## External guidance consulted

Context7 was not exposed in the available tool catalogue. Official sources were used for the narrow recommendations below, not for a complete legal review:

- [ICO: data protection by design and default](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/accountability-and-governance/guide-to-accountability-and-governance/data-protection-by-design-and-by-default/): supports including privacy throughout the build.
- [Home Office: right to work guidance](https://www.gov.uk/government/collections/right-to-work-checks-employer-guidance): management must select and review the applicable check procedure; an uploaded document is not itself proof that the prescribed check occurred.
- [GitHub: workflow events](https://docs.github.com/en/enterprise-cloud%40latest/actions/reference/workflows-and-actions/events-that-trigger-workflows): chained workflows must explicitly account for successful completion. Prefer one simple dependency chain and promote the tested commit/artifact.

Next action: approve this product boundary, then implement Phase 0 and Phase 1 before expanding the interface.
