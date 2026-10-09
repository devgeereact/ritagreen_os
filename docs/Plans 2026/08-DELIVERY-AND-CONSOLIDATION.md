# 08 Delivery and consolidation

## How work is organised

- **Build branch:** `lean-cms/build` (open as pull request #8 to `main`). All sections land there. `main` is production: merging #8 is a separate owner decision with the release steps written in #8.
- **One section, one branch, one pull request**, based on `lean-cms/build`, named `mvp/sNN-short-name`. Small commits inside, each with a clear message.
- **Tracker:** the section board in [`.agent/CURRENT-TASK.md`](../../.agent/CURRENT-TASK.md). Before starting a section, an agent sets it to 🚧 with the branch name and pushes that change, so another machine sees the claim. When its pull request is merged into the build branch with checks green, the section gets ✅. Blocked work gets ⛔ with the reason.
- **Item-level progress** stays in `docs/STATUS.json`, regenerated into `docs/PROGRESS.md` with `python3 scripts/docs_sync.py`. No second progress ledger.

## Sections

| ID | Section | Delivers | Depends on | Launch plan phase |
|---|---|---|---|---|
| S0 | Plan and tracker | This pack, the section board, the test toolbox notes | none | n/a |
| S1 | Brand and shell | Green tokens, Source Serif 4 for headlines, Plus Jakarta removed, eight-destination sidebar, Settings feature switches (Finance, payments, inbox, SMS) | S0 | n/a |
| S2 | People and compliance | Person profile with tabs, emergency contacts, verification queue, right-to-work screen, onboarding templates per service, contract evidence, activation approval | S1 | 3 (UI outstanding) |
| S3 | Rota | Draft and publish, edit, cancel with reason, reassign, sites as records, leave and rest-gap checks, timesheet submit and query | S2 | 4 |
| S4 | Staff portal | Four tabs (Home, Rota, Records, Me), document upload, leave requests, availability, emergency contacts, payslips | S2, S3 | 4 |
| S5 | Today and reports | Today page on the Action Centre, one Reports page, worker profile pack PDF, system health in Settings | S2 to S4 | 5 |
| S6 | Website | New design and copy, new sitemap, service pages, enquiry routing fixed, redirects from old paths, generated sitemap, structured data | S1 | n/a |
| S7 | Careers and CMS links | Vacancies, role pages, forms directory with embedded Google Forms, company details and social links from the CMS, `/links`, Apps Script runbook | S6 | 2 (intake exists) |
| S8 | Spreadsheet import | Import, map, preview, match, approve, apply, report (file 06) | S2, S7 | n/a |
| S9 | Retirements | Content Studio, AI assistant, quiz builder, weather widget, Analytics and Insights merged into Reports, each through the removal protocol | S5 | removal proposal |
| S10 | Launch gate and consolidation | Restore test, privacy checks, browser journeys, docs folded into the permanent files, this folder removed with owner approval | all | 5 |

S6 can run alongside S2 to S4 because it touches the public site, not the office app. Shared files (`src/routes/index.tsx`, `src/lib/access.ts`, `src/lib/types.ts`) are edited in small, separate commits to keep merges simple.

## Gate for every section

A section is ✅ only when all of these pass on its branch:

| Check | Command |
|---|---|
| Types | `npm run typecheck` |
| Lint, zero warnings | `npm run lint -- --max-warnings 0` |
| Frontend tests | `npm test` |
| PHP logic tests | `npm run test:php` |
| Database-backed HTTP tests | `npm run test:db` |
| Build, no source maps | `npm run build` |
| Docs in step | `python3 scripts/docs_sync.py --check` |
| Journeys | The section's journeys exercised in a browser against a local database with synthetic data; anything not exercised is written down as NOT TESTED in the pull request |

New behaviour that touches authorisation, scope, state or persistence gets a case in `server/tests/db/cases/`. Pure logic goes in `server/pure.php` with a test in `server/tests/run.php` (`CLAUDE.md`).

Never: run a migration against anything but a local or test database, push to `main`, read or print `server/config.php`, send real email, SMS or WhatsApp, or change a social account.

## Machines without PHP or MySQL

The suites need PHP 8.4 with `pdo_mysql` and a MySQL or MariaDB server (`docs/TESTING.md`). Where they are not installed, run them in Docker: a MariaDB container for the database and a PHP 8.4 CLI image with `pdo_mysql` for `test:php`, `test:db` and `dev:api`. The exact commands are recorded in `.agent/CURRENT-TASK.md`.

## Consolidation after the build (S10)

Each approved decision moves to its permanent home, then this folder is removed. Hand-written counts (endpoints, migrations, pages) are deleted rather than updated, because they go stale.

| Target | Receives |
|---|---|
| `docs/PRD.md` | Purpose, audiences, four services, boundaries (file 01) |
| `docs/BLUEPRINT.md` | Modules, person profile, rota and portal behaviour (files 02, 03) |
| `docs/ROADMAP.md` | What remains after S10 |
| `docs/DESIGN_SYSTEM.md` | Rewritten for the green system (file 04); navy and mint marked retired |
| `docs/ARCHITECTURE.md`, `docs/API.md`, `docs/SCHEMA.md` | Only what was built, as it was built |
| `docs/UK_GOVERNANCE.md` | Role checks per service (file 01) |
| `docs/RULES.md` | Durable rules only: one person, calculated compliance, no uploads in public forms, embed only validated form ids |
| `docs/STATUS.json`, `docs/TRACEABILITY.md`, `docs/CHANGELOG.md` | New items, evidence links, release notes; `docs/PROGRESS.md` regenerated |
| `docs/LEAN-CMS-LAUNCH-PLAN.md`, `docs/LEAN-CMS-BUILD-PROMPT.md` | Marked complete and superseded by the decision record |
| `docs/RITAGREEN_SETUP.md` | Marked superseded (launch plan already proposes this) |
| `CLAUDE.md`, `AGENTS.md`, `CODEX.md`, `README.md`, `server/README.md` | Accurate entry points, design pointer, no stale counts |
| `.agent/PROJECT.yml`, `.agent/CURRENT-TASK.md`, `.agent/MCP-PROFILE.yml`, `docs/GEE-OS.md` | Current stage, sources of truth, next contract |
| Agent memory | Project memories only for facts not in the repository |
| `docs/DECISIONS-2026.md` (new, short) | D01 to D06, dates, where each landed |

### Keeping it consistent afterwards

- Extend `scripts/docs_sync.py --check` to fail on broken markdown links in `docs/`, the root and `.agent/`, and on retired brand colours (`#0C447C`, `#5DCAA5`) appearing in docs or `src/`.
- Add `.claude/settings.json` hooks only where they prevent real harm: block edits to `server/config.php`, block hand edits to `docs/PROGRESS.md`, block `git push` to `main`. No hooks for style preferences.
- Removal of `docs/Plans 2026/` happens only after every row above is done, the link check passes, and the owner approves deleting exactly that folder.
