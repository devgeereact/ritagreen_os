# 03 Rota and staff portal

## One engine, four kinds of work

Healthcare shifts, cleaning jobs, security assignments and care visits all use the existing scheduling engine (`server/scheduling.php`). The labels change per service; the rules do not. Jobs and shifts tables are not merged (launch plan, Phase 4).

## Shift lifecycle

| State | Meaning | Who moves it |
|---|---|---|
| Draft | Planned, not visible to workers | Office |
| Published, assigned | A named worker has it and can see it | Office |
| Published, open | Any eligible worker can pick it up | Office |
| Offered | Sent to chosen workers; first eligible acceptance wins | Office, then worker |
| Accepted | Worker confirmed | Worker or office |
| Cancelled | With a reason; affected worker told | Office |
| Completed | Clock-out recorded or office closed it | System or office |
| Hours approved | Timesheet approved; ready for pay and invoicing | Office |

Every change after publishing tells the affected worker what changed.

## Rules the server checks on every assignment path

Office assignment, open-shift pick-up, offer acceptance by link, and acceptance in the portal all pass through the same check (`rg_deployment_check` and the clash check; enforced on every path since the 14 September fixes, see `docs/CHANGELOG.md`). S3 adds:

1. Approved leave blocks the assignment.
2. Availability the worker has marked as unavailable produces a warning the office must acknowledge.
3. Rest gap between shifts below a configurable minimum (default 11 hours) produces a warning.
4. Site required training (from the site record) is part of eligibility.
5. Overnight shifts and the change between GMT and BST are handled in Europe/London time.

A failed check keeps what the office typed and says exactly what blocks the worker.

## Office rota screen

| Part | Behaviour |
|---|---|
| Header | Week picker, Today button, filters for service and site, "Add shift", "Copy last week", "Publish drafts" |
| Week grid (desktop) | Rows by site (default) or by worker; each shift shows time, role, worker or "Open", status chip |
| Day list (phone) | Shifts in time order with the same information |
| Shift panel | Opens on click: edit, assign, offer, cancel with reason, history |
| Conflicts | Shown on the shift with the reason, not in a separate report |
| Templates | Existing shift templates (`server/shift-templates.php`) create recurring shifts as drafts |

Dragging is never required. Every action has a button.

## Timesheets

Clock-in and clock-out from the portal create the draft timesheet. The worker confirms breaks and submits. The office approves, or queries with a reason the worker sees. Corrections keep history (migration 021). Approved hours feed the pay-run export and invoices when Finance is switched on.

## Staff portal (`/m`)

Phone first, installable as an app from the browser (existing PWA). Four tabs at the bottom.

| Tab | Shows | Actions |
|---|---|---|
| **Home** | Next shift card: date, time, site name, address with map link, site contact, site notes. Anything blocking work (expired document, missing check) with what to do. Training due soon | Clock in and out (from 15 minutes before start), report a problem |
| **Rota** | My shifts by day, offers waiting, open shifts I am eligible for | Accept or decline an offer, pick up an open shift, add to phone calendar |
| **Records** | My documents and their status (valid, due soon, expired, awaiting review), my training and certificates, my payslips | Upload a replacement document, download a certificate or payslip |
| **Me** | Profile, emergency contacts, availability, leave requests and balance | Edit contact details and emergency contacts, set availability, request leave, contact the office |

Rules: a worker sees only their own records; every request proves ownership on the server. Sensitive data is not kept in the offline cache (payslips already excluded, 8 October audit). Large tap targets, short forms, a clear saved or failed message after every action.

## Notifications

| Event | In the portal | Email | SMS or WhatsApp |
|---|---|---|---|
| Shift offered | Yes | Yes | Later, when switched on |
| Shift published, changed or cancelled | Yes | Yes | Later |
| Reminder the day before | Yes | Yes | Later |
| Document due in 30 days, expired | Yes | Yes | No |
| Document rejected, with reason | Yes | Yes | No |
| Leave approved or declined | Yes | Yes | No |
| Timesheet queried | Yes | Yes | No |

The portal is the record. The WhatsApp Community (file 07) carries announcements only and never confirms or changes a shift.
