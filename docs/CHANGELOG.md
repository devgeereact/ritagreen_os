# Changelog

Notable changes to RitaGreen OS. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Per `docs/RULES.md` rule 21, completing a module means updating `docs/STATUS.json`,
regenerating `docs/PROGRESS.md`, and adding an entry here.

## [Unreleased]

### Added: sign in with Clerk, email code (2026-10-30, branch `move/cpanel-clerk`, not deployed)

- **Server.** `server/clerk-lib.php` checks a Clerk session token (RS256, signing keys cached, issuer, expiry, not-before and authorised-party checks) and maps it to an active `users` row; `current_user()` in `lib.php` accepts it beside the 64-hex session tokens. Migration `044_clerk_user_id.sql` adds `users.clerk_user_id` (unique, nullable, re-runnable). New keys `CLERK_ISSUER` and `CLERK_AUTHORIZED_PARTIES` in `config.sample.php`.
- **Linking.** The first sign-in links an active user whose email equals the token's verified email. A row linked to another Clerk user is never re-linked; an unknown email gets 401; sign-up never creates a user.
- **App.** `/login` shows Clerk's email-code form when `VITE_CLERK_PUBLISHABLE_KEY` is set (unset keeps the password form). `RequireAuth` resolves the person through Clerk for the office, the worker app and the client portal. The Clerk code loads only there, not on the public website. The idle lock screen and the 2FA card are replaced by a note under Clerk.
- **Content-Security-Policy.** `public/.htaccess` allows only the named Clerk hosts. NOT TESTED in a browser against the live policy.
- **Tests.** `server/tests/clerk.php` (token checks, in `npm run test:php`), `server/tests/db/cases/250_clerk_signin.php` (linking, bad tokens, roles), `src/lib/__tests__/clerk.test.ts`.
- **Not removed.** Password, 2FA, Google SSO, reset and the password session remain until the owner confirms.

### Changed: website header, home hero and footer (2026-10-12, local, not deployed)

Branch `website/hero-nav-refresh`. Frontend only: no endpoint, table or migration changes.

- **Header.** Four destinations instead of seven links: **Services**, Careers, About, Contact,
  then "Staff login" and "Request staff". Services is a Radix navigation menu (new
  `src/components/ui/navigation-menu.tsx`, dependency `@radix-ui/react-navigation-menu`
  1.2.22, pinned exact) in the 21st.dev "Navigation Menu" pattern: a green "Request checked
  staff" block, the four services with a Lucide icon and one line each, and the two Healthcare
  routes. The phone menu groups the services with their icons. Links stay links (no
  `role="menu"`), and the current section carries `aria-current="page"`. The link data moved to
  `src/lib/site-nav.ts`, shared with the footer.
- **Home hero.** The 21st.dev "Split Hero With Image Cards" pattern: the two Healthcare routes,
  which were a separate band, are now photograph cards beside the headline, with a solid scrim
  under the words and one framed "Checks in date, confirmed before every booking" card. The
  second action calls the office. A strip of the settings staffed follows the hero. One CSS
  entrance (`rise-in`, behind `motion-safe:`); no animation library, because the page is in
  the entry chunk.
- **Footer.** Green-900, four columns, contact details with icons, icon links to the socials,
  the legal line with the storage notice and staff login.
- **Demo content for development.** `src/lib/demo-content.ts` fills the testimonial and news
  bands on `npm run dev` when nothing is published, each block marked "Demo content,
  development only". Guarded by `import.meta.env.DEV` at the point of use; `npm run build`
  output contains none of the sample strings (checked with grep over `dist/`).
- `docs/DESIGN_SYSTEM.md` sections 4 and 5 describe the three website motions and the new
  header, hero, footer and demo content.

Checked: typecheck, `eslint --max-warnings 0`, `npm test` (118 passed), `npm run build`; in a
browser at 1440px (Services panel, hero, footer) and 390px (hero, phone menu, no horizontal
scroll). Not run: `npm run test:php`, `npm run test:db`, the launch journeys.

### Added: launch gate, follow-ups and docs consolidation, section S10 (2026-10-10, local, not deployed)

The last section of the 2026 build. Nothing here has been run against production.

**Follow-ups found by earlier sections**

- A check that is valid only because of a legacy certificate row read as "Valid" with
  "Verifier: Nobody yet". `rg_load_evidence()` now tags each entry with its `origin`, the
  profile API returns `evidence_origin`, and the checks tab says "From certificate record, not
  yet verified" (or the worker record, the old right-to-work tick, references, a contract file).
  The evaluator does not read the tag; precedence is unchanged, and a rejection still outranks a
  certificate (`240_launch_followups`).
- The seed held "Moving & Handling" against the requirement "Moving and handling", so the
  certificate counted for nothing. The seed uses the requirement's label, and
  `rg_label_key()` reads "&" as "and" when matching certificate labels: case, spacing and the
  ampersand, nothing looser.
- Settings, Features has a "Rota rules" card for the minimum rest between shifts (owners
  change it, the office reads it). It had an API only.
- Settings has a "Data requests" tab: deletion requests listed, approved or declined, with the
  four-eyes rule said before anyone presses anything (`is_mine`) and the cascade confirmation
  the endpoint asks for. Today's deletion item now links there.
- `inbox.php?action=unread` answers `{unread: 0, configured: false}` instead of 503 when no
  mailbox is set up, and the sidebar stops polling.
- Active training forms in the forms directory appear on `/training` the way a role page shows
  its form: nothing from Google until the visitor presses the button, a new-tab link and the
  phone number always there. New public read `website-forms.php?action=public&purpose=training`.
  The privacy notice and `docs/COOKIES.md` say so.
- `160_rota` no longer depends on the date either: the clock-out minutes are checked against the
  London wall clock of the stored instants, so the two clock-change nights pass too.

**Launch gate**

- `server/restore-check.php` (CLI only): compares a restored database with the source table by
  table and the restored uploads document by document, and with `--record` writes the
  `restore_test` heartbeat. System health reads it (ok within 90 days) instead of a permanent
  warning. Rehearsed locally: backup, restore into `ritagreen_os_test_s10_restore`, 88 tables
  and 1240 rows matched, documents byte-identical, downloads through `files.php` from the
  restored copy identical; a damaged copy failed as it should. `docs/runbooks/backup-and-restore.md`.
- Browser journeys from public application to approved timesheet, and five refusals, all PASS
  on a fresh test database: `scripts/launch-journeys.mjs`, checklist in `docs/TESTING.md`.
- `docs/runbooks/release-2026.md`: the production steps for merging #8, not performed.

**Consistency**

- `scripts/docs_sync.py --check` fails on broken relative markdown links (docs/, root, .agent/,
  server/README.md) and on the retired navy and mint outside history. Tests in
  `scripts/test_docs_sync.py`, run in CI.
- `.claude/settings.json` PreToolUse hooks (`scripts/agent_guard.py`) block writes to
  `server/config.php`, hand edits to `docs/PROGRESS.md` and pushes to `main`. Tests in
  `scripts/test_agent_guard.py`, run in CI. Codex has no hooks; the rules apply by hand.
- The 2026 plan pack is folded into the permanent docs, with `docs/DECISIONS-2026.md` for the
  owner's decisions and the build's own. `docs/Plans 2026/` stays until the owner approves its
  removal.

**Deploy:** upload `compliance-lib.php`, `people.php`, `pure.php`, `seed.php`, `governance.php`,
`action-centre.php`, `inbox.php`, `website-forms.php`, `system-health.php` and the new
`restore-check.php`; add `restore-check` to the CLI-tool refusal line in the live `.htaccess`.
No migration. All of it is in `docs/runbooks/release-2026.md`.

### Removed: retirements, section S9 (2026-10-10, local, not deployed)

The features the 2026 plan retires (file 02, "Keep, switch off, retire") are deleted through the
launch plan's removal protocol. Code is deleted; no table is dropped and no row is deleted.
Routes, imports, server files, configuration and dependencies went together, and every old
address that had a sensible replacement still lands somewhere.

**Deploy note.** `server/ai.php`, `server/weather.php` and `server/analytics.php` must be
**deleted from the live server by hand**: `deploy.yml` never touches PHP, so uploading the new
frontend alone leaves them reachable. Upload the changed `server/files.php`, `server/lib.php` and
`server/pure.php` at the same time. A live `config.php` that still defines `OR_*` or
`OPENWEATHER_*` is harmless; the owner may remove those keys.

#### Removal record

| Path | Reason | Replacement | Callers before removal | Routes and endpoints | Data impact | Verification |
|---|---|---|---|---|---|---|
| `src/pages/app/Analytics.tsx` | Duplicate surface (plan 02) | Reports (`/app/reports`) | none since S5 (route already redirected) | `/app/analytics` keeps its redirect to `/app/reports` | none | grep finds no import; old URL redirects in a browser |
| `src/pages/app/Insights.tsx` | Duplicate surface | Reports | none since S5 | `/app/insights` keeps its redirect to `/app/reports` | none | as above |
| `server/analytics.php` | Fed only Analytics.tsx | `reports.php` | `Analytics.tsx` | endpoint deleted (404) | none; reads only | db case `231_retirements`; guard in `run.php` |
| `apexcharts`, `react-apexcharts` (package.json) | Last users were Analytics and Insights | none needed | `Analytics.tsx`, `Insights.tsx` | none | none | `npm run build` passes; `vite.config.ts` glob for its chunk removed |
| `src/components/app/weather-widget.tsx` | No business purpose | none | no importer (already unmounted) | none | none | grep finds no import |
| `server/weather.php` | As above; third-party call to OpenWeather | none | `weather-widget.tsx` | endpoint deleted (404) | file cache in the system temp directory only | db case `231_retirements`; guard |
| `src/pages/app/ContentStudio.tsx` | AI writing; posts are written in Website | Posts (`/app/blog`) | route, ⌘K entry | `/app/content-studio` now redirects to `/app/blog`, same role gate | none (it saved nothing of its own) | redirect checked in a browser; `app-routes.test.ts` finds Posts by "content studio" |
| `src/components/app/ai-assistant.tsx` | Retired (plan 02) | none | `AppLayout.tsx` header | none | none | header renders without it |
| `QuizBuilder` and `parseQuiz` in `src/pages/app/AcademyDetail.tsx` | Not a learning platform | none; the training detail page, enrolment and certificate issue stay | the page itself | none | none (quizzes were never stored) | Academy detail opened in a browser |
| AI draft and AI routing buttons in `src/pages/app/Enquiries.tsx` | Called `ai.php` | "Reply by email" link | the enquiry dialog | none | none | Enquiries opened, dialog checked |
| AI summary in `src/pages/app/Files.tsx` | Sent document text to OpenRouter | Download | the file table | none | none | Files opened in a browser |
| `files.php?action=extract`, `docx_text()`, `pdf_text()` | Existed only to feed `ai.php` | none | `Files.tsx` | the action now falls through to the file list | none | db case `231_retirements` |
| "Draft with AI" in `Comms.tsx` and `Academy.tsx`, AI ping in `SmokeTest.tsx` | Called `ai.php` | write the text by hand | the forms | none | none | typecheck, lint, pages render |
| `aiDraft()` in `src/lib/api.ts` | No caller left | none | the pages above | none | none | typecheck |
| `server/ai.php` | Retired (plan 02); third-party call to OpenRouter | none | every item above | endpoint deleted (404) | `rate_limits` rows for it age out as usual | db case `231_retirements`; guard |
| `ai_assistant` feature switch (`rg_feature_defaults()`, `FeatureName`, Settings card) | Its only reader was `ai.php` | none | `AppLayout.tsx`, `ai.php`, Settings | `features.php` no longer lists it and answers 422 to setting it | **row kept** in `feature_switches`, unread | `run.php` feature switch group; db case `120_feature_switches` |
| `OR_API_KEY`, `OR_MODEL`, `OR_MODELS`, `OR_REFERER`, `OR_TITLE`, `OPENWEATHER_*` in `config.sample.php`, `config.local.php.example` and the test config; `lib.php` no longer requires `OR_*` | No reader left | none | `ai.php`, `weather.php`, `lib.php` start-up check | none | none; `server/config.php` not touched | PHP and db suites pass without the constants |
| OpenRouter in the privacy notice and `docs/PRIVACY.md`; OpenWeather in `docs/PRIVACY.md` | No longer a recipient | none | `src/pages/public/Legal.tsx` (notice now v2.2, 10 October 2026) | `/privacy` | none | page text checked |

Left alone, as the plan says: finance, payments, inbox, SMS and WhatsApp, field operations and
the panic button, `/verify`, Comms announcements and Files.

### Added: Today, Reports, client profile pack and system health, section S5 (2026-10-10, local, not deployed)

- **The Action Centre obeys service scope and zones.** Every count was over the whole company
  for every member of staff: a manager confined to security was told how many care checks had
  expired and who in care could not work, and an editor with no compliance zone saw the same.
  Groups now follow `rg_action_centre_access()` in `pure.php`; scoped counts are filtered by
  service or by person; email and intake counts are for unscoped office accounts only.
  `dashboard.php` is fixed the same way. db case `200_today`.
- **Today** at `/app` replaces the Command Centre: tiles (unfilled shifts, checks to review,
  evidence expiring in 30 days, approvals waiting), today's shifts with state chips, and Needs
  action with the people named and a button to the place to act. No charts and no money.
- **Reports** (`/app/reports`): pipeline, active and blocked workers with reasons, evidence
  expiring 30/60/90, unfilled shifts by week, approved hours by week (payroll zone), staff
  workload. Totals over the whole filtered dataset. CSV export is built on the server from
  declared columns only and recorded in `export_log`. Analytics and Insights redirect here (S9
  removes the files). Regulator packs now take the compliance zone and service scope. db case
  `210_reports`.
- **Download profile for client** on the person profile: a PDF with initials, name, role,
  services, training with dates, DBS date and recorded level, right to work verified and when,
  SIA for security, and nothing else. Compliance zone, audited. db case `220_profile_pack`.
- **System health in Settings**: when each scheduled job last ran (migration 043 `cron_runs`,
  written by the four cron scripts), shown as unknown until it reports; the email queue and
  recent failures without recipients; per-form intake health; the restore test as an explicit
  unknown. db case `230_system_health`.
- Deploy: apply migration 043; upload `action-centre.php`, `dashboard.php`, `reports.php`,
  `compliance.php`, `system-health.php`, `lib.php`, `pure.php`, `backup.php`,
  `expiry-alerts.php`, `retention-sweep.php`, `send-queued-mail.php` before the frontend.

### Added: the staff portal at /m, section S4 (2026-10-09, local, not deployed)

- **Four tabs, phone first** (`src/pages/worker/`): Home, Rota, Records, Me, with a bottom tab
  bar, a skip link, 44px tap targets, saved or failed messages after every action, and loading,
  empty and error states. The installable app is unchanged; the four tabs are lazy chunks inside it.
- **Home**: the next shift with "Today/Tomorrow", times with "(+1)" for nights, site, address with
  a plain map search link opened only on tap, site contact and notes; clock in from 15 minutes
  before start, clock out, check call, report a problem, panic alert; what stops the worker being
  booked, from the deployment check, with what to do; training due; messages.
- **Rota**: published shifts by day, offers with Accept and Decline, open shifts with Pick up and
  the server's reason on refusal, a list of open shifts the worker cannot take and why, and an
  .ics calendar file built on the phone (Europe/London, stable ids).
- **Records**: checks with status chips (icon and word) and upload of a new copy, which goes to
  "received" for the office and never verifies itself; training with certificate download;
  timesheets (confirm the break, submit, see a query and send again); gross pay by month with the
  GROSS PAY statement.
- **Me**: contact details (phone and address, written to `people`), emergency contacts
  (`rg_save_emergency_contacts()`), unavailable days, leave requests and balance, the office's
  numbers and email, log out.
- **Server** (`me.php`, new `portal-lib.php`, `files-lib.php`): `records`, `upload`, `download`,
  `offer_accept`, `offer_decline`, `report`, `profile` (GET and POST), `emergency`; the home read
  adds offers, blockers, training due, unread count and per-shift eligibility. Every action takes
  the worker from the login only. `offer.php`'s answer moved to `rg_offer_respond()` so the text
  link and the portal share it; `files.php` storage moved to `files-lib.php`.
- **Fixed**: a worker's own leave request stored any `paid_pence`, `in_lieu` or `carried_over` the
  body carried; they are ignored for non-staff now. A worker could clock in at any time before a
  shift; `field.php` now refuses a worker clock-in earlier than 15 minutes before the start.
- **Offline holds no personal data.** The service-worker rule caching `me.php` and
  `notifications.php` for 12 hours is removed and `/m` deletes the old `worker-reads` cache. The
  shell loads offline; each tab says "You are offline". `docs/PRIVACY.md` and `docs/COOKIES.md`.
- **Tests**: `180_staff_portal` proves a worker cannot read or change another worker's offers,
  documents, timesheets, gross pay, leave, availability, emergency contacts or contact details,
  and that client and office logins get nothing from `me.php`. Portal rules in
  `server/tests/run.php`; calendar, map link and day labels in `src/lib/__tests__/portal.test.ts`.
  `160_rota` no longer depends on the hour of day it runs.
- **Deploy**: upload `me.php`, `portal-lib.php`, `files-lib.php`, `files.php`, `offer.php`,
  `field.php`, `leave.php`, `compliance-lib.php` and `pure.php` before the frontend. No migration.
### Added: spreadsheet import, section S8 (2026-10-10, local, not deployed)

- **Recruitment > Import from spreadsheet** (`/app/ats/import`, office roles only): upload a
  Google Sheet downloaded as CSV, map its columns, preview every row, decide uncertain matches,
  tick rows, apply, and read a report with a failures download and an import history.
- `server/sheet-import.php` and migration **042** (`sheet_import_mappings`, `sheet_imports`,
  `sheet_import_rows`, a `retention_policies` row with no period). Numbered 042 because S4 and S5
  may take 040 and 041; renumber at merge if they do not.
- Pure functions in `server/pure.php` with tests: `rg_csv_parse()` (BOM, CRLF, quoted line breaks,
  semicolon and tab files, Windows-1252), `rg_import_normalise_header()`,
  `rg_import_normalise_email()`, `rg_import_normalise_phone()` (restores the leading zero Google
  Sheets drops), `rg_import_map_row()`, `rg_import_match_decision()`,
  `rg_import_same_application()`, `rg_import_contact_changes()`, `rg_import_plan_row()`.
- `server/people-lib.php`: `rg_create_applicant()`, now also used by `forms-intake.php`, and
  `rg_save_contact_details()`.
- The uploaded file is never stored. Cells are held per row until apply, then only for failed
  rows, and cleared when the import is closed (`docs/RETENTION.md`).
- Apply settles each row in its own transaction with the row locked, recomputing its outcome;
  the import is claimed `FOR UPDATE` so it applies once; `applied_fingerprint` is UNIQUE, so the
  same row cannot be applied twice from any file. Re-importing a file creates nothing.
- Staff changes are limited to phone, email, address, postcode, the first emergency contact and
  availability. A column mapped to anything else is stored as "ignore".
- `server/tests/db/cases/190_sheet_import.php` (89 assertions): client, worker, trainer and editor
  logins refused; forbidden fields ignored; preview writes nothing; uncertain match not applied;
  "someone new" leaves the matched person alone; duplicates against the form feed (response id,
  and same person, role and day); idempotent re-import; a scoped manager cannot update or create
  in another service; four simultaneous applies make one application.
- Runbook: "Import from a Google Sheet" in `docs/runbooks/google-forms-intake.md`.
- Recruitment shows a source of `spreadsheet_import` as "spreadsheet import".

### Added: careers, the forms directory, company details and socials, section S7 (2026-10-09, local, not deployed)

- **Vacancies** (migration 039, `server/vacancies.php`, Website, Vacancies). Draft, published,
  closed; the address is fixed once published; checks prefilled per service from plan file 01.
  Editors and office roles, service scope on every branch including the service in the body,
  every change audited. The public read returns named fields of published roles only; a draft is
  404 and a closed role 410.
- **Careers on the website.** `/careers` lists published roles with a service filter, an empty
  state and a "register your interest" form. `/careers/:slug` shows the role, what you need, the
  checks, then one way to apply: the linked Google Form, or the short native form when the role
  has none, never both. A closed or unknown role gets a friendly page with other roles. JobPosting
  structured data with no salary.
- **Forms directory** (`website_forms`, `server/website-forms.php`, Website, Forms). Only
  `https://docs.google.com/forms/d/e/<id>/viewform` is accepted and only the id is stored;
  redirectors, other hosts, `javascript:` and pasted HTML are refused with 422. History of each
  form from the audit log. Settings, Form intake shows forms not reviewed for 90 days and each
  form's intake health.
- **Click to load.** The Google Form is not in the page until the visitor presses "Load the
  application form from Google"; "Open the form in a new tab" and the phone number are always
  shown. CSP gains `frame-src 'self' https://docs.google.com` and nothing else. Cookie notice 2.1
  and the privacy notice say so. **Deploy:** copy the CSP line in `public/.htaccess` to the server
  by hand, or the frame is blocked and the page falls back to the link.
- **Company details and social links from the CMS** (`company_settings`, `server/company.php`,
  Website, Company details). Owner only, audited, public read cached five minutes, seeded from
  `src/lib/company.ts`, which stays as the fallback. Instagram, Facebook, LinkedIn, TikTok and a
  WhatsApp enquiry link, each checked against its own host. X is not offered.
- **`/links`**, the link-in-bio page: Request staff, Arrange care, Current roles, Training,
  Contact, the phone number and the active socials.
- **Live sitemap.** `server/sitemap.php` lists published role pages and posts; `robots.txt`
  names it beside the build-time `sitemap.xml`.
- **Intake secrets.** `forms-intake.php?action=set_secret` lets an owner create an
  integration's shared secret in Settings, shown once, stored hashed. Set-up guide with the Apps
  Script: `docs/runbooks/google-forms-intake.md`.
- `is_assignable_staff()` moved from `ats.php` to `lib.php` so the Website area applies the same
  owner test.
- Tests: `server/tests/db/cases/170_careers_cms.php` (108 assertions), pure tests for form
  address parsing, slugs, vacancy states, review age and social links, and
  `src/lib/__tests__/google-forms.test.ts`, `company.test.ts`.
- **Deploy order:** run migration 039, upload `vacancies.php`, `website-forms.php`,
  `company.php`, `sitemap.php`, `forms-intake.php`, `lib.php`, `ats.php` and `pure.php`, then the
  frontend (which carries `robots.txt`), then the `.htaccess` CSP line by hand.

### Added: the rota, section S3 (2026-10-09, local, not deployed)

Launch plan Phase 4, office side. Migration 038.

- **Drafts and publishing.** Every new shift is a draft (`shifts.is_draft`); every shift that
  already existed counts as published. Workers, offers, the client portal and the field board
  never see a draft. "Publish drafts" publishes the week shown, as filtered, and tells each
  booked worker once. Cancelling needs a reason, which the worker is told. Editing, moving or
  taking somebody off a published shift tells them what changed, in the app and by email
  (`rg_notify_worker()` in the new `server/rota-lib.php`). A shift's history is its audit log.
- **One assignment check on every path.** `rg_assignment_check()` in `compliance-lib.php`:
  employment and evidence, clash and approved leave block; a period the worker marked
  unavailable and a rest gap under `app_settings.rest_gap_min_hours` (11 by default) are
  warnings the office must acknowledge, and the acknowledgement is audited. Office create,
  assign and edit, "Find eligible", broadcast, offers by name, accepting an offer by link and
  claiming in the app all use it. Rest is measured in real time in Europe/London, so a night
  across 29 March is an hour shorter than it reads and one across 25 October an hour longer.
- **Sites as records** (`server/sites.php`, a Sites tab on the client page): address, postcode,
  access notes, a site contact, open or closed, and the training the site requires. Site
  requirements join the service's rules as mandatory ones in `rg_load_rules()`; the site
  verdict is never written to `person_services`. Shifts link to `site_id`.
- **The Rota screen** replaces "Scheduling & dispatch": a week by site or by worker on desktop
  and one day at a time on a phone, conflicts written on each shift, and a side panel to edit,
  assign (showing the eligibility result and reasons first), acknowledge warnings, offer,
  cancel and read the history. "Copy last week" makes drafts and skips what is already there;
  weekly patterns generate drafts. FullCalendar is removed: only this page used it.
- **Timesheets from the clock.** Clocking out makes a draft timesheet in Europe/London time.
  The worker submits it with their break (`me.php?action=submit_timesheet`, for the staff
  portal); the office approves, queries with a reason the worker is told, or corrects with a
  reason, the values before and after kept in the entry's history. The Timesheets screen opens
  on the week and filters by service and status.
- **Requests** (`/app/requests`, `server/requests.php`): website cover requests and enquiries in
  one list with an owner. "Create shifts" turns a cover request into one or more draft shifts
  at a site. `/app/enquiries` and `/app/bookings` still work.
- `me.php` gains `timesheets`, `submit_timesheet` and `availability` for section S4, and returns
  the site's address, access notes and contact with each shift.

### Fixed in S3

- `scheduling.php` applied no service scope at all: a manager confined to security could read
  and change the care rota. The list is filtered and every record is held to the scope now, as
  are timesheets and cover-request confirmation.
- Shift template generation looked for a duplicate only among `open` shifts, so a generated
  shift that had been assigned or cancelled was generated again on the next run.
- `rg_load_rules()` described itself as memoised and never stored its result.
- `me.php` listed archived shifts in the worker's rota and open shifts.

### Changed: the public website rebuilt, section S6 (2026-10-09, local, not deployed)

The website now follows the 2026 plan (file 05) and the homepage mock.

- **New look on every public page.** Warm paper, white surfaces with 1px borders, deep green
  blocks, Source Serif 4 headings, 8px radius, no shadow outside menus and the storage notice.
  The hero gradients, engineering grids, mesh bands, floating and tilted cards, pulsing glows,
  fade-up entrances and animated counters are removed, with their CSS. Header: Healthcare,
  Cleaning, Security, Training, Careers, About, Contact, a Staff login link and one Request
  staff button (shown on phones too), with a slim phone and hours bar. Footer from
  `useCompanyDetails()`; the registered address is left out while unconfirmed; X is dropped.
- **New pages.** Home with the file 05 sections and copy. Six service pages on one pattern
  (`/healthcare`, `/healthcare/staffing`, `/healthcare/supported-living`, `/cleaning`,
  `/security`, `/training`), content in `src/lib/service-pages.ts`. `/request` with four
  focused forms. `/news` and `/news/:slug` replace `/resources`. About, Contact, Careers,
  Verify, Article, the 404 and the legal pages are restyled.
- **Enquiries reach the right queue.** Request staff goes to `bookings.php`. The cleaning quote
  goes to the new public `quotes.php?action=request`, which files a Cleaning request in the
  Bookings queue rather than a priced quote; the old `/quote` form posted to `contact.php`.
  Care, training and general enquiries go to `contact.php` with a `type`; a care enquiry is
  filed under Healthcare, a training one under Training, and the stored message opens with the
  type so the office Enquiries screen shows it. No schema change.
- **Every form**: labels, "(optional)" on optional fields, inline errors tied to their field,
  focus to the first error, input kept on failure with a plain failure message and the phone
  number, a success panel that takes focus, and a privacy notice link.
- **Old addresses redirect**, in the app and with 301 rules in `public/.htaccess`: `/services`
  to the services overview on the home page (it listed all four, so Healthcare alone would be
  wrong; `/services#care` and the other old anchors go to their own pages), the twelve
  `/services/:slug` pages to their new pages, `/sectors` and `/accreditations` to `/about`,
  `/resources` to `/news`, `/apply` to `/careers`, `/quote` to `/request?type=cleaning`.
- **SEO.** Titles and descriptions per page (the separator is now a pipe, not a dash).
  JSON-LD: `EmploymentAgency` and `LocalBusiness` on home, `Service` on service pages,
  `Article` on posts. `dist/sitemap.xml` is generated at build time from
  `src/lib/public-routes.ts`; `public/sitemap.xml` is removed. Static pages only (posts are in
  the database). `index.html` meta and Open Graph copy rewritten; `public/og.png` redrawn.
- **Healthcare and Training** replace Care and Academy in public copy, `index.html` and
  `Verify.tsx`. Stored blog categories (`Care`, `Academy`) and the certificate issuer in
  `server/certificates.php` are **not** migrated; the frontend shows them as Healthcare,
  Training and "RitaGreen Services (Training)".
- **Nothing invented is shown.** The bundled sample posts (shown whenever the API returned
  nothing) and six sample testimonials with a "5.0 from 27" summary are removed; news and
  testimonials appear only when real ones are published in the office app. Claims the plan
  does not support are gone: 100%, a guaranteed interview, insurance figures, accreditation
  and regulator alignment statements. Supported living says "talk to us", not a registration.
- **Images**: the five stock photographs stay as placeholders, listed in
  `src/lib/site-images.ts` with neutral alt text and never captioned as staff or clients.
- **Retired**: `Services`, `ServiceDetail`, `Sectors`, `Accreditations`, `Quote`,
  `RequestCover`, `Resources` and `Apply` pages, `src/lib/divisions.ts`,
  `src/lib/division-icons.tsx`, `src/components/public/marketing.tsx`,
  `src/hooks/use-count-up.ts`.
- **Tests**: `server/tests/db/cases/130_website_enquiries.php` (all three routes, refusals,
  rate limit, the staff side stays closed); pure tests for the cleaning option lists;
  `src/lib/__tests__/public-routes.test.ts` (sitemap, redirect map, 301 rules in step).

**Deploy order.** Upload `server/quotes.php`, `server/contact.php` and `server/pure.php`
before the frontend: until then the cleaning quote form would reach the staff-only quotes
endpoint and fail. Copy the new redirect block into the live `.htaccess` by hand.

### Added: people and compliance, section S2 (2026-10-09, local, not deployed)

Launch plan Phase 3's missing screens, and the person profile.

- **Person profile** at `/app/people/:personId`, read through the new `server/people.php`:
  Overview, Checks and documents, Training, Shifts, Employment, Emergency contacts and
  Activity. The header shows each service with the person's status in it and the single next
  action the server works out (`rg_person_next_action()`). A section the account's zone does
  not cover comes back as `{restricted: true}` and the tab says so. Linked from the Workers
  list, the applicant dialog, the compliance list and the verification queue.
- **Checks and documents**: every requirement for every service with a calculated chip
  (Valid, Due soon, Expired, Awaiting review, Missing), the evidence file, verifier, date
  checked and expiry. Upload evidence, verify with what was checked, reject with a reason.
- **Compliance** gains a Verification queue (oldest first, filtered by service and type of
  check), a Right to work screen (method, date, outcome, follow-up date, reviewer) and
  Onboarding lists.
- **Onboarding checklists per service** (`onboarding_template_items`, migration 037,
  `server/onboarding-templates.php`). Starting onboarding seeds from the list for the
  service; a cleaner is no longer asked for a DBS check, and a security officer is asked for
  the SIA licence.
- **Contract evidence** on the Employment tab: agreed version, sent, accepted, signed copy,
  review, new version. `employment-contracts.php` accepts the agreed version
  (`document_file_id`, and `?action=document` on a draft).
- **Approval** is offered only when `rg_approval_blockers()` is empty, which is the rule
  `workers.php?action=approve` enforces; otherwise the reasons are listed. Approval now moves
  the application to `onboarded`. **Suspension** with a reason (`?action=suspend`,
  `?action=reinstate`), recorded in `workers.suspended_at`, `suspended_by`,
  `suspension_reason`.
- **Emergency contacts**: a second contact (migration 037), validated and audited by field
  name only, through `rg_save_emergency_contacts()` in the new `server/people-lib.php`, which
  the worker portal can reuse.
- **Training records** added from the profile go to `enrolments` with `person_id`;
  `learner_name` is now nullable so the name is not copied.

### Fixed: person-level endpoints ignored service scope (2026-10-09)

`verifications.php`, `right-to-work.php`, `leave.php` and `employment-contracts.php` took a
person id and applied no service scope (contracts checked the first worker record only, and
nothing for an applicant), and the verification queue, right-to-work due list and leave lists
named everybody to every manager. `compliance.php`'s credential list, pack and eligibility
reads did the same with worker ids. They now use `require_person_scope()` or the worker's
service. Evidence files named by a verification, a right-to-work check, a contract or a
training record must be filed against the same person. Recording or verifying a right-to-work
check recalculates derived status immediately. Covered by
`server/tests/db/cases/140_person_profile.php` and `150_onboarding_and_approval.php`.

### Added: brand and shell, section S1 of the 2026 plan (2026-10-09, local, not deployed)

- **The company green replaces navy and mint.** `src/index.css` and `tailwind.config.ts` are
  rebuilt on `#33512D` with a paper canvas, ink text, status colours that are not the brand
  green (each with a soft background), an ochre highlight, one 8px radius, ink-tinted shadows
  and a green-black dark mode. Every hard-coded navy and mint hex in `src/` is gone; the rota's
  open shifts now use the warning colour, because ochre is Training's accent.
  `docs/DESIGN_SYSTEM.md` is rewritten for v3 and marks v2 retired
- **Source Serif 4 for website headlines**, self-hosted (500 and 600, latin and latin-ext, OFL).
  Website `h1`/`h2` pick it up from the `website` class on `PublicLayout`; the office app and
  the staff portal keep Source Sans 3. **Plus Jakarta Sans is removed**, files and references.
  The worker app's install skips the serif files
- **Healthcare and Training** (decision D01) replace the public labels Care and Academy in
  service names, filters, selects, badges and the sidebar. Internal ids `care` and `academy`
  are unchanged. Names and accents live in `src/lib/services.ts`. Website prose still says
  "RitaGreen Care" and "RitaGreen Academy" in places; that copy is rewritten in S6
- **Logo, favicon and PWA icons** redrawn in green; `theme-color` and the manifest colours follow
- **Eight destinations in the office sidebar**: Today, Recruitment, People, Compliance, Rota,
  Website, Reports and Settings, each a link with its secondary pages beneath, plus Finance
  only while switched on. Content Studio, Analytics, Insights and Notifications left the rail
  and stay in the ⌘K palette, which is regrouped the same way and still finds a page by its old
  name. The rules are in `src/lib/nav.ts`, tested in `src/lib/__tests__/nav.test.ts`
- **Owner feature switches** (migration 036, `server/features.php`): `finance`,
  `online_payments`, `sms_whatsapp`, `inbox` and `ai_assistant`. Any staff account can read
  them; only `super_admin` and `admin` can change one, and every change is audited. When off,
  `inbox.php` and `ai.php` refuse with 403; `payments.php` and `gocardless.php` refuse
  anything that starts a payment, while their webhooks stay open so a payment already in
  flight still settles; `send_sms()` and `send_whatsapp()` log the attempt as skipped.
  `finance` is interface only. The seeds keep production as it is, except the AI assistant,
  which starts off because the plan retires it; the header assistant and the weather panel
  are hidden while it is off. Settings has a Features tab with what each switch does and the
  plan's advice. Pure rules and tests in `server/pure.php` and `server/tests/run.php`; HTTP
  cases in `server/tests/db/cases/120_feature_switches.php`

### Fixed: the CI database job ran on the wrong engine (2026-10-09)

The `database` job used `mysql:8.0`. `schema.sql` and the migrations use MariaDB's
`ADD COLUMN IF NOT EXISTS`, which MySQL 8 rejects, so the job failed at
`schema.sql` before any case ran, on every pull request including #8. It now uses
`mariadb:10.11`, matching the host. The suite itself was green locally on MariaDB:
377 assertions across 78 groups. `docs/TESTING.md` gains a Docker toolbox for
machines without PHP or MySQL.

### Security: audit fix pass (2026-10-08, local, not deployed)

- **Server files in the document root are refused.** `public/.htaccess` now returns 403
  for `*.sql`, `*.md`, `migrations/`, `tests/` and the six CLI-only tools (`migrate.php`,
  `seed.php`, `backfill-people.php`, `backfill-payroll.php`, `create-admin.php`,
  `report-activation.php`). The live `.htaccess` is hand-maintained, so these lines are
  not on the server until copied there; `server/README.md` says how
- **Cron keys can travel in a header.** `expiry-alerts.php`, `send-queued-mail.php`
  `backup.php` and `retention-sweep.php` read `ALERTS_KEY` from an `X-Cron-Key` header first, which keeps it
  out of the access log, and still accept `?key=` so existing crontabs keep working.
  The rule is `rg_cron_key()` in `pure.php`, with six assertions in `server/tests/run.php`
- **`contact.php` caps email at 190 and phone at 40**, matching its columns and
  `bookings.php`, and the Contact and Quote forms carry the same `maxLength` caps
- **`server/README.md`'s `api.php` row and smoke test** now reflect that `api.php`
  needs a token

### Security — a rejected requirement read as verified (2026-09-14)

`rg_load_evidence()` builds one picture of a person from four sources that disagree, and what
it concludes decides who may work. Three faults, each reproduced in
`server/tests/db/cases/100_evidence_precedence.php` before being fixed.

- **A newer rejection was overwritten by an older success.** The newest-wins test read
  `$evidence[$key]['from_verification']` — but a rejection *unsets* the key, so the test was
  false for the next row, and the next row is an older verification for the same requirement.
  Somebody rejected today, having been verified last year, read as **verified**. A `$decided` map
  now records that a reviewer has ruled, separately from the entry a rejection removes: "there is
  nothing here" and "somebody looked and said no" are not the same fact
- **A certificate could revive a rejection.** The certifications pass runs after the verifications
  pass, matches on a lower-cased free-text label, and overwrote whatever it found. A rejected
  requirement had been removed from the picture by then, so a certificate carrying the same words
  put it straight back
- **A certificate could silently re-date a reviewed expiry.** The reviewed decision carries the
  date somebody checked; a certificate row with a later one quietly extended it
- References and the contract file are treated the same way: a reviewer's ruling stands over the
  rows themselves

### Security — the deployment gate weighed credentials and nothing else (2026-09-14)

`rg_deployment_check()` is the single chokepoint every assignment path goes through. It assessed
compliance evidence only, so a worker who was **suspended, had left, was half way through
onboarding, or had never been approved for work** passed it as long as their DBS and licence were
in date.

- `rg_employment_gate()` in `pure.php` runs first, in order: archived, left, suspended, inactive,
  onboarding outstanding, not approved. A leaver is not a compliance problem and is no longer
  reported as one
- The verdict now carries `blocked_by` — `employment` or `evidence` — because the two need
  different people to fix them
- **`workers.status` could not hold `'suspended'`.** It is `ENUM('active','onboarding','inactive')`,
  and `rg_derive_worker_status()` has branched on `'suspended'` since migration 016 — a branch the
  column made unreachable. Suspending somebody was not a thing the system could be told, and the
  nearest available answer, `inactive`, means something else. Migration 035 adds the value, with
  no backfill: reclassifying existing inactive workers would invent a decision nobody made

### Added — activation is somebody's decision (migration 034)

- **`workers.approved_at` / `approved_by` / `approval_note`.** Every box being ticked is not the
  same fact as an approval, and only the second one is an approval. `workers.php?action=approve`
  requires onboarding finished, a signed contract that has been **reviewed**, and refuses a
  second approval under concurrency by pinning `approved_at IS NULL` in the UPDATE
- **`?action=unapprove` requires a reason.** Withdrawing approval stops somebody working
- The migration **backfills every already-active worker**, dated from their record's creation with
  no approver named and a note saying so. Enforcing a new requirement against an existing
  workforce would have marked every current worker unapproved the moment it shipped — a policy
  change that stands the company down overnight. `server/report-activation.php` lists who is
  blocked and why, changes nothing, and is meant to be run before and after
- **`server/employment-contracts.php` is new.** The table has existed since migration 010 with no
  endpoint at all. Draft → sent → accepted → signed → reviewed, with transitions enforced;
  superseding keeps the old row with its signature and review intact. It is **not** the Contracts
  screen (`server/contracts.php` is commercial agreements with clients) and it is **not** a
  document designer or a signature service: the agreed version is uploaded, the signed copy is
  uploaded, and what this owns is the record. A signed copy must be a file that exists and is
  filed against that person — another person's document is refused
- `employment_contracts.reviewed_at` / `reviewed_by` / `review_note`. A signature is evidence the
  worker agreed; a review is evidence the company did

### Fixed — status was recalculated once a day and no sooner (2026-09-14)

- `rg_derive_worker_status()` was called from exactly one place: the nightly `expiry-alerts.php`
  run. A worker approved at 10am read as "pre-employment" on every screen until the following
  morning, and a credential rejected at noon left a dashboard saying "active" all afternoon.
  `rg_refresh_worker_status()` recalculates on the spot, and verifying, rejecting, approving and
  unapproving all call it. The nightly sweep still runs

### Fixed — the test harness hid its own SQL errors (2026-09-14)

- The database-backed cases arrange rows through `$db`, and that connection had
  `MYSQLI_REPORT_OFF`. A mistyped column name inserted nothing, silently, and the assertion that
  followed measured a database that had never been set up — a test reporting on nothing while
  looking like it passed. Found exactly that way: an INSERT naming a `note` column the table does
  not have vanished without a word. It throws now

### Added — recruitment records a recruiter actually keeps (2026-09-14)

The pipeline had a stage and a list of label/done tasks, and nothing else. Migration 033 adds a
named recruiter, interview records, task owners and due dates, stage history and a reason on the
way out. Fixed list, not a workflow designer.

- **Stage transitions are enforced.** Every stage was freely selectable from a dropdown, so a
  candidate could go from `applied` to `onboarded` in one click with no worker record, no
  checklist, no verification and no approval behind it. `rg_stage_transition()` in `pure.php`:
  forward one stage at a time; backward with a reason; out to `rejected`/`withdrawn` with a
  reason; back in with a reason; and `onboarding`/`onboarded` not settable by hand at all — they
  are what starting onboarding and approving activation *do*. 28 unit tests
- **`withdrawn` is its own exit.** A candidate who took another job is not one the company turned
  down, and recording both as `rejected` loses the difference
- **Stage history**, with the reason and who made the change. `audit_log` had it, and is the wrong
  place to read it from: a recruiter looking at one candidate should not be handed a tool that
  pages through everybody's
- **Interviews**: date, interviewer, location, outcome, notes, and who decided. Recording an
  outcome deliberately does **not** move the candidate — a passed interview is evidence, not the
  only thing that has to be true before vetting
- **Tasks have an owner and a due date**, and record who ticked one and when. Un-ticking clears
  both: "Right to work verified" with nobody's name against it is not evidence
- An owner, recruiter or interviewer must be internal staff by `is_staff()`. A client portal login
  and a worker's login are ordinary user rows, and either would have been shown — or emailed
  about — a record they cannot open. `users.php?action=assignable` feeds the dropdown from the
  same test, so it cannot offer somebody the write would refuse
- The candidate screen was rebuilt around all of this. The stage dropdown in the list is now a
  badge; moving a candidate happens in the detail, where the server says which moves are open and
  a reason can be given

### Fixed — retrying a form submission created a second candidate (2026-09-14)

- **`process_submission()` re-ran the mapping on whatever it was given**, so pressing retry on a
  submission that had already produced a candidate produced a second candidate for the same
  person, a second notification email, and a submission row repointed at the newer of the two.
  Retry is for a submission that *failed*. The guard is now inside the mapping rather than in the
  branch, so a future caller cannot reintroduce it
- **The webhook's duplicate check and its insert are two statements.** Two deliveries of one
  response — what a retrying Apps Script produces — could both find nothing and both insert, and
  the second got a duplicate-key **500**, which Google would then retry again. `uniq_external` was
  doing its job; the refusal is now caught and answered as the duplicate it is
- A candidate arriving through a form now gets the same first stage-history row as one who used
  the careers page

### Added — the outgoing email queue is visible and recoverable (2026-09-14)

- **`queue_mail()` has written to `email_queue` since migration 008 and nothing read it back.** A
  message that failed five times, or that was never sent because mail is switched off, left no
  trace anybody would see, so "we emailed them" and "nobody emailed them" looked identical from
  inside the application. `comms.php?action=queue` and a table on the Communication page
- **Mail being switched off is stated, not inferred.** With `MAIL_ENABLED` false the drainer
  records every message `cancelled`. That is *not sent* — a different fact from a delivery
  failure, and it must not be shown as one
- **`?action=requeue`** puts a `failed` or `cancelled` message back and resets `attempts` to 0,
  because the drainer refuses anything at the limit and a retry that cannot be attempted is not a
  retry. A message still `queued` or `sending` is refused: re-queueing a live message is how one
  person gets the same email twice. Audited
- Office roles only. Recipients and subjects are correspondence with named people

### Added — form intake has a screen (2026-09-14)

- `forms-intake.php` has served integration health, submissions and retry since migration 011 and
  **nothing in `src/` called any of it**. A form that silently stopped feeding the system looked
  exactly like a quiet week; a submission that failed to map was a person who applied and then
  vanished. Both are now on a tab in Settings, with the failure reason, the kept payload and a
  retry button
- A form with no shared secret refuses every submission, which is also indistinguishable from
  nobody applying. It is now labelled

### Fixed — a dialog with no title (2026-09-14)

- The candidate dialog rendered its header inside the `data &&` branch, so for as long as the
  fetch was in flight it had no `DialogTitle` and no description — which Radix reports as an
  error and which reaches a screen reader as an unnamed dialog. Found in the browser, not by a
  test

### Security — service scope was applied to lists and nothing else (2026-09-14)

`service_scope()` confines an account to one service, opt-in per account through
`users.scope_to_division` (migration 015). Every seeded account had it off, so no fixture
exercised it and no test could. `server/seed.php` now seeds `scoped@local.invalid`, a manager
for security, and `server/tests/db/cases/030_service_scope.php` is the reproduction.

Reproduced as that account, against `care` records it should never reach. Before the fix it
could:

- **read a candidate in full** by id — name, email, phone, notes — because the ATS list query
  was scoped and the detail read was not
- **reject them**, by posting a stage change
- **add onboarding tasks** to their checklist
- **complete a task** on it, through `task_done`, which takes a task id and so was not covered
  even by the branches that did look at an applicant id
- **convert them into a worker**, allocating them a payroll number
- and on `workers.php`: change another service's worker's availability, set their **pay rate**
  and write a rate-history row, and create a worker in a service it cannot see

`require_service_scope()` in `lib.php` now sits on every branch of `ats.php` and `workers.php`
that reads or writes one record, including the two that take the service from the request body
rather than from a row. The predicate is `rg_in_service_scope()` in `pure.php`, tested there: a
record with no service is **out** of scope for a scoped account, because treating "unknown" as
"yours" is how a scope stops meaning anything.

### Fixed — one applicant, four workers (2026-09-14)

`ats.php?action=onboard` is five writes — allocate a payroll number, insert the worker, link the
person to the service, mark the application, seed the checklist — and it had no transaction and
no lock on the row it checked.

- **Four simultaneous requests produced four workers**, each with its own payroll number, and
  seeded the checklist four times over. The "already a worker" test was a plain `SELECT`, so
  every request read `worker_id` as NULL and every request went on to insert. The whole branch
  is now one transaction with the applicant held `FOR UPDATE`: one request creates the worker,
  the other three get 409
- This was invisible until the test harness could serve requests concurrently. `php -S` handles
  one at a time unless `PHP_CLI_SERVER_WORKERS` is set, so the first version of the concurrency
  test passed against the unfixed code
- A failure part way through used to leave the halves that had succeeded — a worker holding a
  payroll number that no application pointed at. It now rolls back

### Fixed — a repeat application could return a 500 (2026-09-14)

- `find_or_create_person()` does a lookup and then an insert, and `people.match_key` is UNIQUE
  (migration 001). Two requests for the same person could both find nothing and both insert, and
  the second got `Duplicate entry 'e:...' for key 'uniq_people_match'` as a **500**. Reproduced
  with four simultaneous applications from one address: two runs in three failed. The duplicate
  is now caught and the person the other request created is read back
- The unique key was doing its job throughout. What was missing was the handling of its refusal

### Fixed — starting onboarding is not finishing it (2026-09-14)

- The conversion set the application's stage to `onboarded` while creating a **pending** worker
  and a checklist of **unfinished** tasks. Migration 031 adds `onboarding` between `offer` and
  `onboarded`; the conversion writes that, and `onboarded` becomes the completed state that the
  activation step will reach. Existing rows are reclassified by whether their checklist is
  finished, not in bulk

### Fixed — payroll numbers (2026-09-14)

- **`counters.payroll` is seeded by migration 032** to the highest number already in use. On a
  database whose payroll numbers predate the counter — or one restored from a dump without the
  counters table — `next_seq()` would have started at 1 and the next conversion would have died
  on the unique key with a 500 that explained nothing
- **`server/backfill-payroll.php`** allocates a number to workers that have none: report by
  default, `--go` to write, safe to run twice, refuses to start if the counter is behind a
  number already in use. It never renumbers anybody — the old number is already on payslips
- **`server/seed.php` allocates through `next_payroll_no()`**, as both application paths do. It
  did not, so all ten seeded workers had NULL and no test could tell an unnumbered fixture from
  a broken allocator

### Fixed — a fresh install did not work (2026-09-14)

Found by building a database from `server/schema.sql` and every migration, which nothing had
done since the schema and the migrations started being maintained side by side. All three
faults are in `schema.sql`; none of them affects a database that already exists.

- **`schema.sql` altered a table it never creates.** `ALTER TABLE integration_submissions ADD
  COLUMN ... deleted_at` sat in the privacy build pass, and `integration_submissions` is created
  by migration 011. A new database therefore failed on the third statement of the file, before
  a single table existed. Migration 020 already adds that column and keeps it
- **`schema.sql` repeated an index the migrations own.** `idx_consent_email_kind` was added here
  with `IF NOT EXISTS` and by migration 020 without it, so migration 020 stopped on "Duplicate
  key name" — leaving **eleven migrations, 020 to 030, unapplied** on any new database. That is
  the privacy rights tables, the pay rate history, the bank holidays, the notification receipts,
  the panic acknowledgements, the attendance retention and the break deduction
- **`schema.sql` repeated three foreign keys the migrations own** — `fk_shifts_template` (027),
  `fk_ts_shift` (029) and `fk_jobs_worker` (030). MySQL has no `ADD CONSTRAINT IF NOT EXISTS`,
  so each one stopped its migration the same way
- The rule now stated in the file: a statement belongs in `schema.sql` only if the table it
  names is created in `schema.sql`

### Added — database-backed HTTP tests (2026-09-14)

`server/tests/run.php` says in its own header that anything needing a database is out of its
scope. That left authorisation, service scope, state transitions and persistence covered by
nothing, while `docs/` described behaviour no test exercised.

- **`npm run test:db`** builds `ritagreen_os_test` from `schema.sql` and every migration, seeds
  it, serves `server/` over HTTP and makes real requests as each seeded account. 85 assertions
  across authentication and the permission matrix. Documented in `docs/TESTING.md`
- It applies the migrations twice and asserts the second run changes nothing, and runs
  `backfill-people.php --go` twice and asserts no person is created — both documented as safe
  and neither previously tested
- **`RG_CONFIG`** in `server/lib.php` lets the harness load its own configuration instead of
  `server/config.php`, so a test run cannot reach a developer's working database and cannot
  reach production. It is refused unless the file it names declares `RG_ENV` as `'local'`, and
  the harness refuses any database name that is not `ritagreen_os_test`
- Every outbound integration in the test configuration is off. Nothing in a run can send an
  email, a text message, a payment or an AI request
- CI gains a `database` job, on MySQL 8, with no `config.php` present

### Fixed — deployment could promote a commit nothing had tested (2026-09-14)

- **`deploy.yml` triggered on `push: branches: [main]`, exactly as `ci.yml` does.** Two
  workflows on one event start together; they do not form a chain. The FTP upload finished
  whether or not the typecheck, the tests, the build assertions or the source-map check had
  passed. A red CI run and a deployed commit were compatible states
- Deployment now triggers on `workflow_run` when CI **completes**, runs only when the conclusion
  is success, and checks out `head_sha` — the commit CI tested, not whatever `main` points at by
  then. It repeats the build-output assertions before uploading, and records the commit and the
  CI run it is promoting
- The gate also requires `workflow_run.event == 'push'` and that the head repository is this
  repository. CI runs on `pull_request` too, including from forks: without those two conditions
  a fork branch named `main` would have passed a conclusion-and-branch check, and this job would
  have run `npm ci` and `npm run build` on that branch's code with the FTP credentials in the
  environment

### Security — authorisation on reads (2026-09-09)

Found by probing all 54 endpoints with a token for each of the seven roles, before and after,
against a local database with the seeded fixtures.

- **Twenty endpoints role-gated their POST branches and left every GET on `require_auth()`
  alone.** Reproduced with the seeded client portal login: it could read the whole client base
  with per-client financials (`clients.php`), every applicant's name, email and phone
  (`ats.php`), the entire workforce's compliance state (`compliance.php`), the full rota with
  worker names and client sites (`scheduling.php`), and other clients' cover requests
  (`bookings.php`). A worker's own login had the same reach. `src/lib/access.ts` said it mirrored
  these `require_role()` calls; for reads there were none to mirror. The guard now sits
  immediately after `require_auth()` in each file, above every branch
- **`gocardless.php?action=status`** said "staff only below" and enforced nothing, so any
  logged-in account could walk `client_id` and read every Direct Debit mandate
- **`right-to-work.php` and `verifications.php`** took `require_staff()` but no zone check, so
  the `editor` role — which holds `compliance.can_view = 0` — could read DBS and right-to-work
  evidence. Both now take the zone, as `compliance.php`'s audit pack already did
- **`weather.php`** said "logged-in staff only" in a comment and enforced nothing
- `server/tests/guards.php` locks the invariant: no endpoint may return anything between
  `require_auth()` and its first authorisation call. Endpoints that genuinely serve any
  authenticated account are listed there with the reason, and each scopes its query to the
  caller. Negative-tested by removing the `clients.php` guard, which fails the suite
- Endpoints returning 200 to a client or worker login: **18 → 0**

### Fixed — mutations that reported success for nothing (2026-09-09)

Every `?action=` handler probed with an id of 999999.

- **Twenty-five returned `{"ok": true}`** for a record that does not exist, and wrote an audit
  entry against the id. `jobs.php` alone confirmed existence first, and is the pattern the rest
  now follow through `row_exists()`. Repeating an action already applied still returns 200:
  `affected_rows` is 0 both for a missing row and an unchanged value, so existence is checked
  separately. Deleting an already-deleted record is not an error; deleting one that never
  existed is
- **Eight let a parent id reach the INSERT** and came back as a foreign-key 500 with the
  constraint name in it, plus a logged exception, for what is only a stale id from an open page.
  In `quotes.php` and `incidents.php` the check now runs *before* `next_seq()`, so a rejected
  request no longer burns a quote or incident number on a record that was never written
- **`invoices.php?action=pay`** reported success for a missing invoice, would mark an invoice a
  credit note had voided as paid, and left `amount_paid_pence` at zero while Stripe and
  GoCardless both set it. Now 404, 409 and an idempotent 200, and it sets the column

### Fixed — the CLI tools could not tell production from a laptop (2026-09-10)

The footgun recorded in the 2026-09-03 deployment notes, and worse than it looked.

- `migrate.php`, `seed.php` and `backfill-people.php` decided "local" from `DB_HOST`. On cPanel
  the production database is `localhost` too, so the live system read as local:
  `migrate.php --go` skipped `--production` and the typed-name confirmation, and
  **`seed.php --confirm --reset` would have emptied clients, workers, invoices and twenty more
  tables on the real database.** `config.php` now declares `RG_ENV`, read through
  `rg_is_local_database()`, and a config that does not declare it counts as production. Verified
  both ways: with `RG_ENV=production`, seed and backfill refuse and migrate demands
  `--production` and the database name typed in full
- **`backfill-people.php --go` was not safe to run twice.** It inserted a fresh person for every
  group regardless of existing links, repointed every record at the duplicate and orphaned the
  original — along with the right-to-work checks, verifications, eligibility and leave records
  still hanging off it. Both the quickstart and the production runbook run it after a seed, by
  which time everything is already linked. It now leaves linked rows alone and gives an unlinked
  row its group's existing person; a group pointing at two different people is reported, never
  merged. Verified: `--go` on a linked database creates nothing, and an artificially unlinked
  login rejoins its worker's person rather than making a second
- **`seed.php` could not run at all** against a database with migration 004 applied, which is
  every developer's: it inserted users, workers and applicants with no `person_id` while 004
  makes the column `NOT NULL`. The documented setup path, broken. Every identity now goes
  through `find_or_create_person()`, and a worker's login shares the worker's person. It also
  wrote the incident ref `INC-<year>-0001` by hand and left the counter at zero, so the first
  incident anybody filed on a fresh database collided on the unique index; and `--reset` never
  cleared `people`, so the spine grew on every reset

### Changed — the interface stops offering what the server refuses (2026-09-09)

- The Command Centre's live panels read three office-only endpoints and rendered each 403 as an
  empty state, so "not yours to see" read as "nothing to do" for every trainer, editor and
  employee
- The sidebar polled office-only `inbox.php` every 60 seconds for accounts that cannot open it
- Settings appeared in the app footer and account menu for roles bounced off it; Compliance
  offered Insurance and References tabs that led straight to an error block; Workers, Timesheets,
  Academy and Jobs offered create and approve actions ending in 403
- **Nothing behind the login set a document title.** `/app`, `/portal` and `/m` all kept
  whichever `<title>` the last public page left behind, so the browser tab, the history entry and
  a screen reader's page announcement read as the marketing home page
- **`/verify` told a visitor whose connection had dropped that their certificate did not
  exist** — a transport failure and a genuine 404 set the same state. Only a 404 says that now
- **`/login` rendered the sign-in form to somebody already signed in**, and its button issued a
  second session for the same person

### Changed — ESLint at zero, and blocking (2026-09-09)

From 33 errors to none, by changing the code rather than the rules. CI runs
`eslint --max-warnings 0` and blocks.

- `src/components/calendar` was an unreferenced FullCalendar template. It read a ref during
  render, so its own toolbar title never updated, and its dialog reset form state from an effect.
  Deleted
- Scheduling's loader set state synchronously from an effect body and had no cancellation, so
  paging the calendar quickly could let an earlier response land after a later one and paint the
  wrong week
- Verify held three states, two reset synchronously in an effect; one value stamped with its id
  replaces them, and a previous certificate can no longer show while the next is fetched
- The auth provider started `loading` at true and set it false in the effect; the theme toggle's
  `mounted` flag is next-themes' `resolvedTheme`; icons render through `createElement`;
  `homeFor()`, `openStorageNotice()` and `PageLoader` moved out of the modules that mixed them
  with components; the vendored sidebar skeleton derives its width from `useId()` rather than
  `Math.random()`, which made it twitch on every re-render

### Verified (2026-09-09)

Direct exercise against a local MariaDB with migrations 001–020 applied, not only static checks.

- Journeys end to end: public application → ATS → onboard → worker on one `people` row;
  deployment gate refusing an unvetted worker and opening once evidence is filed; overlapping
  shift refused; invoice → credit note → void → pay with VAT and boundary cases; login, logout,
  token expiry, password reset, rate limiting; 2FA setup, enable, gated login, disable; data
  request → subject access export → erasure → two-person deletion approval; retention sweep dry
  run; per-file document access
- Concurrency: 24 invoices raised in parallel produced 24 unique numbers; six concurrent onboards
  of one applicant produced one 201, five 409s and exactly one `people` row
- Four-eyes on deletion confirmed with an admin requester: they cannot approve *or* reject their
  own request
- An approved hard deletion now discloses its cascade and is refused until acknowledged. Several
  foreign keys onto `workers` and `people` are `ON DELETE CASCADE`, so approving one deletion
  also removes shifts, timesheets, certifications and references — some of which have to be kept
  after the employment ends. The schema is unchanged; `decide_deletion` counts the children from
  the live foreign keys, returns 409 with the counts, and proceeds only on `confirm_cascade`

### Deployed — production (2026-09-03)

- Migration 019 applied to `devgeereact_ritagreen_os` after a fresh dump to
  `~/backups/`. Production was at 18 applied / 0 pending, so 019 was the only new
  one and it is purely additive (`CREATE TABLE IF NOT EXISTS oauth_states`)
- Eleven changed PHP files uploaded, checksum-matched byte for byte against local
  and `php -l` clean on the host. Previous copies kept in
  `~/private_backups/php-predeploy-*`
- **The rate-limit fix verified against production**: fourteen login attempts with a
  rotating `X-Forwarded-For` returned 429 from the tenth. This also confirms
  Cloudflare IP restoration is live, which is what makes `TRUSTED_PROXIES` empty the
  correct setting here
- Security headers merged into the server's `.htaccess` rather than replacing it,
  and the origin lock re-verified afterwards (direct-to-origin still 403)
- **`public/.htaccess` had drifted from the server and was a live hazard.** The
  server copy carries the Cloudflare origin lock and
  `AddType application/manifest+json .webmanifest`; the tracked copy had neither.
  Uploading the repo version would have removed the origin lock — re-exposing the
  origin to direct-to-origin traffic and bypassing the WAF — and broken PWA
  installability. Reconciled, with a note that the server copy is authoritative
- Noted for later: `migrate.php`'s production guard does not engage on this host.
  It treats `localhost` as local, and on cPanel the production database genuinely
  is on localhost, so `--production` and the typed-name confirmation never fire when
  the script is run on the server. The guard protects a developer running it from a
  laptop against a remote database; it does not protect the server-side path

### Security — production-readiness audit (2026-09-03)

- **Rate limiting could be bypassed with a header.** `client_ip()` read
  `X-Forwarded-For` / `CF-Connecting-IP` whenever they were present. Both are set by
  the caller, so rotating one per request produced a fresh bucket every time and
  defeated every `rate_limit()` call — login, password reset, the contact form, the
  public application form. Reproduced against a running server (14 login attempts with
  a rotating header: all 401, none 429), then fixed and re-verified (429 from the
  eleventh). A forwarded header is now read only when `REMOTE_ADDR` is in the new
  `TRUSTED_PROXIES` config list, which is empty by default and correct for this host
  (Cloudflare IP restoration already puts the real address in `REMOTE_ADDR`)
- **Google sign-in skipped two-factor entirely.** An account with an authenticator
  enrolled could sign in through Google with no code. It now stops at the same place a
  password sign-in does, via a short-lived ticket (`action=google_2fa`) — Google has
  already spent the authorization code by that point, so the callback cannot simply be
  replayed
- **Google sign-in had no `state` parameter**, so the callback accepted any
  authorization code handed to it — login CSRF, which signs a victim into an attacker's
  account. Single-use state added (`migrations/019_oauth_state.sql`)
- **No security headers.** `public/.htaccess` now sets CSP (`script-src 'self'`, no
  inline scripts), `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy` and HSTS. Verified by serving the real build under the real
  headers in a browser: zero violations across every public route
- **`ai.php` was open to any logged-in account** — including client-portal and worker
  logins — with no spend limit and no length cap, against a metered OpenRouter key. Now
  `require_staff()`, 40 calls/hour/account, prompt capped
- **`certificates.php` allowed bulk PII enumeration.** Public, unthrottled, and it
  returns a learner's name against a countable ID (`RG-2026-00123`). Rate-limited
- Per-account login throttle added alongside the per-IP one; the cron-key endpoints
  (`backup`, `expiry-alerts`, `send-queued-mail`) throttle their key check; password
  reset retires outstanding tokens when a new one is issued; `config.sample.php` no
  longer returns the database connection error to anonymous callers; `forms-intake.php`
  uses a prepared statement rather than `real_escape_string` (rule 4)

### Fixed — production-readiness audit (2026-09-03)

- **Website applications never reached the ATS.** The careers form posted to
  `contact.php` with the role flattened into a message prefixed
  `CAREERS APPLICATION`, so every application landed in `contact_enquiries` and
  `/app/ats` — the recruitment pipeline the CMS is built around — was fed by nothing.
  It now posts to `ats.php`: applicant, person link, role, division, the applicant's own
  words in `notes`, and a real consent record. Verified end to end through a browser
- **`/apply` and `/request` were orphans** — real, working conversion pages with no link
  from anywhere on the site, no sitemap entry and no SEO tags. Linked from the footer,
  added to the sitemap, given titles and canonicals
- **The footer newsletter form was fake.** It validated the address, said "we'll keep
  you posted" and sent nothing anywhere — there is no subscribers table, endpoint or
  unsubscribe path. Replaced with the two entry points that do complete
- **Five of the six ⌘K palette destinations did not exist** (`/analytics`, `/crm`,
  `/profile`, `/billing`, `/settings` — leftovers from the dashboard template; the sixth
  went to the marketing homepage). The palette now lists real routes, filtered through
  `canAccess()`
- **No phone number on mobile.** It lived only in the desktop utility bar, which is
  hidden below `md`. A labelled Call button is now in the mobile header and both numbers
  are in the menu
- **Clicking "privacy policy" inside a form wiped it.** Four internal `<a href>` links
  forced a full page load; now `<Link>`
- **The engagement table was clipped on mobile** — `overflow-hidden` around a 576px
  table in a 326px column, so the rates column could not be reached. Now scrollable
- `ats.php` queued the office email against `$conn->insert_id` after two further
  inserts, so `related_id` pointed at a notifications row, not the applicant
- Length caps on the public `ats.php` and `bookings.php` fields, as `contact.php`
  already had; an oversized field returned a 500 instead of a message
- `ErrorPage` rendered a raw `Error.message` to the user in production

### Fixed — PWA, offline and third-party weight (2026-09-03)

- **The app rendered a blank page on every route offline**, including the worker
  app the PWA exists for. `manualChunks` named `charts`, `calendar` and `pdf`,
  which promotes those chunks into the entry's dependency graph — the build emitted
  `<link rel="modulepreload" href="/assets/pdf-*.js">` in `index.html`, so every
  visitor to the marketing home page preloaded a 425 kB PDF library reachable only
  from four lazy CMS pages. Workbox's `globIgnores` then deliberately kept that same
  chunk out of the precache, so offline the preload never resolved, the module graph
  never settled and React never mounted. The two settings were fighting each other.
  Reproduced offline against both this build and the pre-audit build (identical
  blank page, so this was long-standing, not a regression), and re-verified after:
  home, careers, services and apply all render fully offline, and `/m` correctly
  redirects to sign-in. Only `react` stays a manual chunk, because it is genuinely
  needed immediately
- **`globIgnores` had silently stopped matching.** Its patterns named chunks that
  `manualChunks` used to create; with those gone the globs matched nothing — except
  `**/calendar-*.js`, which matched a 0.3 kB lucide icon — so ~700 kB of office code
  re-entered the worker's install unnoticed. Patterns now match the real chunk names,
  with a note to re-check them after any chunking change
- `og.png` was listed in `includeAssets`, which overrides `globIgnores`, so the 81 kB
  social-share image was precached into the worker app despite a `globIgnores` entry
  that looked like it excluded it
- **Two icon-font CDNs were render-blocking on every page.** Boxicons was used for
  nothing at all — not one `bx-*` class in the codebase. Bootstrap Icons was loaded
  for exactly one glyph, the X logo in the footer, at 14 kB of CSS plus a 131 kB
  webfont. Both removed, the icon inlined as SVG, and the CSP tightened to drop both
  origins. Verified: zero console errors under the tightened policy

### Changed — production-readiness audit (2026-09-03)

- **SEO: every page without `useSeo` claimed the homepage as its canonical.**
  `index.html` carries a static canonical, so the 404 page, sign-in, register, reset,
  `/apply`, `/request` and the Google callback all told Google they were duplicates of
  `/`. `useSeo` gained a `noindex` mode that also drops the canonical, and the pages
  that lacked it now have it
- `robots.txt` disallows `/portal`, `/m`, `/register`, `/forgot`, `/reset/`, `/auth/`
  and `/offer/` — previously only `/app/` and `/login`
- Sitemap: `lastmod` on every entry, plus `/apply` and `/request`
- **Entry bundle 206 kB → 141 kB gzipped.** All seventeen public pages were imported
  eagerly, and `AppLayout` was too — so a visitor reading the marketing homepage
  downloaded the legal pages, the certificate verifier, five forms, the CMS sidebar, the
  command palette and a QR encoder before anything rendered. Home stays eager;
  everything else is a route-level chunk
- Accessibility: skip link on the public site (the CMS had one), `role="alert"` on
  form-level errors, `scope="col"` and a caption on the engagement table, 44px touch
  targets in the mobile header, `prefers-reduced-motion` honoured by the scroll handler,
  a description on the mobile-menu dialog
- ESLint baseline 27 → 19 errors; `react-refresh/only-export-components` scoped off the
  vendored `src/components/ui` primitives only


### Added — Milestone 0, documentation pack

- `docs/PRD.md` — problem, users, outcomes and the explicit non-goals
- `docs/BLUEPRINT.md` — the target model, what is already built, and the audited gap analysis
- `docs/ARCHITECTURE.md` — the real data flow, authentication model and target identity model
- `docs/SCHEMA.md` — every table, generated from `server/schema.sql`
- `docs/API.md` — every endpoint and action, replacing a server README that documented 8 of 49
- `docs/DESIGN_SYSTEM.md` — navy and mint v2 as the single source of truth
- `docs/RULES.md` — non-negotiable engineering and product rules
- `docs/UK_GOVERNANCE.md` — the compliance framework, with policy values held as configuration
- `docs/ROADMAP.md` — milestones 0 to 9
- `docs/TRACEABILITY.md` — requirement to data to endpoint to interface to test
- `docs/STATUS.json` and generated `docs/PROGRESS.md`
- `scripts/docs_sync.py` — regenerates progress, validates traceability, `--check` for CI
- `.github/workflows/ci.yml` — typecheck, build, source-map assertion, `php -l` across every
  PHP file, a guard against committing `server/config.php`, and the documentation check. Lint
  runs but does not block: there are 33 pre-existing ESLint errors in application code, most of
  them React 19 hook rules whose fixes would change runtime behaviour. The baseline is tracked
  in `docs/STATUS.json` rather than hidden
- `typecheck` script in `package.json`

### Changed

- `README.md` rewritten to describe the system as built
- `CLAUDE.md` rewritten — it was a half-filled template describing React 18 in JavaScript and
  a single `api.php`, neither of which has been true for some time
- `public/manifest.webmanifest` theme colour corrected from a retired green to navy `#0C447C`

### Removed

- `DESIGN-SYSTEM.md` — marked "v1 (LOCKED)" while specifying a green palette and two fonts the
  application does not load. Superseded by `docs/DESIGN_SYSTEM.md`
- `RitaGreenOS.html` — a 46 KB standalone feature specification carrying a third brand palette.
  Superseded by `docs/PRD.md` and `docs/BLUEPRINT.md`

### Fixed — access control

Found during the Milestone 0 audit and fixed immediately, ahead of the rest of Milestone 5,
because `/portal` is externally facing. Each of these was reachable by **any** authenticated
account, including a client portal login.

- **`server/workers.php` GET** exposed every worker's pay rate, DBS status and expiry, and SIA
  number. The file's role checks were only on its write paths. Now staff-only, with pay fields
  returned solely to `super_admin`, `admin` and `manager`
- **`server/inbox.php`** exposed the entire shared company mailbox and allowed sending replies
  as the company. Now `super_admin`, `admin`, `manager`
- **`server/field.php` POST** allowed posting field events — clock-ins and **panic alerts** —
  against any shift id. The board is now staff-only, and a worker may post only against a shift
  assigned to them
- **`server/api.php`** was an unauthenticated, unthrottled write into the production database.
  Both verbs now require authentication; the write is throttled

Adds `is_staff()`, `require_staff()` and `linked_worker_id()` to `server/lib.php`. Role alone
could not express this boundary: a worker's login is an ordinary account with
`users.worker_id` set and may hold any role, so "internal staff" has to be decided by the
link, not the role. Privileged roles are treated as staff unconditionally, so a manager who
also holds a worker record does not lock themselves out.

### Fixed — correctness

- **`server/dashboard.php`** counted trainer-less *training sessions* as open shifts
  (`FROM sessions` rather than `FROM shifts WHERE status = 'open'`). That tile had always shown
  the wrong number. It now also respects the division filter, like its neighbours
- **`server/gocardless.php`** marked an invoice fully paid on any confirmed collection, setting
  `amount_paid_pence = total_pence` regardless of what was actually collected, so a part
  collection reported as paid in full. It now credits the amount collected, marks the invoice
  paid only once that covers the total, and reads the payment's prior status first so a
  redelivered webhook cannot credit twice. Signature verification was already sound
- **`tokens.last_seen_at`** was written once at issue and never updated, so the device list in
  Settings showed each session's creation time under a heading that reads as last activity. It
  is now refreshed per request, throttled to one write a minute per token

### Fixed — client-side access control

- **`src/lib/access.ts`** is new: one path-prefix map of route to permitted roles, plus
  `isStaff()`, `isClientAccount()` and `isWorkerAccount()`. Both `RequireAuth` and the sidebar
  read it, so a link can never be shown to someone who would be bounced off the page behind it
- `RequireAuth` accepted a `roles` prop that **no route ever passed**, so `/app`, `/portal` and
  `/m` were gated identically on "is logged in" and a client account could load `/app/payroll`.
  It now consults the map, and redirects to the user's own home rather than a dead end
- `/portal` now requires a `client_id` and `/m` a `worker_id`, because those surfaces scope
  every query to that link and would otherwise render an empty or erroring page
- The sidebar filters its groups through the same map, dropping any group left empty

### Fixed — the typecheck was not running

- **`tsconfig.json` had no `references` array**, so `tsc -b` built a root config that lacks
  `noUnusedLocals` and `noUnusedParameters`, and `tsconfig.app.json` — where those settings
  live — was orphaned. The strict configuration had never been enforced. Wiring the references
  surfaced seven genuine errors, all now fixed
- The `typecheck` script is now `tsc -b --force`, so a warm incremental cache cannot make the
  gate a silent no-op
- **`tsconfig.tsbuildinfo` was tracked in git** despite `*.tsbuildinfo` being gitignored — a
  file added before the ignore rule stays tracked. Untracked, and both projects now write their
  build info under `node_modules/.tmp`
- Of the seven errors this exposed, one was a real bug: `SidebarMenuSub` in
  `src/components/ui/sidebar.tsx` declared `React.forwardRef` and destructured `ref`, but never
  applied it, so the forwarded ref was silently dropped. The rest were unused imports and a
  dead `STATUS_VARIANT` constant left behind when job status became an editable Select

### Changed — lint baseline

Down from 33 errors and 3 warnings to 27 errors and no warnings, by removing the unused
imports the typecheck surfaced and applying the auto-fixable `prefer-const`. What remains is
8 `react-hooks/set-state-in-effect`, 7 `react-refresh/only-export-components`, 4
`react-hooks/refs`, 3 `react-hooks/static-components`, 2 `no-explicit-any`, 2
`react-hooks/purity` and 1 `no-unused-expressions` — all of which change runtime behaviour to
fix. Everything mechanical is done.

### Added — local development, and with it the unblocking of all schema work

Development read and wrote the **live production database**: `vite.config.ts` proxied every
`*.php` request to `https://ritagreenservices.com`, and there was no local alternative. That
made every migration in the roadmap unsafe to attempt.

- **`server/config.local.php.example`** — a tracked template pointing at a local database with
  every outbound integration deliberately disabled: mail, SMS, WhatsApp, Stripe, GoCardless,
  Google SSO, OpenRouter, weather. A local run must not be able to email a real worker or
  charge a real card
- **`npm run dev:api`** — `php -S 127.0.0.1:8122 -t server`, serving the PHP tier exactly as
  the host does, with no router needed
- **The Vite proxy now defaults to local.** The target comes from `RG_API_TARGET`; overriding
  it prints a warning at startup, and `npm run dev:prod-api` makes the deliberate case explicit
- **`server/seed.php`** — fixtures covering staff logins for every role, a worker login, a
  client portal login, four clients with sites, and ten workers whose compliance is deliberately
  mixed: valid, inside the 30-day window, expired, and one with no right to work. Plus
  certifications, applicants across the pipeline, assigned and open shifts, programmes,
  enrolments, enquiries and an incident. It refuses to run outside the CLI, against a non-local
  `DB_HOST`, without `--confirm`, or on a populated database without `--reset`
- The "config.php is missing" error now tells a developer to copy the local template, rather
  than telling them to upload a file to a server they are not on

**Still needed on the developer's machine:** a local MySQL or MariaDB. Everything else ships in
the repository.

### Added — tests, and the separation that makes them possible

There was not one test file in this repository. There are now two suites, both
dependency-free and both running in CI, because the production host cannot install packages
and a suite that only runs on one machine stops being run.

- **`server/pure.php`** — domain logic with no database, no config, no output and no globals.
  Every function is a function of its arguments, and every date function takes an injectable
  clock so a test can pin time rather than hoping the suite never runs at midnight. It holds
  credential expiry classification and reminder banding, the staff/worker/client distinction,
  and field status derivation. Three of those were **duplicated definitions inside separate
  endpoint files** — `classify()` in `compliance.php`, `tier_for()` in `expiry-alerts.php` and
  `field_status()` in `field.php` — which is exactly how two places drift on what "expired"
  means. They now share one implementation
- **`server/tests/run.php`** — 74 assertions, a few dozen lines of assertion helper, no
  PHPUnit. Covers the boundary days specifically: a credential expiring *today* is expiring,
  not expired, and an off-by-one there stands someone down a day early every time. Also that
  `unknown` stays distinct from `expired`, since a missing date is a gap in the evidence rather
  than a lapsed credential
- **`src/lib/__tests__/access.test.ts`** — 24 tests over the permission map, including that a
  client or worker account cannot reach `/app` by any route, and that `/app` as the shortest
  prefix never shadows a more specific rule
- `npm test`, `npm run test:watch`, `npm run test:php`; both wired into CI

### Changed — the documentation checker consults git

`scripts/docs_sync.py` flagged `server/config.php` as a missing file. It is missing by design —
it holds the live credentials and is gitignored — so the checker now asks
`git check-ignore` before reporting, rather than carrying a hand-maintained allowlist that
would itself go stale. Verified both ways: a genuinely absent path still fails the check.

### Added — the compliance engine's decision logic

The question the whole system exists to answer — *may this person work in this service, and if
not, why not?* — is now a tested pure function, built ahead of its storage precisely because
being pure makes it verifiable without a database.

`rg_evaluate_eligibility()` in `server/pure.php` takes a rule set and a person's evidence and
returns a verdict, a score, every blocking reason and every warning. The behaviour it encodes:

- **A mandatory requirement that is unmet blocks**, and the refusal names it. Every blocker is
  reported, not just the first — fixing one at a time, with a fresh refusal on each attempt, is
  how an office loses a day
- **A mandatory credential inside its expiry window warns without blocking.** The person is
  still valid today, and standing them down early is its own failure
- **A recommended requirement never blocks**, and an optional one neither blocks nor warns
- **Missing, expired, undated and unverified are four different states.** No evidence on file
  is a gap; a lapsed credential is a lapse; a credential that should carry an expiry but does
  not is incomplete evidence, not valid evidence; and an unverified document is a claim
- **An empty rule set scores 0, not 100.** "Nothing configured yet" must never read as "fully
  compliant" on a dashboard
- **A refusal always carries a readable reason** — "SIA licence: expired 31 days ago", not a
  status code

48 assertions cover it, including the boundary days where an off-by-one becomes a person
wrongly stood down or wrongly deployed. One of them caught an arithmetic error in the test's
own expected score, which is the point of writing them.

Storage for the rules, and wiring the evaluator into the endpoints, remain — see
`docs/ROADMAP.md` Milestone 2.

### Fixed — exception messages no longer leak

`server/lib.php` returned `$e->getMessage()` to whoever made the request, regardless of
`GK_DEBUG`. Any input that provoked a fatal handed the caller table names, file paths and query
fragments. The full detail now goes to `error_log` with a short reference; the caller gets the
reference alone, so a user can quote it in a support request without ever having seen the
underlying error. `GK_DEBUG` still returns the detail locally.

### Added — the person spine, applied and verified locally

> Applied to a real MariaDB and verified end to end — schema, migrations, fixtures, backfill,
> and the API exercised against the result. **Not yet applied to production.**

Running it found three things that reading it did not:

1. **A foreign key does not force a row to be linked.** Migration 002 was written on the
   assumption that it would block on an unresolved match. It does not — a foreign key
   constrains non-NULL values only, and 002 applied cleanly with an unlinked row present. The
   claim was corrected in the migration, `ARCHITECTURE.md` and `SCHEMA.md`, and migration 004
   added: `person_id NOT NULL` is what actually requires the link.
2. **The backfill ignored links the database already recorded.** `users.worker_id` and
   `applicants.worker_id` are facts somebody entered, and matching on email and phone alone
   split a worker from their own login. `rg_group_people()` now unions explicit links with
   matched keys, which also handles the transitive case where a record joins one group by its
   link and another by its email.
3. **004 depended on the server's `sql_mode`.** Without `STRICT_TRANS_TABLES` MySQL coerces an
   unlinked `person_id` to 0 rather than failing, leaving the spine quietly wrong instead of
   loudly unfinished. It now sets `STRICT_ALL_TABLES` for its own session.

Verified: 22 people from 23 source records with no duplicates; the UNIQUE `match_key` refuses a
second person with the same identity (error 1062); 004's precondition blocks on an unlinked row
and reports the count; the API answers correctly against the migrated database, with all four
access restrictions returning 403 and 401 as intended.

- **`server/migrate.php`** — a migration runner. `server/schema.sql` stays the from-scratch
  baseline; everything after it is a numbered file applied in order and recorded with a
  checksum. Dry run is the default; applying takes `--go`; a non-local database additionally
  takes `--production` and a typed database name, because MySQL DDL is not transactional and a
  migration that fails halfway leaves the schema halfway. An applied migration that has since
  been edited is reported as drift, because re-running it would not reconcile anything
- **`migrations/001_people_spine.sql`** — `people`, `person_services`, nullable `person_id` on
  `workers`/`applicants`/`users`/`enrolments`, and `workers.payroll_no`. It only *adds*: no
  column is dropped or renamed, so the application keeps working unchanged while the endpoints
  move over one at a time. `people.match_key` is UNIQUE, so the database itself refuses a second
  person with the same identity — the guarantee the old copy-on-onboard behaviour could not make
- **`migrations/002_people_foreign_keys.sql`** — split from 001 on purpose. A foreign key fails
  if any row holds an unresolved value, so an ambiguous match left unresolved *blocks* this
  migration rather than being silently guessed at. `ON DELETE RESTRICT`, because removing a
  person goes through the deletion-approval workflow, never a cascade that takes their
  employment history with it
- **`migrations/003_compliance_rules.sql`** — storage for the engine already built: 13 seeded
  requirements and the rules per service, seeded to reproduce exactly what `scheduling.php`
  enforces in code today, so wiring the engine in changes no behaviour on day one. Everything
  beyond that starts as `recommended`, so the business raises a requirement to mandatory
  deliberately rather than discovering overnight that half the workforce is blocked
- **`server/backfill-people.php`** — dry run by default. Reports what will be created, what
  will collapse into one person, what has no email or phone, and what is ambiguous

### Added — person matching, and its refusals

The matching logic is pure and covered by 34 assertions, which is what makes an otherwise
unverifiable migration defensible:

- **Email first, then phone. Never a name.** Matching on names would collapse every John Smith
  into one worker
- **Synthetic addresses are not identities.** `ats.php` fabricated
  `<name>@learner.invalid` for learners with no email; matching on those would collide every
  such learner into one person
- **`07700 900123`, `+447700900123` and `00447700900123` are one key.** Anything under seven
  digits is an extension, not an identity
- **A group whose names disagree is not merged.** A shared landline is not proof of one person,
  so it is reported for a human to resolve. A wrong merge joins two employment histories, which
  is far harder to undo than an unmerged duplicate
- Records with neither email nor phone each become their own person — that can leave a
  duplicate, which is the safe direction to be wrong in

### Fixed — the identity defect itself

`ats.php` no longer copies. `find_or_create_person()` in `server/lib.php` now sits in front of
**every** path that can create a human record — the public application, self-registration, the
Academy learner handoff, direct worker creation and user creation — matching on the same rules
as the backfill. A login linked to a worker inherits that worker's person rather than resolving
its own, so the login and the employment record can never describe two different people.

`onboard` reads identity back from `people` instead of copying it off the applicant, and
assigns a payroll number: `RG-000001`, from `next_seq()`, unique and never reused. It also
records the person as intended for that service in `person_services`.

Verified end to end against a real database. Two applications for one human (the second with
the phone number written differently), then an onboarding, then a login creation:

```
person  full_name     match_key                        apps  workers  logins  payroll
23      Jordan Blake  e:jordan.blake@example.com       2     1        1       RG-000001
```

**One person.** Before this, that sequence produced three or four separate identities.

A second employment record created for the same person attaches to the same identity, which is
the multi-service capability `workers.division` could never express: one human, two roles, one
record of who they are.

### Fixed — failed queries no longer pass silently

`server/lib.php` set `mysqli_report(MYSQLI_REPORT_OFF)`, and almost no call site checks the
return value of `execute()`. A failed INSERT was therefore ignored and the endpoint went on to
report success — `workers.php` returned HTTP 200 with a complete worker object, `id: 0`, for a
row that was never written. Found because the new NOT NULL constraint made an insert fail.

Reporting is now `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`, set *after* the connection is
established so `config.php` keeps its own readable connection-failure message. Anything thrown
reaches the exception handler, which logs the detail and returns a reference. That single
change protects every endpoint, not just the ones touched here.

### Added — the compliance engine, wired to real data

`server/compliance-lib.php` is the boundary between the tested decision logic and the database:
`rg_load_rules()` reads the configured rule set for a service and role, `rg_load_evidence()`
gathers what is on file for a person, `rg_assess_person()` evaluates and records the verdict in
`person_services`, and `rg_deployment_check()` is the single gate every assignment path will
call. Exposed as `compliance.php?action=eligibility` and `?action=eligibility_all`.

Verified against real data, and it is the blueprint's central claim made concrete:

```
Esi Mensah
   care      ELIGIBLE      71%
   cleaning  ELIGIBLE      89%
   security  NOT ELIGIBLE  75%  <- SIA licence: expired 3 days ago

Grace Okafor
   care      NOT ELIGIBLE  57%  <- DBS check: no evidence on file
   cleaning  ELIGIBLE     100%
   security  NOT ELIGIBLE  67%  <- SIA licence: no evidence on file
```

One person, a different answer per service, and one lapsed credential blocking exactly one
service. That could not be expressed at all while `workers.division` was a single ENUM.

### Changed — right to work is provisionally non-expiring

Migration 005. The catalogue declared `right_to_work` as expiring, which is correct, but the
evidence behind it is still `workers.right_to_work` — a `TINYINT(1)` with no date. The engine
therefore classified every worker who *has* right to work as `undated`, meaning incomplete
evidence, and blocked them from every service.

That was the engine being right about a model that is not ready. The requirement is marked
non-expiring until Milestone 3 replaces the boolean with a check record carrying a method, a
date, a verifier and an expiry. It is a one-row `UPDATE` rather than a code change, which is
the whole reason rules live in the database.

### Added — the deployment gate

`server/scheduling.php` no longer carries its own hard-coded DBS-for-care and SIA-for-security
pair. It calls `rg_deployment_check()`, which evaluates the rule set configured for the service
and refuses the assignment when anything mandatory is unmet.

The refusal names **every** blocking requirement at once, rather than surfacing them one 409 at
a time, and a warning does not block — a credential inside its expiry window is still valid
today, and standing someone down early is its own failure. A blocked attempt writes a
`shift.blocked` audit row with the reason, so what is actually preventing deployments becomes
visible rather than merely felt.

Verified:

```
POST scheduling.php  worker: Esi Mensah, security shift
409  Esi Mensah cannot be deployed. SIA licence: expired 3 days ago
     blocking: [ SIA licence — expired 3 days ago ]

POST scheduling.php  worker: Dmitri Volkov, same shift
201  shift created
```

### Added — right to work becomes evidence

It was `workers.right_to_work TINYINT(1)`: no method, no date, no verifier, no evidence, no
expiry, no audit trail. The one field that most needed a trail had none, and it was the weakest
point in the system in legal terms.

- **Migration 006** adds `right_to_work_checks` — method, method note, reference, check date,
  outcome, follow-up flag, recheck date, evidence file, verifier, verified-at, notes. The old
  boolean is kept and synchronised, so nothing that still reads it regresses
- **`server/right-to-work.php`** records a check, lists a person's history, reports what needs
  attention, and verifies a check. **Recording and verifying are separate actions**, because
  the person who collects a document is often not the person who signs it off, and "verified"
  with nobody's name against it is not verification. A check whose outcome is still `pending`
  cannot be verified — signing that off would be signing off nothing
- The engine now reads the check record rather than the boolean: the most recent check wins,
  its `recheck_due` is the expiry, and `verified` reflects whether a named person signed it

**The legacy flag was carried over honestly.** Migration 006 imports each existing
`right_to_work = 1` as an *unverified* historical assertion, with a note recording that no
method, verifier or date was captured at the time. Presenting it as a completed check would
have been inventing evidence.

**Migration 007 stages the consequence.** With verification enforced, every migrated check is
unverified, so the engine correctly blocks the entire workforce. Correct, and unusable. The
requirement is recorded but not enforced until the office has worked through the backlog;
turning it on is a one-row `UPDATE`, and the migration carries both that statement and the
query to count who it would stand down first.

### Added — templated, queued, logged email

`send_mail()` opened a socket and spoke SMTP **inside the web request**. A slow mail server
therefore slowed the application, and a failed send was lost: nothing recorded that it had been
attempted, so nobody found out an applicant never got their confirmation.

- **Migration 008** adds `email_templates` (7 seeded, with `{{placeholders}}`), `email_queue`
  and `email_log`
- **`queue_mail()` and `queue_templated_mail()`** in `server/lib.php`. The templated form
  **refuses to send a half-filled message** — an email that goes out reading "Hi {{first_name}},"
  has already reached the recipient by the time anyone notices
- **`server/send-queued-mail.php`** drains by cron. It claims a row before attempting it, so two
  overlapping cron runs cannot send the same message twice; caps retries at five, so a message
  that will never succeed stops churning and stays visible as failed; and batches at 50, because
  pushing a thousand emails through one SMTP connection gets throttled
- **Nine senders converted.** Three stay synchronous *on purpose*, and now say why in the code:
  the **lone-worker panic alert** (an alert waiting for the next cron run is not an alert),
  **password reset** (somebody is sitting on the screen waiting for it), and interactive
  **mailbox replies**

**Queued regardless of whether mail is enabled.** The `mail_enabled()` gate was removed from the
call sites: the queue is the record of what the system intended to send, and the drainer decides
what can actually go out. Gating at the call site meant a disabled mailer left no trace that
anybody should have been contacted. Verified — with mail off locally, a public enquiry queues
two messages and the drainer records both as `skipped` with the reason, rather than nothing
happening at all.

`rg_render_template()` leaves an unknown placeholder **in place** rather than emptying it. "Hi ,"
looks like a mistake to the recipient; "Hi {{first_name}}," looks like a mistake to whoever sent
it, and they are the person who can fix it.

### Added — an audit log that can answer a question

`audit_log` held a user, an action, a 255-character string and a timestamp. That records *that*
something happened, but not what changed, to which record, or from where. "Who marked this SIA
licence verified, and what did it say before?" was unanswerable.

Migration 009 adds `entity_type`, `entity_id`, `before_json`, `after_json`, `reason`, `ip` and
`user_agent`, plus indexes on entity, user and time — finding everything that happened to one
record is the commonest audit question, and there was no index for it.

`audit()` keeps its existing signature, so all ~90 call sites carry on working unchanged and
pick up IP and user agent automatically. The high-value ones now pass the rest:

```
action      entity    id  before                     after                      ip
ats.stage   applicant 1   {"stage":"applied"}        {"stage":"rejected"}       127.0.0.1
worker.pay  worker    1   {"pay_rate_pence":1350}    {"pay_rate_pence":1450}    127.0.0.1
```

`audit_diff()` records **only what changed** — `pay_type` is absent above because it did not.
An entry holding a copy of every field is a second copy of the data, which is its own retention
problem.

`audit.php` gains filters: `?entity_type=worker&entity_id=12` for one record's whole history,
`?action=` for one kind of event, `?user_id=` for one person's activity. Reading the log **with
a filter** is itself audited; the routine full listing is not, or the log fills with entries
about reading the log.

### Added — eligibility, visible

`src/lib/types.ts` did not know about any of the last few milestones. It now carries `Person`,
`EligibilityVerdict`, `RequirementState`, `AssessedRequirement`, `RightToWorkCheck`,
`QueuedEmail` and a fuller `AuditEntry` — a narrower duplicate `AuditEntry` was **merged**
rather than left alongside it, since two definitions of one thing is the drift this work exists
to remove.

`src/components/app/eligibility-dialog.tsx` shows one person's answer for all four services,
opened from the status column on the Workers page. It lists **every** blocking reason, not just
the first, and it is read-only by construction — there is no endpoint that would accept a
compliance status if it tried.

Written so it does not add to the lint baseline: `loading` is *derived* from a single state
object carrying the worker it belongs to, rather than set at the top of the effect. That also
makes a stale response impossible to render — a reply for a worker who is no longer selected
simply never matches.

### Added — the Action Centre

`server/action-centre.php` answers one question: what needs attention today? The office should
not have to visit fifteen screens to find out, and an owner should not have to know which
screen a problem would appear on.

Grouped by **urgency, not category** — a list sorted by which module a problem belongs to
answers a question nobody asked. Everything is **derived**: there is no action table to fall
out of step with reality, so an item cannot linger after the thing it describes is fixed.

```
counts: urgent 5, today 4, soon 2

[urgent] 7 people cannot be deployed
         Audit Probe (cleaning): Signed contract: no evidence on file; Identity: ...
[urgent] 1 SIA licence has expired
[urgent] 2 unfilled shifts start within 3 days
         Nobody is assigned. These are the ones that become a phone call at 6am.
[today ] 8 right-to-work checks need verifying
[soon  ] 1 credential expires within 30 days
         Still valid today. Chase now and nobody is stood down later.
```

It also watches **itself**: failed emails, and a queue not drained for over two hours. If the
mail cron stops, the first symptom is otherwise somebody saying they never heard from us.

Staff-only. A worker account gets 403.

### Changed — eligibility is recalculated daily

Eligibility is a calculated value, so it goes stale the moment a credential lapses, not when
somebody next opens a page. Reassessment is folded into the **existing** `expiry-alerts` cron
rather than adding another one to configure, so the Action Centre and the dashboards are true
without anyone having looked.

Only the services a person is actually intended for are assessed. Scoring everyone against
everything would produce a wall of "not eligible for Security" for cleaners who were never
going to work security.

### Added — a PWA that actually works offline, and 37 MB less of it

`public/sw.js` was hand-written. It precached four fixed paths and knew nothing about hashed
asset names, so assets were cached only opportunistically after a first fetch and **offline
broke after every deploy** — the one moment it most needed to work.

- `vite-plugin-pwa` replaces it, with a **build-hash precache manifest**: a deploy invalidates
  exactly what changed
- Manifest generated from the config, so it cannot drift. 192, 512 and a separate **maskable**
  icon, drawn inset into the middle 80% because Android crops it to the launcher's shape
- `src/components/pwa-status.tsx` — an update prompt and an offline banner. `registerType:
  "prompt"`, never automatic: swapping the app out from under somebody part-way through
  clocking in is its own kind of failure. The banner says what still works, not just that
  something is wrong
- Read-only worker data (`me.php`, `notifications.php`) is `NetworkFirst` with a 5-second
  timeout. **The rest of the PHP API is never cached** — stale compliance data would mean
  showing an eligibility answer that is no longer true, which is worse than showing nothing

**37 MB of orphaned assets deleted.** `public/cards`, `public/products`,
`public/illustrations`, `public/images/carousels`, `public/images/countries` and
`public/images/cards` were Pulse-UI template leftovers with **zero references** anywhere in the
source. They were shipped on every deploy and precached into the installable app.

The office-only chunks are excluded from the install too: the app starts at `/m`, and a field
worker should not download a charts library, a calendar and a PDF engine they will never open.

```
public/    37 MB  ->  756 KB
precache   14.5 MB across 115 entries  ->  1.15 MB across 67
```

### Fixed — the app icon was still the old brand

`public/favicon.svg` was green `#15803D` / `#22C55E`, from the palette retired in the v2
rebrand. Redrawn in navy and mint, with PNGs generated from it for every size the manifest and
iOS need.

### Added — employment records: contracts, leave, document metadata, retention

Migration 010, closing three gaps at once.

**There was no employment contract.** The `contracts` table is a *commercial* contract with a
client — scope, rate, frequency, SLA. Nothing described the agreement with a worker.
`employment_contracts` adds draft → sent → viewed → accepted → signed, with `version` and
`supersedes_id`: a signed contract is evidence of what was agreed at a point in time, so it is
superseded rather than edited.

**The Acas particulars were not queryable.** They are now fields — job title, place of work,
pay and frequency, hours pattern, holiday entitlement, sick pay, other paid leave, notice both
ways, probation, training entitlement, pension, collective agreements. A PDF is the artefact;
these are what make them answerable without a human opening a document.

**There was nowhere to record holiday.** `leave_records` plus `server/leave.php` covers annual,
sickness, unpaid, parental, bereavement, compassionate, jury service and training — with
holiday pay, payments in lieu, carry-over, and return-to-work discussions after sickness.

Counted in **hours, not days**. Shift work is not made of whole days, and a balance in days is
wrong for anyone on variable hours.

Two decisions worth stating:

- **A request is not an agreement.** Requested leave is reported but *not* deducted from the
  balance. Showing a balance already reduced by an unapproved request misleads both sides.
- **No entitlement recorded is not an entitlement of zero.** The balance returns `null` rather
  than a large negative number, which would read as "overdrawn" when it means "nobody set this
  up" — the same distinction as `unknown` versus `expired` in compliance.

Overlapping leave is refused, naming the dates that clash, rather than quietly double-booking
somebody's holiday. A worker may request their own leave and see only their own; whatever
status they submit, it is recorded as a request.

**Document metadata:** `files` gained `issued_on`, `expires_on`, `verification_status`,
`verified_by`, `verified_at` and `person_id`. A document with no issue date, no expiry and no
verifier cannot be evidence of anything.

**Retention as configuration:** `retention_policies` is seeded with eight record types and
**NULL periods, deliberately**. The values belong to the business and its legal advice, not to
this schema, and NULL is distinct from zero so a dashboard can say "no policy set" rather than
implying immediate deletion.

### Added — Google Forms intake

The office already runs recruitment through Google Forms. The decision taken was to keep that
**and** the native `/apply` page, with Forms as an *integration* rather than the database — so
the CMS owns the record and Forms can be replaced later without rebuilding anything.

- **`server/forms-intake.php`** — a webhook for an Apps Script on the form's submit trigger.
  Authenticated by a shared secret compared with `hash_equals` (Apps Script has no login), rate
  limited because the endpoint is necessarily public, and **redelivery-safe** via the form's own
  response id
- **The mapping is configuration.** `integration_field_maps` holds question → CMS field, with
  optional value translation so the form can say "Security Officer" and the CMS store
  `security`. A form that gains a question, or has one reworded, must not need a code change
- **Matching is deliberately forgiving** — case, surrounding whitespace and the trailing
  asterisk Google adds to required questions. An exact-string join breaks *silently*:
  submissions arrive and map to nothing
- **The raw payload is stored first** and kept whether or not it maps. A submission that fails
  to map is a person who applied and would otherwise vanish; the payload is what makes them
  recoverable once the mapping is fixed. `?action=retry` re-maps one
- **Nothing is discarded.** Answers the mapping could not place are written onto the applicant
  as `[unmapped] <question>: <answer>`, so a new question on the form shows up as something to
  configure rather than disappearing

**Silence is the failure that matters.** A form that stops feeding the system looks exactly
like a quiet week. Each integration carries `expect_within_hours`, and the Action Centre reports
anything past its window — along with any submission that could not be mapped.

Verified end to end: a missing or wrong secret is refused with 401; a real submission creates
one person and one applicant and reports the unmapped question; the same response redelivered
returns `duplicate: true` without creating a second application; a submission missing a required
answer fails with the question named, and its payload is retained.

### Added — deletion approval, export logging, retention settings

Two gaps with the same shape: an action that removes or copies personal data left no trace of
who decided it was alright.

**Nobody deletes an employment record on their own say so.** `server/governance.php` gives
ordinary staff *request deletion*, not *delete*. A reason of at least 10 characters is
required — a request nobody can explain later is not one an approver can sensibly decide on —
and **the approver may not be the requester**. Verified: an admin attempting to approve their
own request is refused. Archive is the default disposition, and the label of what was removed
is captured at request time, because the record may be gone by the time anybody reads it.

**Every export of personal data is logged** — user, type, format, record count, scope, purpose
and IP. Exports are built in the browser from data already fetched, so a client call is the
only point at which the server can know one happened. Where a spreadsheet goes after it leaves
is outside this system's control, which is precisely the reason to record that it left.

**Retention periods are editable and start NULL**, deliberately. The values belong to the
business and its legal advice, and NULL is distinct from zero so a dashboard can say "no policy
set" rather than implying immediate deletion.

### Fixed — archiving now actually archives

The deletion workflow could not archive an applicant: `applicants` had no `deleted_at`, leaving
a hard `DELETE` as the only option, which is exactly backwards. Migration 013 adds `deleted_at`
to `applicants`, `invoices`, `incidents`, `timesheets` and `shifts` — five tables where a
removed row destroys a recruitment decision, a financial record, a statutory report, pay
evidence or a worked shift, and three of which cascade to child rows.

It also adds `updated_at` to `timesheets`, which had neither, so an approved timesheet could be
altered with no trace of when; and the index on `shifts.client_id`, which `scheduling.php` joins
on and which had neither a key nor a foreign key while `shift_templates.client_id` in the same
build pass had both.

Every list and count query for those tables now filters on `deleted_at`. An archive that leaves
the row visible is not an archive.

### Fixed — the dashboard binds its parameters

`server/dashboard.php` interpolated the division filter with `real_escape_string`. The value was
already checked against an allowlist so it was not injectable — but the guarantee depended on
remembering that check, and the next person adding a filter had a pattern to copy that did not
carry it. Every division filter is now bound. Verified across all four divisions.

### Fixed — the constraints the build passes could not add

Every column added by a later `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` in `schema.sql` is
unconstrained, because that syntax cannot carry a constraint. That is the mechanical cause of
the foreign-key gaps rather than an oversight by anyone. Migration 014 adds the six that were
missing: `users.client_id`, `users.worker_id`, `clients.account_manager_id`,
`shifts.client_id`, `service_requests.shift_id` and `audit_log.user_id` — the last of which was
joined in `audit.php` with neither a key nor an index, making every audit query a full scan.

`ON DELETE SET NULL` throughout rather than CASCADE. A deleted user must not take their audit
entries with them — that is the opposite of what an audit log is for.

**`clients.divisions` was a comma-separated string.** It cannot be joined, indexed or
constrained, and "care" matches "healthcare" under `LIKE`. `client_services` is the same pattern
the schema already contains as `worker_skills`, applied to the one place it was solved a second,
worse way. The CSV is kept in step by `clients.php?action=services` until nothing reads it —
two sources of one fact drift, so they are written in one place.

### Added — training records join the person spine

`academy.php?action=enrol` resolved nothing: `enrolments` carried a `learner_name` string, so a
person's training history was disconnected from the person.

It now resolves a person from an explicit id, or from an email or phone. With only a name it
records the enrolment **unlinked** rather than guessing — matching on a name would merge every
John Smith — and the Action Centre lists those to be linked by hand.

That completes the spine. Every entry point now resolves to one person: the public application,
self-registration, the Academy learner handoff, worker creation, user creation, the Google Forms
webhook, and training enrolment.

### Added — per-service scope and sensitive zones

Two problems with the same root: a permission that was recorded but never enforced.

**`users.division` was set and ignored.** A Care recruiter saw Security candidates, which is not
what "scoped to a division" means. `service_scope()` now filters the workforce and candidate
lists. It is **opt-in per account** via `users.scope_to_division`, defaulting off, so this
migration narrows nobody's access on the day it runs — silently narrowing access is how a Monday
morning becomes "the system is broken". `super_admin` and `admin` are never scoped: somebody has
to be able to see the whole picture.

**A worker profile was one undifferentiated record.** Health information, disciplinary matters
and payroll are not ordinary fields, and "can open the profile" must not mean "can see all of
it". `zone_permissions` is a role × zone matrix — general, employment, compliance, health,
payroll, disciplinary, legal — editable by a super admin without a deployment.

`can_view_zone()` replaces the hard-coded role list that gated pay on the workforce list. **No
row means no permission**, so a zone nobody has configured is not readable by default.

Two guards on the matrix itself: a super admin cannot be denied a zone, because the account that
fixes a mistake must not be able to lose the screen that fixes it; and edit-without-view is
refused as incoherent.

Verified: a manager scoped to Care sees 3 workers, all Care, with pay. A trainer sees all 10
workers across every division and **no pay at all**, because the trainer role has no payroll
zone. Both from the same endpoint, with no role check in it.

### Added — system health and staff workload

The owner should not learn that recruitment stopped importing three weeks ago from a candidate
ringing up.

`server/system-health.php` runs **live checks, not a stored status** — a health table goes stale
exactly when it matters. It covers the database, migrations shipped but never run, mail, the
queue drainer, integrations, eligibility freshness, document storage and backups. States are
`ok` / `warn` / `fail` / **`off`**, because a deliberately disabled mailer is a configuration
choice and must not read as a fault.

It earned its place immediately: on first run it reported `fail — the uploads directory does not
exist`, which would have meant every document upload failing.

Two checks worth calling out. **Unapplied migrations**: a deploy that ships the files without
running them looks completely normal until something fails oddly, so the directory is compared
against `schema_migrations`. And **restore testing**, which cannot be inferred — it is reported
as an explicit unknown rather than assumed fine, because a backup nobody has restored is a hope,
not a backup.

`?action=workload` shows what each staff member is carrying. Not a performance measure: it
exists so work can be moved before somebody quietly drowns in it, which is only possible if it
is visible.

### Added — worker status is derived, and training can come from anywhere

`workers.status` was active/onboarding/inactive, **set by hand**. A status somebody has to
remember to change is a status that is wrong, and this one decides whether a person appears in
the workforce the office schedules from.

`workers.derived_status` is now computed daily from the evidence: leave, onboarding tasks, a
signed contract, and how many services the person is actually eligible for. The manual column
stays as the **intent** — what the business means to do with this person — and the derived one
is what is true. Keeping them apart matters: "we intend to employ them and they are not yet
cleared" is a real and common state, and collapsing it into one field loses exactly the
information the office needs.

The fixtures make the point: **all ten workers read `active` manually; four genuinely are.** The
other six have no signed contract on file.

Order is the whole design, and most of the 16 tests cover which rule wins rather than any rule
alone. Having left outranks everything — a leaver is not a compliance problem. A suspension is a
decision, not a derivation. Approved leave outranks ineligibility, because somebody on holiday
has not *become* non-compliant.

**Training providers:** `programmes` were implicitly RitaGreen Academy's, so a worker who did
their first aid with St John Ambulance had training the system could not record. `enrolments`
now carries a provider, an external course name, and completion and expiry dates, and
`programme_id` is nullable because training done elsewhere has no programme of ours.

### Fixed — reassessment was skipped on quiet days

The eligibility and status recalculation sat at the end of `expiry-alerts.php`, **after an
`exit` taken when no reminder was due**. On a quiet day nothing was recalculated at all, so
eligibility went stale on exactly the days nobody was watching. It now runs first and
unconditionally, which also means the reminders are composed from a fresh assessment.

### Added — one verification lifecycle for everything

Verification was three different shapes. `worker_references` had a status. `right_to_work_checks`
had its own `verified_by`/`verified_at`. Everything else — an SIA licence, a DBS certificate, a
qualification, a document — had **nothing**: a number typed into a field, with no record of
whether anybody looked at the register, who, or when.

`verifications` gives them one shape: requested → received → under review → verified / rejected
/ expired. **Only legal transitions are permitted**, and a refusal names what *is* possible:

```
POST verify   (no method)   -> Record what you checked, so the entry means something later.
POST received (on verified) -> A verification cannot go from 'verified' to 'received'.
                               From here it can only become: expired.
```

`received` and `under_review` are separate on purpose. A document arriving and somebody actually
checking it are different events with different people behind them, and collapsing them is how
"we have it" quietly becomes "we checked it".

**Signing one off requires recording what was checked.** "Checked the SIA public register
against the licence number" and "saw a photocopy" are not the same assurance, and only that
field can tell them apart later.

**The existing data came across honestly.** An SIA number or DBS status against a worker means
somebody typed it in; it does not mean anybody checked a register. They import as `received`,
not `verified`, each carrying a note saying exactly that. Marking them verified would have been
inventing an assurance nobody gave.

`right_to_work_checks` is deliberately **not** folded in — it carries methods, share codes and
recheck rules that belong to it alone, and flattening those into a generic table would lose them.

The compliance engine reads a completed verification in preference to a typed field, but
**augments rather than replaces**: a check still at `received` does not downgrade the underlying
record, because every migrated entry starts there and downgrading them at once would block the
entire workforce on the day this shipped. Same trap right to work hit, same answer — the
evidence model improves now, enforcement follows once the backlog is worked through.

### Still open

Recorded in `docs/STATUS.json`, verified against source, not yet fixed: the GoCardless webhook
has no timestamp replay window (the Stripe one does); `server/dashboard.php` interpolates the
division filter with `real_escape_string` rather than binding it; `server/lib.php` returns raw
exception messages to callers.

### Notes

This milestone ships **no functional change**. Its purpose is to make every later phase
executable against a written contract, and to record honestly what the audit found — including
that development currently runs against the production database, that there are no tests, and
that a person can exist three times in this schema.
