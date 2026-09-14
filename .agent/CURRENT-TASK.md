# Current Task Contract

## Outcome

Implement the approved lean CMS launch plan (`docs/LEAN-CMS-LAUNCH-PLAN.md`, approved
14 September 2026) against this repository, phase by phase, verifying each before the next.

The launch unit is one complete journey: application → recruitment review → evidence
verification → onboarding → authorised approval → active worker → eligible assignment →
ongoing compliance and expiry follow-up.

Superseded: the "verification and correctness pass" contract this file previously carried. Its
work is done and its evidence is in `docs/CHANGELOG.md` and `docs/PRIVACY_READINESS_REPORT.md`.
Nothing it established is discarded — the defects it fixed stay fixed, and its rule that a claim
needs a command behind it is carried forward here.

## Product boundary

One company. Four services: Care, Cleaning, Security, Academy. One person record.

This is a company management system. It is not a learning management system. Lessons, quizzes,
assessments, course authoring, gradebooks, video hosting, classrooms, SCORM and learner
progression are out of scope, and the existing AI quiz generator in `AcademyDetail.tsx` is to be
removed. Training **records** and external certificate evidence are retained.

Keep React, Vite, TypeScript, the existing UI components, the flat PHP endpoints and MySQL. No
framework migration, no multi-tenancy, no microservices, no generic workflow engine, no
permissions DSL, no new state library.

## Scope

- Phases 0 to 5 of the approved plan, locally, in order.
- Reuse before building: existing tables, helpers, access controls, queues and components.
- Removal only through the plan's removal protocol, recorded per path with its verification.
- Keep `docs/`, `CLAUDE.md` and the STATUS/PROGRESS/TRACEABILITY set accurate as code changes.
- Commit on `lean-cms/build`.

## Outside scope

- Pushing, merging to `main` (which deploys), or uploading PHP to the live host.
- Applying migrations to, or reading, the production database.
- Real email, SMS, WhatsApp, payment or AI calls — local runs keep every integration disabled.
- Phase 6, the external Academy integration: it needs a confirmed provider, and there is none.
- Deleting historical employment, financial, certificate or audit data as cleanup.
- Dropping database tables because a feature is retired.

## Mode and workflow

- Primary mode: Existing Application.
- Workflow: Change Safety. Inspect the artefact, change the smallest thing that fixes the root
  cause, verify by exercising it, record the evidence.

## Evidence

Every phase reports: what changed, which files, which acceptance scenarios were exercised, the
commands and their results, what remains broken, and what was not tested.

- `npm run typecheck`, `npm run lint -- --max-warnings 0`, `npm test`, `npm run test:php`,
  `npm run test:db`, `npm run build`, `python3 scripts/docs_sync.py --check` — all green, all
  blocking in CI.
- Database-backed HTTP tests for anything that touches authorisation, scope, a state transition
  or persistence. A pure-function test is not evidence that a workflow works.
- Browser journeys for what a person actually does.
- `NOT TESTED` stated plainly wherever it applies.

## Completion

The core journey works for the owner, for staff and for a worker; the negative cases are refused;
the documentation matches the code; a release candidate and its evidence are prepared. Production
deployment is a separate authorisation and is not part of this contract.

## Phase record

| Phase | State | Evidence |
|---|---|---|
| 0 — reproducible local baseline | done | `docs/TESTING.md`, `npm run test:db`, CI `database` job |
| 1 — foundation and one person record | in progress | |
| 2 — recruitment and communications | not started | |
| 3 — verification, onboarding, activation | not started | |
| 4 — workforce and worker portal | not started | |
| 5 — owner visibility and launch readiness | not started | |
| 6 — external Academy integration | deferred, no provider | |
