# Retention schedule

**Status:** a schedule with no periods in it, deliberately. Not legal advice.

Every period below is **REQUIRES LEGAL REVIEW**. None is set in the database, and nothing in the
system deletes anything on a schedule until one is. That is the correct state for a mechanism
whose values belong to the business and its advisers — see `docs/RULES.md` rule 23 and
`docs/UK_GOVERNANCE.md`.

Set a period in Settings, which writes `retention_policies.retain_months`. `server/retention-sweep.php`
then acts on it, and only on it.

---

## How the sweep behaves

```
retention_policies row
   ↓  retain_months IS NULL  →  nothing happens, ever
   ↓  a period is set
anchor date + period ≤ today
   ↓
action_at_end
   review   →  a task for a person
   archive  →  deleted_at is set; every list query already filters on it
   delete   →  a request in the four-eyes workflow, which a second person approves
```

It reports by default. It acts only with `--go`, from the command line. It never hard-deletes.

```bash
php server/retention-sweep.php          # report
php server/retention-sweep.php --go     # act
```

The arithmetic is `rg_retention_due()` in `server/pure.php`, with 21 cases in
`server/tests/run.php` — including that a NULL, zero or negative period is never due, and that a
record with no anchor date is never due.

---

## The schedule

| Record type | What it covers | System of record | Anchor the clock runs from | Period | Action | Backups | Owner | Status |
|---|---|---|---|---|---|---|---|---|
| `candidate_unsuccessful` | Rejected applications | `applicants` | Last update to the record | **LEGAL** — against current ICO recruitment guidance | review | Ages out with the 14-day dumps | HR | Swept once set |
| `worker_employment` | Employment records after leaving | `workers` and everything filed against the person | `left_on` | **LEGAL** | review | As above | HR | Swept once set |
| `right_to_work_check` | Right-to-work checks after employment ends | `right_to_work_checks` | **OWNER** — leaving date, or check date | **LEGAL** — against Home Office guidance | review | As above | Compliance | Not yet mapped in the sweep |
| `leave_records` | Holiday and absence, including sickness | `leave_records` | **OWNER** | **LEGAL** — against Acas guidance | review | As above | HR | Not yet mapped |
| `timesheets` | Timesheets and pay records | `timesheets` | Work date | **LEGAL** — payroll record-keeping | review | As above | Finance | Not yet mapped |
| `attendance_events` | Attendance, check calls and location | `shift_events` | Event time | Location is the most intrusive thing recorded about a worker day to day | review | Not set | Operations | Mapped (028) |
| `incidents` | Incidents, including RIDDOR-reportable and safeguarding | `incidents` | Occurrence date | **LEGAL** — health and safety, and safeguarding, which can be very long | review | As above | Compliance | Not yet mapped |
| `training_records` | Training and certificates | `enrolments`, `certificates` | Completion date | **LEGAL** — sector requirements | review | As above | Academy | Not yet mapped |
| `audit_log` | Accountability record | `audit_log` | Entry date | **LEGAL** — long enough to answer a question about a decision | review | As above | Governance | Not yet mapped |
| `contact_enquiries` | Website enquiries and quote requests | `contact_enquiries` | Created | **OWNER** — how long an enquiry stays useful | review | As above | Sales | Swept once set |
| `service_requests` | Cover requests from the website | `service_requests` | Created | **OWNER** | review | As above | Operations | Swept once set |
| `form_submissions` | Raw Google Forms payloads | `integration_submissions` | Received | **OWNER** — held only to diagnose a failed mapping, so short | review | As above | Platform | Swept once set |
| `sheet_import_rows` | Cells of a spreadsheet import (S8) | `sheet_import_rows.cells_json` | Upload | **OWNER**. The uploaded file is never stored. Cells are cleared when the import is applied (except failed rows, kept for the failures download) and entirely when it is closed; the row number, outcome and links stay as the record of what happened | review | As above | Recruitment | Not yet mapped: an import left open keeps its cells until somebody closes it |
| `data_requests` | Records of rights requests | `data_requests` | Completed | **LEGAL** — long enough to show how it was handled | review | As above | Governance | Swept once set |
| `consent_log` | Consent and opt-out evidence | `consent_log` | Created | **LEGAL** — an opt-out generally outlives the consent it withdraws | review | As above | Governance | Not swept; see below |
| `email_sms_log` | Delivery logs, including full message bodies | `email_log`, `sms_log`, `email_queue` | Created | **OWNER** | review | As above | Platform | Not yet mapped |

"Not yet mapped" means a period can be set and the sweep will report it as configured but
unswept rather than pretending to act on it. Mapping one is a few lines in `SWEEPABLE`; it was
left undone deliberately for the record types where the anchor date is a business decision
(`right_to_work_check`, `leave_records`) rather than an obvious column.

**`consent_log` is deliberately never swept.** Deleting the row that records an opt-out is how
somebody gets signed up again by the next import. Erasure anonymises the address on every
consent row except a marketing opt-out, which keeps it — a suppression record that names nobody
suppresses nobody. See `server/gdpr.php`.

---

## What has no policy at all

`shift_events` (including locations), `shifts`, `verifications`, `certifications`,
`worker_references`, `employment_contracts`, `files`, `notifications`, `export_log`, `tokens`,
`people`. **OWNER:** decide whether each needs its own policy row or is covered by
`worker_employment` as part of the person's file.

## Backups

`server/backup.php` writes gzipped full database dumps to a directory outside every webroot,
mode 0700, and prunes to 14 of each type. A backup cannot be edited per person, so an erasure
takes effect in backups by ageing out. The privacy notice says so.

## What runs automatically today

Two things, neither a retention policy: `rate_limits` rows older than a day are removed on about
1% of requests (`server/lib.php`), and `oauth_states` older than a day on sign-in
(`server/auth.php`). Everything else waits for a configured period.
