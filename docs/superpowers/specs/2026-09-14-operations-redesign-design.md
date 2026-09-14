# Operations, redefined: Today, Rota, Pay

> **Superseded in part, 14 September 2026.** The approved product direction is
> [`../../LEAN-CMS-LAUNCH-PLAN.md`](../../LEAN-CMS-LAUNCH-PLAN.md), whose navigation is Today,
> Recruitment, People, Compliance, Assignments, Reports and Settings. The Today / Rota / Pay
> grouping proposed here is **not being implemented as a whole**, and the files it lists are not
> to be deleted on the strength of it.
>
> Two of its findings are carried into the approved plan and remain correct, both checked against
> the code rather than assumed: `action-centre.php` already returns every item needing attention
> with a link to where it is fixed and nothing in `src/` calls it, and `bookings.php` is the
> front door to cover while being filed under money. The approved plan's Today list and
> Assignments section build on both.
>
> Design only. Nothing here was implemented.


**Date:** 2026-09-14
**Status:** design agreed, not implemented
**Scope:** the OPERATIONS group of the internal CMS only

## Outcome

The Operations section goes from five menu items to three, arranged around the
three rhythms the office actually works to rather than the five tables that
happen to exist:

| Surface | Answers | Rhythm |
|---|---|---|
| **Today** | What needs me now, and who is at work | Continuous |
| **Rota** | Who is covering next week | Daily to weekly |
| **Pay** | Approve the hours, run the money | Weekly to monthly |

No database migration. No change to the pay engine. Every existing URL keeps
working through a redirect.

## The problem

Five menu items (Scheduling, Jobs, Field ops, Timesheets, Payroll) present one
pipeline as five unrelated destinations, and the pipeline does not even start
inside the group.

```
Bookings ..........> Scheduling ..> Field ops ..> Timesheets ..> Payroll
"Clients & money"    |<----------- "Operations" ------------->|
                          ^
                     Jobs (ad-hoc, parallel table)
```

Four specific findings, each verified against the code rather than assumed:

1. **The front door is in another group.** `server/bookings.php` describes
   itself as "the client-facing front door to cover" and carries
   `?action=confirm`, which turns a client request into an open shift. It is
   step one of operations and it is filed under Clients and money, two menu
   groups away from the rota it feeds.

2. **There is already an engine for "what needs attention" and nothing uses
   it.** `server/action-centre.php` returns every item needing attention,
   grouped and ranked by urgency, each with a link to where it is fixed. Its own
   header states the problem it was written for: "The office should not have to
   visit fifteen screens to find out." No file in `src/` calls it.

3. **Jobs is a parallel spine that the project itself does not populate.**
   `server/seed.php` creates shifts and zero jobs. `jobs` had no entry in
   `docs/STATUS.json` and no mention in `docs/TRACEABILITY.md` until 2026-09-13.
   The distinction it encodes is real (ad-hoc work against rota work) but that is
   a property of a piece of work, not grounds for a second board.

4. **Timesheets and Payroll are one task split in two, with two period
   pickers.** Only approved hours reach a pay run, and nothing on either screen
   says whether the other one is finished.

A sixth item, Timetable, was a straight duplicate of Timesheets and was removed
on 2026-09-13.

## Navigation

```
BEFORE                      AFTER

Operations                  Operations
  Scheduling                  Today
  Jobs                        Rota
  Field ops                   Pay
  Timesheets
  Payroll                   Quality
                              Incidents   (unchanged)
Clients & money
  Bookings                  Clients & money
  ...                         ...         (Bookings removed)
```

Incidents stays under Quality. Safeguarding is not a daily operational rhythm
and folding it in would work against the point of the change.

### Routes and redirects

New routes: `/app/today`, `/app/rota`, `/app/pay`.

Every old route becomes a `<Navigate ... replace />`, the same pattern
`/app/timetable` already uses, so bookmarks, the Command-K palette and any
internal link keep working:

| Old route | Redirects to |
|---|---|
| `/app/field` | `/app/today` |
| `/app/bookings` | `/app/today` |
| `/app/scheduling` | `/app/rota` |
| `/app/jobs` | `/app/rota` |
| `/app/timesheets` | `/app/pay` |
| `/app/payroll` | `/app/pay` |
| `/app/timetable` | `/app/pay` (currently points at `/app/timesheets`) |

## The three surfaces

### Today (`/app/today`)

Four bands, most urgent first. Polls every 30 seconds and keeps the last good
read when a request fails, both of which `FieldOps.tsx` already does.

1. **Panic alerts.** Unacknowledged alerts only, with the Acknowledge control.
   Built already, moves across unchanged.
2. **Needs you.** Driven by `action-centre.php`, ranked by its `urgency` field,
   each row linking to the screen that fixes it. The endpoint exists; this is
   the first interface for it.
3. **Cover requests.** Pending rows from `bookings.php`, with Confirm (which
   creates an open shift) and Decline inline. Office roles only, gated inside
   the page.
4. **On site now.** The live board from `FieldOps.tsx`: who clocked in, who is
   late, who has not turned up.

### Rota (`/app/rota`)

The Scheduling page, kept as it is. The calendar, the shift dialog with
assign, unassign, cancel and broadcast, and the recurring templates all work and
are not being rebuilt. Three additions:

- **Ad-hoc work appears on the same calendar.** Jobs carrying a `scheduled_for`
  are drawn as events alongside rota shifts, styled distinctly so the two kinds
  stay legible. One calendar answers "what is happening", whatever produced it.
- **One New control, two honest choices:** "Shift" for rota work and "One-off
  job" for ad-hoc. Both lead to the dialogs that exist.
- **An Unscheduled list beside the calendar** for jobs with no date, because
  they cannot be placed on one.

### Pay (`/app/pay`)

One period selector at the top of the page, driving both halves. That is the
substance of the change: today a period is chosen on Timesheets and a different
one on Payroll, and nothing reconciles them.

1. **Hours.** The timesheets table for the chosen period: approve, reject with
   a reason, totals, CSV export.
2. **Pay run.** The payroll table for the same period: gross, premiums, holiday
   accrual, per-worker statements, CSV export.

Step 2 shows a warning when step 1 still has pending hours, naming the count,
because only approved hours reach `pay_compute()` and that exclusion is
currently silent.

## Data model

**No migration. No schema change.**

`jobs` and `shifts` stay separate tables. Ad-hoc and rota work are genuinely
different and merging the two spines is a larger bet than this redesign needs.
If convergence turns out to be right, it can follow later on evidence.

One server-side addition: `jobs.php` gains `from` and `to` date filters
alongside the `client_id` and `status` filters it already has, so the rota
calendar can fetch a month rather than a page. That is the only change under
`server/`.

Endpoints consumed, all already built:

| Surface | Endpoints |
|---|---|
| Today | `action-centre.php`, `field.php?action=live`, `bookings.php` |
| Rota | `scheduling.php`, `shift-templates.php`, `jobs.php`, `skills.php`, `clients.php` |
| Pay | `timesheets.php`, `payroll.php` |

## Files

The existing page bodies become components and the three pages compose them.
`Scheduling.tsx` is over 700 lines and `CLAUDE.md` warns that a file growing
large is usually a file doing too much. Logic moves; it is not rewritten.

```
src/components/operations/
  AttentionList.tsx      new      action-centre.php
  CoverRequests.tsx      moved    from Bookings.tsx
  LiveBoard.tsx          moved    from FieldOps.tsx
  RotaCalendar.tsx       moved    from Scheduling.tsx
  HoursTable.tsx         moved    from Timesheets.tsx
  PayRun.tsx             moved    from Payroll.tsx

src/pages/app/
  Today.tsx  Rota.tsx  Pay.tsx    compose the above
```

Deleted once their contents have moved: `FieldOps.tsx`, `Scheduling.tsx`,
`Jobs.tsx`, `Timesheets.tsx`, `Payroll.tsx`, `Bookings.tsx`.

Registries to update, both of which `CLAUDE.md` calls out as load-bearing:
`src/lib/app-routes.ts` (the page list behind the Command-K palette and the
browser tab title) and `src/components/app-sidebar.tsx` (its own tree, because
it groups differently).

## Access control

Merging pages forces each merged page to take a single role, and two of them
currently differ. Decisions taken:

| Surface | Roles | Change |
|---|---|---|
| Today | `STAFF` | As Field ops is now. The cover requests band is gated to office roles inside the page, because a trainer has no business reading client requests. |
| Rota | `OFFICE` | **A tightening.** The jobs board is readable by `STAFF` today, so trainer, editor and employee lose read access to it. Agreed deliberately: a work-order board names clients and sites, and a content role reading it was never right. |
| Pay | `OFFICE` plus the `payroll` zone | Unchanged. |

`src/lib/access.ts` is the single source for this and is read by both
`RequireAuth` and the sidebar, so a visible link always leads somewhere the user
may go. The server remains the enforced boundary; nothing here relaxes an
endpoint guard.

## Testing

- `src/lib/__tests__/access.test.ts`: the three new routes, every redirect
  target, and the Rota tightening pinned so it cannot silently widen again.
- `src/lib/__tests__/app-routes.test.ts`: palette entries for the new pages, and
  the absence of the old ones.
- `server/tests/run.php`: unchanged. No new pure logic is introduced. Ranking
  already lives in `action-centre.php`.
- Manual: the HTTP permission matrix re-run across the new routes for
  super_admin, admin, manager, trainer, editor, employee, a worker login and a
  client portal login.
- Manual: each redirect followed in a browser, confirming no 404 and no flash of
  the wrong page.

## Rollout and risk

Low risk, for three reasons. Every old URL redirects, so nothing that links in
breaks. `server/` changes by one filter, so the manual PHP upload stays small
(the deploy pipeline uploads `dist/` only and excludes `*.php`). The deleted
pages remain in git history.

The one behavioural change to communicate is the Rota tightening: three roles
lose read access to the jobs board.

## Out of scope

- Merging the `jobs` and `shifts` tables.
- Any change to `pay-lib.php` or the pay calculation.
- Rebuilding the calendar.
- Incidents, the Command Centre dashboard, the client portal, the worker app,
  and everything outside the Operations group.
- Turning clock events into timesheets automatically. Still worth doing, still
  a separate piece of work.

## Decisions taken during design

1. **Approach C** over collapsing everything into one tabbed page (too dense,
   hides things) and over renaming and reordering the existing five (does not
   answer "where do I start").
2. **Bookings folds into Today** rather than staying its own page under Clients
   and money.
3. **Rota is OFFICE**, accepting the tightening, rather than `STAFF` with the
   planning controls gated inside.
4. **Ad-hoc jobs live on the rota calendar** rather than keeping a separate
   board.
