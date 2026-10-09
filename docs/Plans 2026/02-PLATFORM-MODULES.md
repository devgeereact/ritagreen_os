# 02 Platform modules

## Principle

People should not need to understand the database to do their job. Every screen has one obvious next action, plain labels and short forms. Reuse what exists before building anything (launch plan, "Reuse before building"). Hiding a link does not retire a feature; retirement follows the launch plan's removal protocol.

## The eight destinations

The office app sidebar drops from 7 groups and about 30 links (`src/components/app-sidebar.tsx`, `NAV_GROUPS`) to eight destinations. This is the launch plan's seven (Today, Recruitment, People, Compliance, Assignments, Reports, Settings) with Assignments named **Rota**, plus **Website**.

| Destination | What lives there | Existing pieces reused |
|---|---|---|
| **Today** | What needs doing now: unfilled shifts, documents to verify, approvals waiting, expiring evidence, new applicants, new enquiries, failed emails. Every item opens the place to act on it | `server/action-centre.php`, `server/dashboard.php`, `server/system-health.php` |
| **Recruitment** | Applicant board, applicant detail, interviews, tasks, form intake failures, spreadsheet import | `server/ats.php`, `server/forms-intake.php`, `src/pages/app/ATS.tsx` |
| **People** | Staff list and one profile per person (tabs below). Leave and training records live inside the profile | `people` spine, `server/workers.php`, `server/leave.php`, `server/employment-contracts.php` |
| **Compliance** | Verification queue, right-to-work checks, expiry list, service rules | `server/verifications.php`, `server/right-to-work.php`, `server/compliance.php`, `server/compliance-lib.php` |
| **Rota** | Rota, open shifts and offers, timesheets, clients and sites, requests from the website | `server/scheduling.php`, `server/shift-templates.php`, `server/timesheets.php`, `server/clients.php`, `server/bookings.php`, `server/enquiries.php` |
| **Website** | Posts, vacancies and forms, testimonials, company details and social links | `server/blog.php`, `server/testimonials.php`; new vacancies and settings records |
| **Reports** | A small set of reliable reports: recruitment pipeline, active and blocked workers by service, expiring evidence, hours worked, unfilled shifts | `server/reports.php`, `server/analytics.php` |
| **Settings** | Users and access, feature switches, email queue, audit log, data requests and retention, help | `server/users.php`, `server/governance.php`, `server/audit.php` |

Notifications stay in the header bell. Workers never see the office app; they use the staff portal (file 03).

## The person profile

One page per person, reached from Recruitment, People, Compliance or Rota. Tabs:

| Tab | Contents |
|---|---|
| Overview | Photo, name, services with status per service (applicant, onboarding, active, suspended, left), payroll number, phone, email, address, start date, next shift |
| Checks and documents | Every requirement for each service the person works in: status, evidence file, verifier, date checked, expiry. Upload, verify, reject with reason |
| Training | Courses completed, certificates, renewal dates, upcoming bookings |
| Shifts | Upcoming and past shifts, hours, cancellations |
| Employment | Contract evidence (sent, signed, reviewed), pay rate history, leave balance and requests |
| Emergency contacts | Name, relationship, phone, second contact. Visible to authorised office staff and the person only |
| Activity | Audit trail of every change and who made it |

## Feature list

Status key: **Exists** (in code on the build branch; still to be checked end to end), **Backend only** (API exists, no screen), **Build** (new work), **Switch off** (kept, hidden behind a Settings switch, off by default), **Retire** (removed through the removal protocol).

| ID | Feature | Status | Section (file 08) |
|---|---|---|---|
| F01 | Person profile with tabs above | Build (data exists in `people`) | S2 |
| F02 | Emergency contacts | Backend only (columns in migration 001) | S2 |
| F03 | Recruitment board, stages, interviews, tasks | Exists (launch plan Phase 2) | S2 polish |
| F04 | Onboarding checklist per service, templates instead of free text | Backend only | S2 |
| F05 | Evidence verification queue | Backend only (`verifications.php`) | S2 |
| F06 | Right-to-work checks with dates and reviewer | Backend only (`right-to-work.php`) | S2 |
| F07 | Contract evidence: sent, signed copy, reviewed | Backend only (`employment-contracts.php`, migration 034) | S2 |
| F08 | Activation approval by a named person | Backend only (migration 034) | S2 |
| F09 | Compliance calculated per service, never typed in | Exists (`rg_classify`, `rg_assess_person`) | S2 |
| F10 | Training records, certificates, renewal dates, training matrix per service | Exists in part (`certifications`, `academy.php`) | S2 |
| F11 | Rota week and day views, draft and publish, edit, cancel, reassign | Build on `scheduling.php` | S3 |
| F12 | Open shifts, offers, accept and decline, first to accept wins | Exists (`offer.php`, `me.php`); gate enforced on every path (launch plan fix, 14 September) | S3 polish |
| F13 | Sites as records with address, access notes, contact, required training | Build (sites table exists, read-only today) | S3 |
| F14 | Availability and leave, checked when assigning | Backend only (`leave.php`) | S3 |
| F15 | Timesheets from clock events, worker submits, office approves or queries | Exists in part (`timesheets.php`, migration 021) | S3 |
| F16 | Staff portal with four tabs | Build on `WorkerApp.tsx` and `me.php` | S4 |
| F17 | Shift reminder the day before, offer and change notices | Exists in part (`notify()`, email queue) | S4 |
| F18 | Payslip view for workers | Backend only (`me.php` payslip) | S4 |
| F19 | Today page | Backend only (`action-centre.php`) | S5 |
| F20 | Reports, one page | Exists in part (`reports.php`, `Analytics.tsx`, `Insights.tsx`) | S5 |
| F21 | Worker profile pack PDF for clients (photo, role, training, DBS date, right to work verified) | Exists in part (`compliance.php` pack) | S5 |
| F22 | Website vacancies and role pages from the CMS | Build | S7 |
| F23 | Forms directory and embedded Google Forms | Build | S7 |
| F24 | Company details, hours and social links from the CMS | Build | S7 |
| F25 | Google Forms auto-feed into Recruitment | Exists (`forms-intake.php`, launch plan Phase 2); Apps Script set-up documented in file 06 | S7 |
| F26 | Spreadsheet import to create or update people | Build | S8 |
| F27 | Incidents, complaints and safeguarding concerns | Exists (`incidents.php`) | Keep, move under Rota |
| F28 | Policy acknowledgement (upload policy, staff confirm read) | Build, P2 | Later |
| F29 | Cleaning job checklist | Build, P2 | Later |
| F30 | Client portal | Exists in part; restricted until its future is decided (launch plan) | Later |

## Keep, switch off, retire

| Existing feature | Verdict | Why |
|---|---|---|
| Invoices, quotes, client contracts, payroll pay-run export | **Switch off** as a "Finance" area, on for the owner when wanted | Launch plan excludes finance from launch scope but keeps records and hand-offs |
| Stripe pay links, GoCardless | **Switch off** | No confirmed use; keep code and history |
| Built-in email inbox (`inbox.php`, `Mailbox.tsx`) | **Switch off** | Staff already use their own mailbox |
| SMS and WhatsApp sending | **Switch off** until email and in-app work (launch plan Phase 2) | Cost and consent; turn on per message type later |
| Field operations panic button and check calls | **Keep** with its current behaviour; no expansion | A safety function is not removed without a replacement (launch plan) |
| Certificate verification page `/verify` | **Keep** | Useful public trust signal for Training |
| Content Studio (AI writing) | **Retire**; posts are written in the Website area | Launch plan removal list |
| AI assistant in the header | **Retire** | Launch plan removal list |
| Quiz builder in Academy detail | **Retire** | Not a learning platform |
| Weather widget | **Retire** | No business purpose |
| Separate Analytics and Insights pages | **Retire** into one Reports page | Duplicate surfaces |
| `/app/timetable` | **Retire** (already redirects to timesheets) | Duplicate route |
| Comms announcements | **Keep**, inside Settings until WhatsApp Community replaces it | Low cost |
| Files (document centre) | **Keep**, reached from the person profile and Settings | Documents belong to people |

## Access

No new stored roles unless a gap is proven. Presented as Owner, Staff and Worker (launch plan).

| Person | Stored role today | Sees |
|---|---|---|
| Owner | `super_admin`, `admin` | Everything, all services, settings and approvals |
| Office staff | `manager`, `employee`, `trainer`, `editor` | Their permitted services; sensitive zones only where granted (`zone_permissions`, migration 015) |
| Worker | login linked to a worker (`worker_id`) | Own shifts, own records, own requests in the staff portal |
| Client | `client` | Restricted until the client portal is decided |

The server is the boundary. `src/lib/access.ts` only hides what the server would refuse.
