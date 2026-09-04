# Data inventory

**Status:** an evidence-backed record of what this system holds, not legal advice.

Every row was established by reading the code and, where noted, by running it against a local
seeded database. Nothing here is inferred from a table name. Where a fact depends on a business
decision or a legal interpretation it is marked **OWNER** or **LEGAL**, and it is not filled in
with something plausible.

Companion documents: [`COOKIES.md`](COOKIES.md) for browser storage,
[`RETENTION.md`](RETENTION.md) for periods, [`UK_GOVERNANCE.md`](UK_GOVERNANCE.md) for the
framework this sits inside.

---

## 1. Controller

| Item | Value | Source |
|---|---|---|
| Legal entity | RitaGreen Services Ltd | `src/lib/company.ts` |
| Company number | 12528439 | `src/lib/company.ts` |
| Registered office | **OWNER — not set.** `COMPANY.registeredAddress` is empty and the privacy notice says so in place of the value | `src/lib/company.ts` |
| ICO registration | **OWNER — not set.** `COMPANY.icoNumber` is empty | `src/lib/company.ts` |
| Privacy contact | `hello@ritagreenservices.com`, the general inbox | `src/lib/company.ts` |
| Data protection officer | **OWNER** — none is named anywhere in the codebase. Whether one is required is **LEGAL** | — |
| Jurisdiction | England and Wales, stated in the terms. Users are London-based clients, workers and applicants | `src/pages/public/Legal.tsx` |

---

## 2. Data subjects

Six groups, with materially different records:

| Group | Where the record lives |
|---|---|
| Website visitors | Browser storage, server request logs, `rate_limits` |
| Enquirers and quote requesters | `contact_enquiries`, `service_requests`, `consent_log` |
| Applicants and learners | `people`, `applicants`, `integration_submissions`, `enrolments`, `certificates` |
| Workers | `people`, `workers`, and 15 further tables |
| Client contacts | `clients`, `sites`, `contracts`, `invoices` |
| People receiving care | Care-related records; see the note in §4 |

---

## 3. Collection points

Public, no authentication:

| Point | Route | Endpoint | Fields | Consent captured |
|---|---|---|---|---|
| Contact | `/contact` | `contact.php` | name, email, phone, division, message | Optional marketing only |
| Quote | `/quote` | `contact.php` | name, org, email, phone, location, start date, scale, notes | Optional marketing only |
| Cover request | `/request` | `bookings.php` | contact name, email, phone, division, site, dates, detail | Required, logged |
| Apply | `/apply` | `ats.php` | name, email, phone, role, division | Required, logged |
| Careers | `/careers` | `ats.php` | name, email, phone, role, free text | Required, logged |
| Register | `/register` | `auth.php` | name, email, phone, division, role | Required, logged |
| Data request | `/data-request` | `data-request.php` | name, email, relationship, detail | Not applicable |
| Certificate check | `/verify` | `certificates.php` | certificate id only | Not applicable |
| Google Forms | external | `forms-intake.php` | whole answer set, stored raw | Captured by Google, not here |

Authenticated: the CMS at `/app/*`, the client portal at `/portal`, the worker app at `/m`.

---

## 4. What is held, by category

Personal data throughout. **Special category or criminal-offence data is marked ●.**

| Category | Fields | Table | Subject | Purpose |
|---|---|---|---|---|
| Identity | full name, preferred name, date of birth, email, phone, address, postcode | `people` | all | The single record of a person |
| Emergency contact | name, phone, relationship | `people` | worker | Safety on shift |
| Account | name, email, bcrypt hash, role, division | `users` | staff, worker, client | Sign-in |
| Two-factor | TOTP secret | `user_2fa` | staff | Sign-in. **Stored unencrypted at rest** — see §9 |
| Sessions | token hash, IP, user agent, last seen | `tokens` | all signed-in | Session management, per-device revoke |
| Employment | payroll number, role, division, status, leaving date, pay rate, pay type, holiday hours, home postcode, travel radius, availability | `workers` | worker | Employment and assignment |
| ● Right to work | method, reference, check date, outcome, follow-up, recheck date, evidence file, verifier | `right_to_work_checks` | worker | Statutory duty |
| ● DBS | status, expiry, update-service flag, recheck date | `workers`, `verifications` | worker | Vetting for regulated work |
| Licensing | SIA number and expiry | `workers`, `verifications` | worker | Security licensing |
| Credentials | type, number, expiry, status | `certifications` | worker | Fitness for role |
| References | referee name, employer, relationship, email, phone, status | `worker_references` | **the referee**, a third party | Pre-employment checks |
| ● Absence | kind including sickness, dates, hours, reason, return-to-work date and note, fit-note file | `leave_records` | worker | Absence management, pay |
| Contract | job title, dates, place of work, pay, hours, holiday, sick pay, notice, probation, pension, signature name | `employment_contracts` | worker | Written particulars |
| Attendance | clock-in and clock-out, **location**, note, signature name | `shift_events` | worker | Attendance, panic response |
| Assignment | shift, client, site, times, status | `shifts`, `timesheets` | worker | Rostering and pay |
| ● Incidents | type including safeguarding, severity, free-text detail, RIDDOR flag, named worker and client | `incidents`, `incident_actions` | worker, client, third parties | Safeguarding, health and safety |
| Eligibility | verdict, reason, score, assessment time | `person_services` | worker | The automated deployment decision, §7 |
| Applications | name, contact, role, division, stage, free-text notes | `applicants` | applicant | Recruitment |
| Raw form payload | the complete unmapped answer set | `integration_submissions` | applicant | Diagnosing a failed mapping |
| Training | learner name, programme, dates, certificate id | `enrolments`, `certificates` | learner | Training records; certificate id is publicly checkable |
| Client contact | organisation, contact name, email, phone, sites and addresses | `clients`, `sites` | client contact | Account management |
| Financial | invoices, amounts in pence, Stripe and GoCardless identifiers | `invoices`, `mandates`, `dd_payments` | client | Billing. **No card or bank details are held here** |
| Documents | original filename, type, size, category including `id`, links to person, worker or client | `files` plus bytes in `server/uploads/` | worker, client | Evidence vault |
| Consent | email, kind, granted, source, IP | `consent_log` | all | Evidence of a choice |
| Data requests | reference, kind, name, email, relationship, detail, status, outcome, IP | `data_requests` | all | Handling a rights request |
| Messages | recipient, subject, **full body**, outcome | `email_queue`, `email_log`, `sms_log` | all | Correspondence record |
| Notifications | title, body — names applicants and workers | `notifications` | staff | In-app alerts |
| Audit | user, action, entity, before and after values, reason, IP, user agent | `audit_log` | staff who acted | Accountability |
| Exports | user, type, count, scope, purpose, IP | `export_log` | staff who exported | Accountability |
| Rate limiting | IP, bucket | `rate_limits` | visitors | Abuse prevention. **The only table with automatic deletion**, at one day |

**Care records.** The privacy notice describes holding care-related information where a care
service is delivered. No dedicated care-record table exists in this schema today; such
information would currently land in `incidents.detail` or a document. **OWNER:** confirm whether
care records are held in this system at all, or only in a client's own system.

**Not held anywhere:** national insurance number, bank sort code or account number, passport or
ID number as a field, ethnicity, disability, photograph. Identity documents exist only as
uploaded files under `files.category = 'id'`.

---

## 5. Recipients and processors

| Recipient | What reaches them | Triggered by | Location | Configured |
|---|---|---|---|---|
| Web and mail host (cPanel, Namecheap) | Everything; all mailboxes | Always | UK (`premium17.web-hosting.com`) | Live |
| Cloudflare | All request traffic | Always | Global edge | Live |
| Google Fonts | **Nothing, since this audit.** Previously every visitor's IP and user agent | — | — | Removed |
| Google (OAuth) | Staff email address | Sign in with Google | US | Off by default |
| Google Forms | The whole application, as the primary copy | An application form | US | Per integration |
| Twilio | Worker phone number, full message body | SMS or WhatsApp about a shift | US | Off by default |
| Stripe | Client organisation name, amount, invoice reference | Card payment | US/IE | Off by default |
| GoCardless | Amount, invoice metadata; collects bank details itself | Direct Debit | UK | Off by default |
| OpenRouter | Whatever a staff member sends, including up to 12,000 characters of extracted document text | A staff member asks for a summary | US, and onward to whichever provider hosts the model | Off by default |
| OpenWeather | A city name. No personal data | Dashboard widget | UK | Off by default |
| DBS, CQC, SIA, HMRC, insurers | As required | Statutory and regulatory | UK | Manual |
| Clients | Assigned worker's name, role, division, and whether required clearances are valid | Portal | UK | Live |

**Every one of those marked "off by default" is off in the deployed `server/config.php`** — the
keys are `CHANGE_ME` and `MAIL_ENABLED` is false. They are latent flows, not live ones.
**OWNER:** confirm which are actually in use in production.

**LEGAL:** the transfer mechanism for each US processor. The privacy notice says transfers are
covered by the terms held with each provider and does not name a mechanism, deliberately.

---

## 6. Sensitive-data access controls

`zone_permissions` (migration 015) defines seven zones. Before this audit only `payroll` was
enforced, in one place. Now:

| Zone | Enforced at | Roles holding it |
|---|---|---|
| `payroll` | `workers.php` | super_admin, admin, manager (view only) |
| `compliance` | `workers.php`, `compliance.php` | all internal roles except editor |
| `health` | `leave.php` | super_admin, admin. A person always sees their own |
| `disciplinary`, `legal`, `employment`, `general` | **Not enforced** — no feature stores this data yet | — |

---

## 7. Automated decision-making

`rg_evaluate_eligibility()` in `server/pure.php` scores each requirement for a person and a
service and returns `not_eligible` if a mandatory one is unmet.
`server/scheduling.php` refuses the assignment on that verdict, with no override path in the
file. `server/expiry-alerts.php` re-runs the assessment daily on cron with no human present and
can move a worker to `suspended`. Approved absence, including sickness, is an input.

A person is therefore refused work by a program. The reason is recorded and visible.
**LEGAL:** whether this engages UK GDPR Article 22, and what "meaningful human intervention"
must look like if it does. It is disclosed in the privacy notice either way.

**No AI is applied to a person's record automatically.** `ai.php` is a staff-initiated proxy;
there is no CV screening, scoring or auto-rejection anywhere.

---

## 8. Location processing

| Event | Precision stored | Why |
|---|---|---|
| `clock_in`, `clock_out` | 3 decimal places, about 110 m | Confirms attendance at a site |
| `panic` | 6 decimal places, full | Somebody has to be found |

Rounding is `rg_round_coord()` / `rg_coord_places()` in `server/pure.php`, applied in
`server/field.php`, tested in `server/tests/run.php`. Verified: nothing computes distance from
these values; they are written once and read for display.

Workers are told before the first clock-in by `src/components/worker/LocationNotice.tsx`. A
refusal at the phone's own prompt still allows clocking in, with no position recorded.

---

## 9. Security controls relevant to privacy

Present: TLS throughout; bcrypt at cost 12; session tokens stored as SHA-256 hashes with a
30-day expiry and per-device revoke; optional and enforceable TOTP; a 15-minute idle lock in the
CMS; uploads stored outside the reachable path under random names with `Deny from all`, streamed
behind an authorisation check; prepared statements everywhere; rate limiting; a CSP of
`default-src 'self'` with no third-party origin; an audit log with before and after values;
four-eyes approval before an employment record can be deleted; every export logged.

Open items:

| Item | Evidence | Severity |
|---|---|---|
| `user_2fa.secret` is stored unencrypted | `server/schema.sql` | P2. Database access already implies compromise, but it makes the second factor forgeable |
| Uploaded file bytes survive a soft delete | `server/files.php` sets `deleted_at` only. An approved hard deletion now unlinks the file (`server/governance.php`), but an archived one keeps the bytes by design | P3, and correct: an archive is meant to be recoverable |
| `certificates.php?action=verify` allows enumeration of learner names by certificate id | the file's own header says so | P2. Rate limited at 30 per 10 minutes |
| `employment_contracts.access_token` grants contract access with no login | migration 010 | P2. **LEGAL/OWNER:** confirm intended |
| Exception messages reach `error_log` and may contain personal data | `server/lib.php` | P3 |
| Database backups are full dumps kept 14 days, and cannot be edited per person | `server/backup.php` | P3, disclosed in the notice |

---

## 10. Confidence

Everything above is from source inspection, and the items marked verified were additionally
exercised against a local seeded database. **NOT TESTED:** production configuration, whether the
integrations are live, whether the seeded `dev@ritagreenservices.com` account exists on the live
database, and any behaviour of the live host.
