# Current Task Contract

## Outcome

Implement the approved lean CMS launch plan (`docs/LEAN-CMS-LAUNCH-PLAN.md`, approved
14 September 2026) **and its 2026 extension** (approved by the owner on 9 October 2026, recorded
in [`docs/DECISIONS-2026.md`](../docs/DECISIONS-2026.md)), section by section, verifying each before the next.

The launch unit is one complete journey: application → recruitment review → evidence
verification → onboarding → authorised approval → active worker → eligible assignment →
ongoing compliance and expiry follow-up. The 2026 extension adds the rota, the staff portal,
the green design, the rebuilt website, CMS-managed vacancies and forms, spreadsheet import and
social profile copy.

Superseded: the "verification and correctness pass" contract this file previously carried. Its
work is done and its evidence is in `docs/CHANGELOG.md` and `docs/PRIVACY_READINESS_REPORT.md`.
Nothing it established is discarded — the defects it fixed stay fixed, and its rule that a claim
needs a command behind it is carried forward here.

## Section board (the progress tracker)

Every agent on every machine reads this table before starting and updates it when a section
changes state. Claim a section by setting 🚧 with your branch and machine, commit and push that
change on your section branch **before** writing code, so others can see the claim. What each
section delivered, and its pull request, is in [`docs/DECISIONS-2026.md`](../docs/DECISIONS-2026.md).

Key: ✅ merged into `lean-cms/build`, all checks green · 🚧 in progress · ⏳ waiting · ⛔ blocked

| ID | Section | State | Branch / PR | Notes |
|---|---|---|---|---|
| S0 | Plan pack, tracker, test toolbox, CI database job fixed | ✅ | `plans/2026-mvp`, PR #9 | CI database job green for the first time (MariaDB, env fix) |
| S1 | Brand and shell (green tokens, fonts, eight-destination nav, feature switches) | ✅ | `mvp/s01-brand-shell`, PR #10 | Seeds keep production behaviour (finance, payments, SMS, inbox on; AI off). Leftover "Care/Academy" copy, og.png and website gradients go to S6/S7 |
| S2 | People and compliance (person profile, verification, RTW, onboarding, contracts, activation) | ✅ | `mvp/s02-people-compliance`, PR #11 | Migration 037. Also fixed missing service scope on verifications, right-to-work, leave, employment-contracts, compliance. Follow-up: label checks that are Valid only from a legacy certification record (no verifier) |
| S3 | Rota (draft/publish, edit, cancel, sites, leave and rest checks, timesheets) | ✅ | `mvp/s03-rota`, PR #13 | Migration 038. FullCalendar removed. Service scope added to scheduling/timesheets. Rest-gap setting has API only, no screen |
| S4 | Staff portal (Home, Rota, Records, Me) | ✅ | `mvp/s04-staff-portal`, PR #15 | Worker clock-in now refused earlier than 15 min before start. Deploy: upload the 9 PHP files in the PR first. Seed label mismatch "Moving & Handling" vs requirement "Moving and handling" |
| S5 | Today and Reports | ✅ | `mvp/s05-today-reports`, PR #17 | Migration 043. Action Centre/dashboard now scoped and zoned. Deletion requests (governance.php) still have no screen. Today vs Workforce report can differ until nightly sweep |
| S6 | Website rebuild | ✅ | `mvp/s06-website`, PR #12 | Deploy: upload quotes.php, contact.php, pure.php before frontend; copy .htaccess 301 block by hand. Hero uses stock care photo until real photography |
| S7 | Careers, forms directory, CMS company details and socials | ✅ | `mvp/s07-careers-cms`, PR #14 | Migration 039. Deploy: copy CSP `frame-src` line to live .htaccess; owner sets intake secret in Settings; Apps Script per docs/runbooks/google-forms-intake.md. Real Google Form not yet tested |
| S8 | Spreadsheet import | ✅ | `mvp/s08-sheet-import`, PR #16 | Migration 042 (040/041 intentionally unused). CSV only (Google Sheets: File > Download > CSV). Owner/admin/manager only. Retention period for open imports is an owner decision |
| S9 | Retirements (Content Studio, AI assistant, quiz builder, weather, Analytics/Insights) | ✅ | `mvp/s09-retirements`, PR #18 | Deploy: delete server/ai.php, weather.php, analytics.php on the live host by hand; apexcharts removed |
| S10 | Launch gate and docs consolidation | ✅ | `mvp/s10-launch-gate`, PR #19 | Local restore rehearsal passed; 21 browser journeys passed; release steps in docs/runbooks/release-2026.md. Removing `docs/Plans 2026/` awaits owner approval |

## Product boundary

One company, RitaGreen Services. Four services, shown publicly as Healthcare, Cleaning,
Security and Training (internal identifiers `care`, `cleaning`, `security`, `academy` stay).
One person record.

This is a company management system. It is not a learning management system. Lessons, quizzes,
assessments, course authoring, gradebooks, video hosting, classrooms, SCORM and learner
progression are out of scope, and the existing AI quiz generator in `AcademyDetail.tsx` is to be
removed. Training **records** and external certificate evidence are retained. Care plans and
medication records are out of scope.

Keep React, Vite, TypeScript, the existing UI components, the flat PHP endpoints and MySQL. No
framework migration, no multi-tenancy, no microservices, no generic workflow engine, no
permissions DSL, no new state library.

## Scope

- Phases 0 to 5 of the approved plan and sections S0 to S10, in dependency order.
- Reuse before building: existing tables, helpers, access controls, queues and components.
- Removal only through the plan's removal protocol, recorded per path with its verification.
- Keep `docs/`, `CLAUDE.md` and the STATUS/PROGRESS/TRACEABILITY set accurate as code changes.
- One branch per section, based on `lean-cms/build`, named as in the board. Push the branch and
  open a pull request into `lean-cms/build`. Merge it only when every check below is green.

## Outside scope

- Pushing to or merging into `main` (which deploys), or uploading PHP to the live host. Merging
  pull request #8 into `main` is the owner's release decision.
- Applying migrations to, or reading, the production database.
- Reading or printing `server/config.php`.
- Real email, SMS, WhatsApp, payment or AI calls — local runs keep every integration disabled.
- Creating, renaming or posting on any social or Google account.
- Phase 6, the external Academy integration: it needs a confirmed provider, and there is none.
- Deleting historical employment, financial, certificate or audit data as cleanup.
- Dropping database tables because a feature is retired.

## Mode and workflow

- Primary mode: Existing Application.
- Workflow: Change Safety. Inspect the artefact, change the smallest thing that fixes the root
  cause, verify by exercising it, record the evidence.

## Evidence

Every section reports in its pull request: what changed, which files, which acceptance scenarios
were exercised, the commands and their results, what remains broken, and what was not tested.

- `npm run typecheck`, `npm run lint -- --max-warnings 0`, `npm test`, `npm run test:php`,
  `npm run test:db`, `npm run build`, `python3 scripts/docs_sync.py --check` — all green, all
  blocking in CI.
- Database-backed HTTP tests for anything that touches authorisation, scope, a state transition
  or persistence. A pure-function test is not evidence that a workflow works.
- Browser journeys for what a person actually does.
- `NOT TESTED` stated plainly wherever it applies.

### Machines without PHP or MySQL

Use Docker: the "Docker toolbox" section of `docs/TESTING.md`.

## Completion

The core journey works for the owner, for staff and for a worker; the negative cases are refused;
the documentation matches the code; a release candidate and its evidence are prepared. Production
deployment is a separate authorisation and is not part of this contract.

## Phase record (launch plan)

| Phase | State | Evidence |
|---|---|---|
| 0 — reproducible local baseline | ✅ done | `docs/TESTING.md`, `npm run test:db`, CI `database` job |
| 1 — foundation and one person record | ✅ done | `030_service_scope`, `040_worker_conversion`, `050_identity`, `060_payroll_backfill`; migrations 031, 032 |
| 2 — recruitment and communications | ✅ done | `070_recruitment`, `080_forms_intake`, `090_communications`; migration 033 |
| 3 — verification, onboarding, activation | ✅ done | `100_evidence_precedence`, `110_activation`, `140_person_profile`, `150_onboarding_and_approval`; migrations 034, 035, 037; UI PR #11 |
| 4 — workforce and worker portal | S3, S4 | Office side in PR #13 (migration 038, `160_rota`); worker side in PR #15 (`180_staff_portal`, no migration) |
| 5 — owner visibility and launch readiness | S5, S10 | Visibility in PR #17 (migration 043, `200_today`, `210_reports`, `220_profile_pack`, `230_system_health`); launch gate in S10 |
| 6 — external Academy integration | deferred, no provider | |

## Handoff: Clerk sign-in (30 October 2026, branch `move/cpanel-clerk`)
Built and tested locally, NOT deployed (owner: no backup, no users, no data moves). `server/clerk-lib.php`, `current_user()` accepts a Clerk bearer, migration 044, `src/auth/ClerkSession|ClerkGate|ClerkArea`, `ClerkLogin`, API client token, lock screen and 2FA card off under Clerk, CSP names the Clerk hosts. Clerk app "Rita Green Services" (production instance on ritagreenservices.com, DNS verified, email code only, test user gakinz101@gmail.com). Steps: docs/runbooks/release-2026.md "Clerk sign-in".
Evidence: `npm run test:php` 870 assertions; `npm run test:db` 1201 assertions on a private throwaway MariaDB (new case 250); `npm test` 123; typecheck and lint clean; build with the live key OK, no secret keys, entry bundle +1 KB (Clerk is lazy); `docs_sync.py --check` consistent.
NOT TESTED: a real sign-in; the CSP in a browser; the live server (state vs `main` not inspected).

## Read-only server check (30 October 2026)
Live ritagreenservices.com is the 3 to 4 September build (migrations 001 to 019 applied; 23 repo PHP files missing; 8 users, 8 workers, 6 clients, 15 people). Not deployed: Clerk cannot go up alone and the full release changes real employment records (owner had waived a backup; not taken). Details in docs/runbooks/release-2026.md.

## Release in progress (30 October 2026, owner said yes to option 1: backup, then the full release)
- DONE: backup `~/backups/ritagreen_os-2026-10-30_062740.sql.gz` (72 tables = live, "Dump completed"; restore NOT drilled; backup.php pruned 3 old dumps by its own rule); PHP and `.htaccess` copied to `~/private_backups/php-predeploy-2026/` (with `config.php.before-clerk`).
- DONE: live `config.php` (still 600): added RG_ENV production, APP_SECRET (generated on the server, not printed), CLERK_ISSUER and CLERK_AUTHORIZED_PARTIES.
- DONE: uploaded server PHP by checksum (46 new, 56 changed; no config files), `schema.sql`, `migrations/` (42 files). All 87 PHP files lint on the server (8.4.27). Home 200, sign-in check unchanged.
- BLOCKED: `migrate.php --go --production` (24 pending: 020 to 039, 042, 043, 044) was refused by the auto-mode permission classifier. Not retried. The live site still runs the old app against the new PHP; per the runbook the new PHP works on the old DB only partly, so finish the migration or roll back the PHP promptly.
- PREPARED, not uploaded: merged `.htaccess` at `.local-test/htaccess/merged.htaccess` (live file + file-refusal block + old-address redirects + Clerk CSP; origin lock and SPA fallback kept).
- NEXT after the migration: activation report and backfills, move the retired `ai.php`, `weather.php`, `analytics.php` aside, upload the merged `.htaccess`, then `dist/` (excluding `.htaccess`), then the checks in the runbook.

## Release done (30 October 2026)
Migrations run by the owner (24 applied, 0 pending, counts unchanged); retired endpoints moved aside; merged `.htaccess` and `dist/` live with the live Clerk key; HTTP checks pass (see docs/CHANGELOG.md "Deployed"). Open: owner to try a real sign-in at /login; no Clerk users created and sign-up is still PUBLIC, so whoever can receive mail at a users-table address (dev@, admin@, manager@, trainer@, editor@, employee@, test.worker@, test.client@ ritagreenservices.com) can claim that role. Create Clerk users for the real people and restrict sign-up. Backfills not run. Rollback: restore PHP from `~/private_backups/php-predeploy-2026/` and `.htaccess.live-before-merge`; DB restore from the dump only if data is damaged.

## Sign-up restricted (30 October 2026, owner asked; chose "dev@ and admin@ only")
Clerk production instance for ritagreenservices.com: users created (no password, email admin-verified, nothing emailed) for dev@ritagreenservices.com (super_admin) and admin@ritagreenservices.com (admin); `sign_up_mode` set to restricted (dry run first). Nobody else can sign in until a Clerk user is created for them (and a `users` row exists). Other users rows (manager@, trainer@, editor@, employee@, test.worker@, test.client@) have no Clerk user yet. An earlier test Clerk user gakinz101@gmail.com also exists but has no `users` row, so it gets "No access yet". Rollback: set sign_up_mode back to public.

## Merged to main (30 Oct 2026)
- `move/cpanel-clerk` (already live) merged into `main`, so `main` matches the server again.
- `.github/workflows/deploy.yml` is now manual only (`workflow_dispatch`): no FTP deploy on push. Releases go over SSH (`pnpm release`, kit standard) once that script is added here.
- 30 Oct 2026: `npm run release` added (kit `scripts/release.mjs`, `release.json`). FTP `deploy.yml`, FTP secrets and FTP account go after the first successful `--go`.
- 30 Oct 2026: dry run showed the live server equal to `main`; FTP `deploy.yml` removed. Owner deletes GitHub FTP secrets and the FTP account with `~/Backups/scripts/remove-ftp-deploys.zsh`.
