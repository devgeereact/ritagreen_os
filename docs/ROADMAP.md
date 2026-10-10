# Roadmap

Milestones for turning RitaGreen OS into a person-centred, service-aware, compliance-driven
platform. Rationale for the ordering is in `docs/BLUEPRINT.md` Part 4.

Machine-readable status lives in `docs/STATUS.json`; the rendered view is `docs/PROGRESS.md`.

---

## Milestone 0 — Documentation pack

**Goal:** every later phase executes against a written contract instead of a memory.
**Ships no functional change.**

- `docs/PRD.md`, `BLUEPRINT.md`, `ARCHITECTURE.md`, `SCHEMA.md`, `API.md`, `DESIGN_SYSTEM.md`,
  `RULES.md`, `UK_GOVERNANCE.md`, `ROADMAP.md`, `TRACEABILITY.md`, `CHANGELOG.md`
- `docs/STATUS.json` and generated `docs/PROGRESS.md`
- `scripts/docs_sync.py` — regenerates `PROGRESS.md`, validates traceability
- `.github/workflows/ci.yml` — typecheck, lint, build, `php -l`, docs check
- Rewrite `README.md` and `CLAUDE.md` to describe the real architecture
- Retire the superseded brand artefacts: delete `DESIGN-SYSTEM.md` and `RitaGreenOS.html`, fix
  the manifest theme colour

**Done when:** CI is green, `docs_sync.py --check` exits 0, and every claim in `API.md` and
`SCHEMA.md` has been verified against `server/`.

---

## Prerequisite — Local development environment — DONE

**Blocked every milestone below. Development used to read and write the live production
database**, because `vite.config.ts` proxied `*.php` to `https://ritagreenservices.com` and
there was no local alternative.

- `server/config.local.php.example` — local database, every outbound integration disabled
- `npm run dev:api` — `php -S 127.0.0.1:8122 -t server`
- Vite proxy target from `RG_API_TARGET`, defaulting to local, warning when overridden
- `server/seed.php` — fixtures with a deliberate spread of compliance states; CLI-only,
  local-host-only, `--confirm` required
- `npm run typecheck`, now `tsc -b --force` so it cannot silently no-op

**Outstanding, and outside what the repository can supply:** a local MySQL or MariaDB server
must be installed on the developer's machine. Everything else is in the repository.

---

## Milestone 1 — Person spine

**Goal:** one human, one record, forever. Unblocks everything after it.

**Status: applied and verified locally. Production is at migration 019** — the deployment
record for 2026-09-03 in `docs/CHANGELOG.md` says the live database was at 18 applied with 0
pending and that 019 was applied that day, so **020 is the only one outstanding there.** That
count comes from the deployment record, not from a check against the live database.

The full chain runs from scratch against MariaDB: schema imported, migrations 001–003 applied,
fixtures seeded, backfill run, 004 applied once its precondition passes. Verified: 22 people
from 23 source records with no duplicates; the UNIQUE `match_key` refuses a second person with
the same identity; 004's precondition blocks on an unlinked row and reports the count; and the
API answers correctly against the migrated database, including all four access restrictions.

Remaining before this milestone is complete:

- ~~Rewrite `ats.php` `onboard` and `from_learner` to link rather than copy~~ — done, and
  `server/seed.php` goes through `find_or_create_person()` too. Verified: an application
  onboarded and then re-submitted through `from_learner` produces one `people` row, two
  applicant rows and one worker, all pointing at it
- Assign payroll numbers to existing active workers. `next_payroll_no()` runs on onboarding, so
  only workers created before it exists are missing one — ten of the ten local fixtures
- The unified person profile in the interface, replacing the separate applicant and worker views
- Link `enrolments` by hand as the Academy module is rebuilt — they carry only a learner name
- Apply migration 020 to production, after a restore-tested backup

- `server/migrations/` established, numbered and applied in order
- `people` table — the identity
- `person_services` — person × service, carrying per-service eligibility
- `person_id` added to `workers`, `applicants` (becoming candidates), `users`, `enrolments`
- De-duplicating migration: match on email, then phone; **ambiguous matches go to a review
  list, never an automatic merge**
- Payroll number generator on `next_seq()` — unique, permanent, never reused, immutable,
  assigned on activation
- `server/ats.php` `onboard` and `from_learner` rewritten to **link, never copy**
- Person record UI: one profile, tabbed, replacing the separate applicant and worker views

**Risk:** highest of the programme. Verification requirements are in the Verification section
below.

**Done when:** onboarding an applicant creates zero new person rows, and no email address maps
to more than one `person_id`.

---

## Milestone 2 — Compliance engine

**Goal:** compliance becomes calculated, not remembered.

**The decision logic is built and tested**, ahead of its storage, because it is pure and
therefore verifiable without a database — see `rg_evaluate_eligibility()` in `server/pure.php`
and its 48 assertions in `server/tests/run.php`. It already implements the weighting, the
blocking-versus-warning distinction, the score, and the human-readable reason.

Remaining:

- `compliance_requirements` — the catalogue of things that can be required
- `compliance_rules` — service × role × requirement × mandatory/recommended/optional
- Loading a person's evidence into the shape the evaluator expects
- Rule builder in Settings, owner-configurable
- Compliance status becomes read-only everywhere in the UI

**Done when:** the system can answer "may this person work in Security, and if not, why not?"
without a human interpreting a list of dates.

---

## Milestone 3 — Verification: right to work, SIA, DBS

**Goal:** evidence, not assertions.

- `right_to_work_checks` replacing `workers.right_to_work TINYINT(1)` — method, date,
  reference, evidence, outcome, verifier, follow-up flag, recheck date
- SIA verification as a mandatory workflow with its own record
- DBS moved into the same lifecycle, retaining the Update Service flag
- Generalised `verifications` table: requested → received → under review → verified/rejected
- Document metadata extended: issue date, expiry, verification status, verifier
- Migration preserves the existing boolean as a historical check record; it is not discarded

**Done when:** no compliance-relevant fact is recorded without a verifier, a date and an
evidence reference.

---

## Milestone 4 — Deployment gate

**Goal:** ineligibility blocks the action.

- The `server/scheduling.php` guard generalised into one reusable eligibility check
- Called by **every** assignment path, not just scheduling
- Returns PASS, or FAIL with the specific blocking reason
- UI shows the check result before the action and the reason on refusal
- Four-eyes approval for the highest-risk actions

**Done when:** there is no code path that can assign an ineligible person to work.

---

## Milestone 5 — Permissions and zones

**Goal:** "Staff" stops meaning "full access".

- `roles` passed to `RequireAuth` on every route in `src/routes/index.tsx`
- `require_role()` added to the endpoints that only call `require_auth()` — including
  `dashboard.php`, `field.php`, `notifications.php`, `ai.php`, `weather.php` (the last two
  since retired in S9)
- Per-service scoping enforced as a query filter, not just a stored preference
- Sensitive zones — general, employment, compliance, health, disciplinary, payroll, legal —
  each with its own permission rule
- Permission matrix documented and tested
- Role-filtered navigation

**Done when:** a client account cannot load `/app`, and a Care recruiter cannot read a Security
candidate.

---

## Milestone 6 — Employment records

**Goal:** the employment relationship is a record, not a folder of PDFs.

- Employment contract lifecycle: draft → sent → viewed → accepted → signed → superseded, versioned
- Distinguished in name and model from the existing commercial `contracts` table
- Structured written-statement particulars as queryable fields
- Leave and absence: annual leave, sickness, other leave, return-to-work records
- Worker status derived from workflow events rather than set by hand

**Done when:** the particulars of someone's employment can be reported on without opening a
document.

---

## Milestone 7 — Communications

**Goal:** mail leaves the request path and becomes observable.

- `email_templates` — recruitment, verification, onboarding, compliance, training
- `email_queue` — drained by cron, so a slow SMTP server never slows the application
- `email_log` — recipient, subject, template, queued, sent, failed, error
- `server/lib.php` `send_mail()` becomes an enqueue; `smtp_send()` moves to the drainer
- Delivery failure surfaced in the UI, not silently swallowed

**Done when:** no user-facing request opens an SMTP socket, and every send has a record.

---

## Milestone 8 — Intake and integrations

**Goal:** the office's existing front door reaches the system, and the CMS still owns the record.

- Google Forms webhook: Apps Script POSTs to a PHP endpoint with a shared secret
- Configurable field-mapping table, so a changed form needs no code change
- Both the native `/apply` page and the Forms route land in the same candidate pipeline
- Integration health monitoring with last-successful-submission tracking and an alert on silence
- Academy enrolment API so training records link to the person automatically

**Done when:** the form can be edited by the office without a developer, and a stalled
integration raises an alert rather than being noticed by its absence.

---

## Milestone 9 — Governance, PWA, hardening

**Goal:** the system is defensible and installable.

*Governance*
- Rich audit log: before/after values, entity reference, IP, reason
- Retention engine, configurable per record type
- Deletion approval workflow: requested, approved, reason, logged
- Export centre with every export logged against a user and a purpose
- Action Centre and staff workload view
- Workflow rule engine — when X, do Y

*PWA — installable plus offline reads, per the agreed scope*
- `vite-plugin-pwa` replacing the hand-written `public/sw.js`, with a build-hash precache
  manifest so offline survives a deploy
- 192 and 512 px PNG icons; manifest theme colour corrected to navy
- Update-available prompt, offline banner
- Cached read-only worker data: own profile, own compliance, own certificates, today's assignment
- **Writes require connectivity.** No offline write queue, no conflict engine

*Hardening*
- Stop leaking exception messages in `server/lib.php`
- System health dashboard: database, storage, mail, integrations, automations
- Backup restore testing as a documented, scheduled procedure
- Remove the unused dependencies the audit found

**Done when:** the app installs and works offline for reads, every sensitive action is
attributable, and no record is retained without a policy.

---

## Deliberately not on this roadmap

Full payroll processing · accounting · a learning management system · a full CRM · complex
rota management · AI recruitment scoring or automated hiring decisions · biometric monitoring
or employee surveillance.

See `docs/PRD.md` §6.
