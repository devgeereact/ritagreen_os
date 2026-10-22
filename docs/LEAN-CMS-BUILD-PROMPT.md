# Build prompt: RitaGreen lean CMS

> **Status: complete and superseded, 10 October 2026.** The build this prompt briefed was carried out in sections S0 to
> S10. The decisions that extended
> and replaced it are in [`docs/DECISIONS-2026.md`](DECISIONS-2026.md); item-level progress is
> [`docs/PROGRESS.md`](PROGRESS.md); the browser evidence for the launch journey is
> [`docs/TESTING.md`](TESTING.md), "2026 launch journeys". What remains is
> [`docs/ROADMAP.md`](ROADMAP.md). Kept as history; do not work from it.

Use this prompt after approving `docs/LEAN-CMS-LAUNCH-PLAN.md`. It is an implementation brief, not authorisation to deploy, remove production records or send messages.

---

Build RitaGreen's lean company management system from the existing repository. Follow the approved lean launch plan sequentially. Complete each phase's verification before moving on. Do not implement all modules simultaneously.

## Product boundary

One company, four services, one person record. The core journey is application → review → verification → onboarding → approval → active worker → eligible assignment → ongoing compliance.

This is a CMS for company operations. It is not an LMS. Remove the existing AI quiz generator. Do not build lessons, assessments, course authoring, gradebooks, video hosting, classrooms, SCORM or learner progression. Retain training records and external certificate evidence. Defer external Academy automation until the core launch has passed.

Keep React/Vite, TypeScript, existing UI components, PHP endpoints and MySQL. No framework migration, microservices, multi-tenancy, generic workflow engine, permissions DSL, new state library or speculative abstraction. Reuse existing helpers and tables. Split a component only when it has a clear independent responsibility.

## Start from the actual repository

Read AGENTS.md, .agent/PROJECT.yml, .agent/CURRENT-TASK.md and the relevant GEE OS instructions. Read CLAUDE.md and the approved launch plan, then inspect the current implementation. The current explicit user instruction controls scope. Old files are evidence, not permission to push or deploy.

Inspect git status and preserve unrelated work, including .codex/. Establish the current branch. Keep application changes on an isolated local branch or worktree where permitted. Do not commit, push, merge, deploy, change remote settings or use production services without the required authorisation.

Record the approved scope in the current task contract. Mark incompatible older plans superseded without destroying their historical decisions. Use existing STATUS/PROGRESS/TRACEABILITY conventions rather than building a parallel tracking system.

Use a disposable local database and synthetic data. Confirm local configuration and disable outbound email, SMS, AI and payments before running seed, migration, queue or cron tools. Never overwrite the existing config.php or copy its secrets into fixtures. If an isolated environment cannot be established, report the dependency and continue independent authorised work; do not test on production.

## Implement in order

0. Baseline: run all current checks, establish reproducible local HTTP and browser tests, exercise fresh schema plus all migrations, record retained behaviour and release dependencies.
1. Foundation: reusable person profile, linked account types, service memberships, explicit server permissions, stable payroll numbers, transactional worker conversion and audit history.
2. Recruitment: candidate detail, owner/due-date tasks, interview records, stage prerequisites, Google Forms mapping/failure UI, replay-safe intake and transactional email visibility.
3. Verification/onboarding: evidence review UI, authoritative evidence precedence, service-specific rules, signed contract evidence and explicit approval/activation. Reuse existing employment contract tables rather than the commercial Contracts resource.
4. Workforce: one practical assignment view, clients/sites, own-record worker portal, documents, leave/absence and training evidence. Preserve separate historical jobs/shifts tables unless a separately justified migration is approved.
5. Management/launch: scoped Today action list, a few accurate reports, exports, workload, health monitoring, expiry alerts, retention/rights controls, restore rehearsal and a small staff pilot.
6. Optional Academy integration: actual provider integration with stable IDs, replay safety, failure recovery and reconciliation. Course delivery remains external.

## Rules that must hold

- A person is linked across applications, workers, accounts and training. Do not match solely by name. Ambiguous matches require review.
- Account creation and worker creation are separate from employment approval.
- A payroll number is allocated once, remains unique under concurrency and is never silently regenerated.
- Onboarding started is not onboarding completed. A user cannot skip required steps by setting a status field directly.
- A document upload is not verification. Record reviewer, evidence, date, outcome and reason. Newest applicable evidence has defined precedence. A rejection cannot be revived by older verified rows, legacy booleans or a later loose certification match.
- Management owns service requirements, reference counts and expiry policies. Do not silently tighten legacy requirements across an existing workforce. Produce an affected-record report and a review queue before changing enforcement.
- Activation and all assignment paths consult the same authoritative rules. Test staff assignment, jobs, worker claims and offers if those paths remain enabled. Check suspension, leaver state, incomplete onboarding, signed contract evidence, service approval, expired checks, leave and overlapping assignments.
- Check role, service scope, sensitive zone and record ownership on the server for every applicable list, detail, write, upload, download, search, report and export. Apply scope to aggregate counts too.
- Worker responses contain only their own authorised fields. Dashboard or offline caches must never leak a previous user's data after logout/account switching or supply authoritative eligibility for a write.
- Employment contracts are versioned evidence. Use upload, sent/accepted/signed records and authorised review. Do not build an electronic signature service.
- Persist important multi-row changes atomically. Add uniqueness/idempotency safeguards for retries. Keep audit and queued-message intent consistent with the business transaction.
- Use the existing queue and logs. SMTP acceptance is not proof of recipient delivery. Show failed/skipped attempts honestly and provide safe retries.
- Keep privacy, retention and recovery in the core build. Do not invent legal retention periods or destroy employment evidence as cleanup.

## Lean interface

Main navigation: Today, Recruitment, People, Compliance, Assignments, Reports, Settings. Person-specific documents, contracts, leave and training live inside the profile. Use familiar labels, accessible forms, clear reasons for blocked actions and real loading/empty/error/permission states. Preserve working public pages and links until their replacement is explicit.

Today must link to usable actions, not display decorative totals. Staff must be able to complete the launch journey without editing SQL, reading source files or using undocumented API calls.

## Removal protocol

Start with the removal table in the approved plan. Classify exact paths as confirmed removable, retained, deferred or uncertain. Search imports, routes, dynamic loads, public links, API calls, scheduled jobs, deployment references and data dependencies. No reference found is not enough for publicly addressed PHP endpoints or cron jobs.

Remove QuizBuilder and its feature-specific code while preserving training records. For optional AI, weather, financial and field features, establish business usage and a replacement process before removal. Never retire panic/safety functions by hiding them. Keep the payroll identifier even if payroll calculation screens are retired.

For each approved removal, record the reason, paths, replacement, data impact and tests. Remove callers and exposed server routes consistently. Preserve needed redirects, retained records and migration history. Do not delete schema tables, uploads, audit logs, tests, legal pages or configuration merely because they look complex. Ask for exact-target approval when required for a destructive action, after preparing the reviewable change.

## Verification loop

For each phase, first reproduce relevant current behaviour. Then implement the smallest complete vertical journey and verify it through UI → API → database → resulting UI, including audit/message side effects. Finally challenge the implementation using denied access, invalid state transitions, retries, stale data and partial failures. Fix and rerun affected tests. Do not repeat unchanged tests as a substitute for covering missing behaviour.

Use these scenarios as minimum acceptance coverage:

1. Owner, each retained staff role, service-scoped staff, worker A, worker B, client and unauthenticated requests. Cross-service/cross-person ID substitution returns denial without data or mutation.
2. Public and Google intake, replayed response ID, invalid secret, malformed mapping, ambiguous identity and recovered failed submission.
3. Concurrent onboarding requests and payroll allocation. One intended worker conversion, no partial records or duplicated checklist/messages.
4. Stage skipping and checklist tampering. Incomplete contract, missing verification, newest rejection with older success and expired mandatory evidence all block appropriately.
5. Approved worker assigned successfully. Suspended/leaver/unapproved worker, wrong service, approved absence and overlapping assignment fail through every retained write path.
6. Protected document upload/download, unsupported types, oversized files, unauthorised record attachment, archive and approved deletion behaviour.
7. Worker own-profile, upload, assignment and leave journey on mobile. Keyboard operation, focus, errors, empty data, network failure, logout and account switching.
8. Email queue success in a local mail sink, disabled sender, transient failure, exhausted retries and duplicate processing. No real recipient messages during local verification.
9. Report totals across pagination, scoped exports and audit records; expiry changes reflected consistently in profile, assignment decisions and alerts.
10. Fresh install, upgrade from a representative earlier schema, repeated migration/backfill, restored database plus documents, and application smoke tests against the restored environment.

Run the repository quality gates after cohesive changes:

```sh
npm run typecheck
npm run lint -- --max-warnings 0
npm test
npm run test:php
npm run build
python3 scripts/docs_sync.py --check
```

Also syntax-check all PHP, verify expected build artefacts/no source maps, and run the meaningful HTTP/browser integration tests added for retained workflows. Do not weaken tests or lint rules to obtain green results. Test tools may be development-only; production hosting restrictions do not justify omitting browser/database testing.

The final release must depend on all required checks for the exact released commit. Coordinate schema, PHP and frontend compatibility. Prepare a tested rollback and restoration procedure, and a versioned release manifest. Do not actually deploy or change production merely because the build passed.

## Evidence and stopping conditions

For every phase report what changed, files affected, acceptance scenarios exercised, command results, limitations and remaining defects. Link to reproducible tests rather than relying on a historical statement of success. Update relevant docs in the same change.

Proceed through approved local phases while safe work remains. Stop expanding scope when the core CMS meets its acceptance criteria. Optional Academy integration requires a real integration need and confirmed provider details.

Do not call the system perfect, secure or production-ready solely because static checks pass. A full-launch recommendation requires passing core role journeys, no unresolved critical access/data/activation defects, verified recovery, operational ownership and a successful staff pilot. List anything untested explicitly and name the next concrete action.
