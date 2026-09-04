# Privacy and legal readiness report

**Project:** RitaGreen OS (`~/WebstormProjects/ritagreen_os`, `devgeereact/ritagreen_os`)
**Date:** 4 September 2026 · **Branch:** `privacy/readiness` · **Baseline commit:** `84234a1`

Nothing in this report is legal advice, and nothing here says the project is compliant. It says
what the code does, what was changed, what was tested, and what somebody else now has to decide.

---

## Portfolio summary

One project inspected. The workspace holds eleven repositories; the other ten were out of scope
by agreement, and nothing here should be assumed to apply to them — different vendors, different
purposes, different users.

| | Count |
|---|---|
| Projects inspected | 1 |
| P0 issues | 4 — 3 fixed, 1 fixed in source and needing production verification |
| P1 issues | 12 — all 12 fixed |
| P2 issues | 14 — 10 fixed, 4 open |
| P3 issues | 5 — 2 fixed, 3 open |
| Technical fixes completed | 27 |
| Documents created | 5 |
| Documents rewritten | 3 |
| Owner decisions required | 11 |
| Items requiring legal review | 9 |

**Readiness: TECHNICALLY READY FOR LEGAL REVIEW**, with two owner facts blocking publication of
the privacy notice.

---

## Project assessment

**Business model, from the evidence.** A London workforce supplier across four divisions — care,
training, cleaning and security. It recruits and vets workers, trains them through its own
academy, places them with client organisations on shifts, and invoices for it. The software is
the whole operation: a public site that takes enquiries and applications, a CMS that runs
recruitment, compliance, scheduling and invoicing, a client portal, and a worker app used on a
phone during a shift.

**Users and jurisdiction.** Clients, workers, applicants and learners, in London and surrounding
boroughs. Terms are governed by the law of England and Wales. No evidence of users outside the
UK was found, and none was assumed.

**Why this is the highest-risk data set in the portfolio.** It holds right-to-work checks, DBS
status, SIA licence numbers, sickness absence with reasons and return-to-work notes,
safeguarding incidents with free-text detail, referees' contact details, identity-document
scans, pay, and the GPS position of workers during shifts.

| | |
|---|---|
| Data categories | 30, of which 5 are special category or criminal-offence |
| Collection points | 9 public, plus the CMS, portal and worker app |
| Storage | MySQL on cPanel; documents on the same host outside the webroot; gzipped full backups kept 14 days |
| Vendors | 12 possible; all but the host, Cloudflare and the mailbox are off in the deployed config |
| Browser storage | 1 cookie, 5 local-storage keys, 2 service-worker caches. No `sessionStorage`, no app IndexedDB |
| Marketing | None existed. Now: optional opt-in, with a working unsubscribe |
| Analytics | None. No GA, GTM, Sentry, pixels, session replay or heatmaps anywhere |
| Account deletion | No self-service, deliberately. A request pathway plus a four-eyes approval workflow |
| Retention | A mechanism now exists. Every period is unset |
| Legal documents | Privacy, terms and cookies existed; all three rewritten |

Full evidence: [`PRIVACY.md`](PRIVACY.md), [`COOKIES.md`](COOKIES.md), [`RETENTION.md`](RETENTION.md).

---

## Issues by priority

### P0

| # | Issue | Evidence | Status |
|---|---|---|---|
| P0-1 | `compliance.php?action=pack` sat above the file's first role check. **Any authenticated account — a client portal login, a worker's own login — could read any worker's whole vetting record** (identity, right to work, DBS status and expiry, SIA number) by changing `worker_id` | `server/compliance.php:22`, first `require_role` at `:183`. Reproduced: a seeded client account got 200 | **Fixed.** Staff plus the `compliance` zone. Client and worker logins now 403 |
| P0-2 | `incidents.php` GET had no role check. Safeguarding and RIDDOR records with free-text detail about named people were readable by any authenticated account, by id | `server/incidents.php:14`, first `require_role` at `:72`. Reproduced | **Fixed.** Office roles throughout, reads included |
| P0-3 | `references.php` GET had no role check. Referee name, employer, email and phone — a third party's details, given to us in confidence — readable by any authenticated account | `server/references.php:10`; the POST paths below already restricted. Reproduced | **Fixed.** Same roles as the writes |
| P0-4 | `schema.sql` seeded a `super_admin` whose password was written in plain text in the comment above its bcrypt hash, in a tracked file, for an account with access to every worker record | `server/schema.sql:44-49`, and `server/README.md:23` repeated it | **Fixed in source.** Seed removed, `create-admin.php` prompts for a password. **OWNER: check whether that account exists on the production database** |

### P1 — all fixed

| # | Issue | Evidence |
|---|---|---|
| P1-1 | `files.php` had no per-file authorisation. The endpoint allowed `editor`, so a content role could download or text-extract an identity scan or an employment contract by guessing an id — and extract feeds `ai.php`, which sends the text to a third-party model | `server/files.php:80-93`, `:99-141` |
| P1-2 | `notifications.php` returned every broadcast to every authenticated account: applicant names, cover-request contacts and detail, and panic alerts naming a worker | `server/notifications.php:28-33` |
| P1-3 | The cookie banner was theatre. `dismiss("declined")` and `dismiss("accepted")` ran identical code, nothing anywhere read the stored value, and once dismissed there was no way back to it | `src/components/public/CookieConsent.tsx` |
| P1-4 | Google Fonts loaded render-blocking on every page, sending every visitor's IP and user agent to Google before the page asked them anything, and disclosed in neither notice | `index.html:41-46` |
| P1-5 | The subject access export returned 5 collections keyed on an email column and omitted roughly twenty tables — every check, credential, contract, absence, shift, document and message is filed against `person_id` or `worker_id` | `server/gdpr.php:28-40` |
| P1-6 | Erasure bypassed the approval workflow entirely, erasing immediately with no reason and no second person, while deleting a single file needed sign-off | `server/gdpr.php:42-67` vs `server/governance.php` |
| P1-7 | No route existed for a person to ask for anything. The notice said "email us", which is a mailbox, not a process | `src/pages/public/Legal.tsx:61` |
| P1-8 | The notice claimed marketing was sent "with an opt-out". No opt-out existed anywhere in the codebase, and the two client-facing enquiry forms recorded no consent at all | `Legal.tsx:49`; `server/contact.php` had no `consent` |
| P1-9 | Workers' precise GPS was captured on clock-in, clock-out and panic with no notice, no explanation and no opt-out. A refusal was swallowed silently | `src/pages/worker/WorkerApp.tsx:21-30` |
| P1-10 | The privacy notice materially misdescribed the system: retention nothing could honour, an "Analytics (optional)" cookie category that did not exist, and not one actual processor named | `src/pages/public/Legal.tsx` |
| P1-11 | `can_view_zone()` was called in exactly one place. The `health` zone was never checked, so absence reasons and return-to-work notes were visible to any staff account, and DBS and right-to-work state to a content role | `server/lib.php:198-212`; only caller `workers.php:17` |
| P1-12 | No retention mechanism existed at all. `retention_policies` had eight record types and no consumer — a configured policy did nothing | `grep retain_months` found no reader |

### P2

| # | Issue | Status |
|---|---|---|
| P2-1 | The legal pages printed `new Date()` as "last updated", always claiming the current month | **Fixed** — written version and effective date |
| P2-2 | The pages carried a permanent banner telling visitors they were an unreviewed template with unconfirmed placeholders | **Fixed** — replaced by a one-flag review line |
| P2-3 | `Accreditations.tsx:68` claimed ICO registration; the notice called the reference an unconfirmed placeholder | **Fixed** — states the reference when set, describes real controls when not. **OWNER: supply it** |
| P2-4 | The `worker-reads` cache held a worker's compliance record for 12 hours after sign-out on a shared device | **Fixed** — purged on logout |
| P2-5 | The auth token had no client-side expiry, so it sat in local storage indefinitely | **Fixed** — 30 days, matching the server |
| P2-6 | `sidebar_state` had no `SameSite` and no `Secure` | **Fixed** |
| P2-7 | "Per the privacy policy" was inert text on `/apply`, `/request` and `/register` | **Fixed** — links, matching `/careers` |
| P2-8 | The CMS footer's legal links were raw anchors that reloaded the whole app; no Cookies link | **Fixed** |
| P2-9 | `/login`, `/register`, `/forgot`, `/reset` and `/offer` had no footer, no legal links and no notice | **Fixed** — `BareLayout` |
| P2-10 | A hard deletion removed the `files` row and left the document on disk under a random name | **Fixed** — the approved deletion unlinks it |
| P2-11 | `user_2fa.secret` is stored unencrypted | **Open.** Database access already implies compromise, but it makes the second factor forgeable |
| P2-12 | `certificates.php?action=verify` allows enumeration of learner names by certificate id — the file says so itself | **Open.** Rate limited at 30 per 10 minutes. **OWNER: is a public verification service intended to be enumerable?** |
| P2-13 | `employment_contracts.access_token` grants contract access with no login | **Open. OWNER/LEGAL: confirm intended** |
| P2-14 | Registered office is not published anywhere | **Open. OWNER** |

### P3

| # | Issue | Status |
|---|---|---|
| P3-1 | Company number rendered three different ways | **Fixed** |
| P3-2 | The `files.php`, `incidents.php`, `compliance.php` and `gdpr.php` header comments misdescribed their own code | **Fixed** at source |
| P3-3 | Exception messages reach `error_log` and can contain personal data | **Open** |
| P3-4 | `x.com/ritagreenservices__` in the footer returns 404 | **Open. OWNER.** Instagram, Facebook and LinkedIn return 200, which is not proof the profile exists behind a login wall |
| P3-5 | £5m insurance asserted in three places while the terms decline to state a figure | **Open. OWNER** |

---

## Fixes completed

Four commits on `privacy/readiness`.

### 1. Authorisation (`f3fbbc3`)

**Problem.** Six read paths returned personal data — three of them special category — after
`require_auth()` and no further check.

**Root cause.** Role checks were written for the write paths, where the risk felt obvious, and
the read paths were left on the assumption that being signed in was enough. `users.role` includes
`client`, and worker logins are ordinary accounts, so "signed in" included the two groups the
data was most sensitive to.

**Solution.** `require_staff()` and `require_role()` on the four unguarded paths;
`rg_file_readable()` for per-file authorisation in `files.php`; `can_view_zone()` finally
enforced for `compliance` and `health`. `src/lib/access.ts` moved with the server so a visible
link still leads somewhere the user may go.

**Changed.** `server/compliance.php`, `references.php`, `incidents.php`, `notifications.php`,
`files.php`, `workers.php`, `leave.php`, `pure.php`, `field.php`, `schema.sql`, `README.md`, new
`create-admin.php`; `src/lib/access.ts` and its tests.

**Verified.** Signed in as each of six seeded roles against a local database and requested each
endpoint. Client and worker logins: 403 on all four. Editor: 403 on the compliance pack,
incidents, references and personal file downloads, and an empty file listing where a manager sees
twelve. Manager and admin unchanged. 20 new PHP assertions.

### 2. Third parties, consent, marketing, rights (`f789556`)

**Problem.** The site's only third-party request was undisclosed and fired before any notice;
the consent control did nothing; marketing was claimed and unbuilt; there was no route for a
person to ask for anything.

**Root cause.** Each is the same shape — a thing described but not built, or built but not
described.

**Solution.** Self-hosted the three font families and removed both Google origins from the CSP
and the service worker. Rebuilt the notice as an honest, versioned, reopenable, keyboard-usable
notice rather than a fake choice. Built marketing consent on the existing `consent_log` with an
HMAC unsubscribe link that needs no login and no token table. Added `/data-request` and
`data-request.php`.

**Changed.** `index.html`, `public/.htaccess`, `vite.config.ts`, new `src/fonts.css` and
`public/fonts/` (20 files), `CookieConsent.tsx`, `PublicFooter.tsx`, `layouts/Footer.tsx`,
`sidebar.tsx`, `Contact.tsx`, `Quote.tsx`, `Apply.tsx`, `RequestCover.tsx`, `Register.tsx`,
new `DataRequest.tsx`, `routes/index.tsx`; `server/lib.php`, `pure.php`, `contact.php`,
`config.sample.php`, new `unsubscribe.php` and `data-request.php`; migration 020.

**Verified.** A cold load in a real browser requests nothing but the site's own origin, and the
production build contains no external origin. The notice appears with cleared storage, persists
across a reload, reopens from the footer with focus moved to it, closes on Escape with focus
returned, and re-prompts when the version changes — no console errors, no horizontal overflow at
375 px or 1280 px. `DSR-0001` and `DSR-0002` created, both mails queued and none sent, client
and manager refused the queue. Opt-in recorded; a GET on the unsubscribe link left consent
intact; POST revoked it; a token for another address returned 400; an address never asked reads
as not allowed.

### 3. Documents, export, retention (`fe74873`)

**Problem.** The privacy notice described a different system. The export answered a subject
access request with a fraction of the record. Nothing disposed of anything.

**Root cause.** For the export, resolving identity by email address alone: `people.email` is
routinely empty because the row came from an application that only carried the address on the
applicant record.

**Solution.** Rewrote all three documents from the verified data map with written versions and
effective dates. Rebuilt the export to resolve through the `people` spine in both directions and
cover 27 collections, naming what it omits. Routed erasure through the four-eyes workflow and
stopped it deleting consent evidence. Built `retention-sweep.php`.

**Changed.** `src/pages/public/Legal.tsx`, `company.ts`, `Accreditations.tsx`,
`WorkerApp.tsx`, new `LocationNotice.tsx`, `api.ts`, `auth-provider.tsx`; `server/gdpr.php`, new
`retention-sweep.php`, `pure.php`, `tests/run.php`.

**Verified.** The same request went from 8 records to 15 on the fixture, gaining the
right-to-work check, the verification, the eligibility verdict, the absence record and the
documents, and wrote a row to `export_log`. Erasure kept the marketing suppression row and
marketing stayed blocked afterwards. The sweep reported and changed nothing with all periods
NULL; with one period set it reported one row due and changed nothing, and only `--go` archived
it and wrote the audit entry. 20 further assertions.

### 4. Deletion and the auth pages (`d2dfbbc`)

**Verified.** Uploaded a file as a manager, requested deletion with a reason, was refused when
attempting to decide it, approved as an admin: row gone, 20 bytes gone, two audit entries. All
three auth pages carry the privacy and data-request links, their forms still render, the
shift-offer page still works, console clean.

---

## Consent status

**What exists.** A storage notice on the marketing site and, now, on the unauthenticated pages
outside it. It is a notice and not a consent gate, because the site loads nothing optional and
stores nothing optional — offering a choice with no effect is what the previous version did.

**What it controls.** Nothing, and it says so. That is the honest position once the fonts are
self-hosted. It carries a version so that adding an optional category re-asks rather than
inheriting an old acknowledgement, and the code that would become a real consent control is
documented in place.

**Accessibility.** Keyboard reachable; visible focus rings on every control; Escape closes;
focus moves to the notice only when the visitor opened it themselves and returns to the trigger
afterwards; not modal and no focus trap, because a barrier in front of information nobody had to
act on is its own dark pattern; no colour or hierarchy advantage between actions; no horizontal
overflow at 375 px.

**Runtime verification.** Cleared all browser state, loaded headless, and exercised first visit,
acknowledgement, reload, reopen, Escape, focus return, and a version change. Network log for a
cold load: `localhost:5122` only.

**Remaining risk.** The whole design rests on there being no third-party request and no optional
storage. Adding one silently makes the notice wrong. `CLAUDE.md` now says so as a standing trap.

---

## Marketing status

Optional, unticked, separately worded, on `/contact` and `/quote`. The enquiry is answered either
way. Consent is recorded in `consent_log` with the source and IP; an opt-out is a new row with
`granted = 0`, never a deletion, because erasing the record that somebody unsubscribed is how
they get signed up again by the next import. `marketing_allowed()` reads the latest row and
treats absence as no.

`unsubscribe.php` works from an email client with no login, authenticated by an HMAC over the
address, so there is no token table and no way to guess a link for somebody else. GET renders the
page; POST performs the opt-out, so link previewers cannot unsubscribe people who never clicked.
With `APP_SECRET` unset, `unsubscribe_link()` returns null and marketing is not sent at all
rather than sent with a broken unsubscribe.

Connected systems: none. Nothing here syncs to an external mailing platform. **No email was sent
during testing** — `MAIL_ENABLED` is false and every assertion read `email_queue`.

The four required consent checkboxes on the applicant-facing forms were left alone: see the legal
review list.

---

## Analytics status

There are none, and this was checked rather than assumed. No Google Analytics, Tag Manager,
gtag, Plausible, PostHog, Matomo, Segment, Hotjar, FullStory or Clarity; no Sentry or any error
SDK; no advertising pixel; no session replay or heatmap; no tag manager; no server-side tracking.
The CSP is `script-src 'self'` and `connect-src 'self'`, so the bundle cannot reach a third party
even if one were added by mistake. Nothing was connected, published or altered.

---

## Data rights and account deletion

| Pathway | State |
|---|---|
| Make a request | `/data-request` → `data_requests`, with a reference and an acknowledgement |
| Access | `gdpr.php?action=export`, 27 collections, logged in `export_log` |
| Correction | Staff-mediated. No self-service correction exists |
| Deletion | `gdpr.php?action=erase` for public-form data; everything else through the four-eyes workflow |
| Objection to marketing | The unsubscribe link, and it survives erasure |
| Portability | The export is JSON. **LEGAL: whether it satisfies portability as a format** |

**Self-service account deletion was not built, deliberately.** Accounts here belong to workers
and clients whose records carry employment and safeguarding retention duties. A delete button on
a worker account would be the wrong answer, and the rules that decide what can actually go are
not documented anywhere. The request pathway plus human approval is the correct shape. This is a
decision, not an omission.

**Security of the pathway.** The form collects the minimum, asks for no identity documents, and
says so before anything is filled in. Verification happens afterwards over the address given.
Closing a request as completed or refused requires a note. Reading the queue is itself audited.
No statutory deadline is written into the code.

**Limitations.** Backups are full dumps that cannot be edited per person; an erasure takes effect
in them by ageing out over 14 days, and the notice says so.

---

## Authoritative sources consulted

Fetched and confirmed to resolve on 4 September 2026.

| Source | Authority | URL | Supports |
|---|---|---|---|
| Employment information | ICO | `https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/employment/` | The employment-record and worker-monitoring framing throughout |
| Guidance on the use of storage and access technologies | ICO | `https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/guidance-on-the-use-of-storage-and-access-technologies/` | The cookie and storage inventory, and the strictly-necessary reading |
| Right to be informed | ICO | `https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/individual-rights/individual-rights/right-to-be-informed/` | What the privacy notice has to cover |
| Automated decision-making, including profiling | ICO | `https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/individual-rights/automated-decision-making-and-profiling/` | Flagging the eligibility engine for review |
| International transfers | ICO | `https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/international-transfers/` | Why the transfer mechanism is left to counsel |
| Direct marketing and privacy and electronic communications | ICO | `https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/` | Opt-in, unsubscribe and suppression |
| Right to work checks: an employer's guide | Home Office / GOV.UK | `https://www.gov.uk/government/publications/right-to-work-checks-employers-guide` | Right-to-work as a dated, evidenced check |
| Disclosure and Barring Service | DBS / GOV.UK | `https://www.gov.uk/government/organisations/disclosure-and-barring-service` | DBS handling and rechecking |
| Holiday entitlement | Acas | `https://www.acas.org.uk/holiday-entitlement` | Absence and holiday records |

**Not fetched:** SIA and CQC sector guidance, and the specific retention periods in any of the
above. Conclusions that would depend on them are marked as requiring legal verification, and no
period was taken from any of these pages into the schedule. The distinction between statutory
requirement, regulator guidance and good practice was not adjudicated here — that is counsel's.

---

## Owner input required

1. **The full registered office address** for company 12528439. Blocks publication of the notice.
2. **The ICO registration reference.** The Accreditations page already tells the public that
   records are handled with ICO registration.
3. **Whether `dev@ritagreenservices.com` exists on the production database**, and if so remove it
   or change its password. Its old password is in the repository's history.
4. **What marketing is actually sent, to whom, and how often.** The mechanism is built; the
   notice describes it in general terms.
5. **Which integrations are live in production.** All are `CHANGE_ME` in the deployed config, so
   the vendor list in the notice may currently overstate what happens.
6. **Whether care records are held in this system at all**, or only in a client's own system.
   The notice says they are; no dedicated table exists.
7. **Whether a data protection officer or representative is named.** None appears anywhere.
8. **Whether `hello@` should remain the privacy contact** or a dedicated address is created.
9. **Whether the £5m insurance figure is current**, given the terms decline to state one.
10. **Whether `x.com/ritagreenservices__` should be removed** from the footer; it returns 404.
11. **Whether the login-free contract access token and the public certificate lookup are
    intended** as they behave today.

## Legal review required

1. **Every retention period.** Fourteen record types, all NULL. Nothing is deleted until they
   are set.
2. **The transfer mechanism** for each processor outside the UK — Twilio, Stripe, Google and
   OpenRouter — which the notice deliberately does not name.
3. **Whether the eligibility engine engages Article 22.** It automatically refuses a person work,
   re-runs daily with nobody present, and takes approved sickness absence as an input.
4. **Whether consent is the right basis on the application forms.** `/apply`, `/careers`,
   `/request` and `/register` present a required consent checkbox where contract or legitimate
   interests is the more usual analysis — and consent that cannot be refused without losing the
   thing you came for is not freely given. Left as it stands, and flagged rather than changed.
5. **Whether the Google Forms arrangement needs a processor agreement**, given the primary copy
   of an application lives there.
6. **Whether the JSON export satisfies portability** as a format.
7. **The commercial terms**: the 50% late-cancellation charge, the six-month non-solicitation,
   the liability cap, and the complaints timescales.
8. **Whether the public certificate verification service** is an appropriate disclosure of a
   learner's name.
9. **Whether the worker location processing is proportionate**, and whether it needs an
   employer's monitoring assessment on top of the notice now shown.

---

## Remaining risks

**Technical.** `user_2fa.secret` is unencrypted at rest. Exception messages can reach `error_log`
with personal data in them. The certificate lookup is enumerable. A contract can be opened with a
token and no login. None is an active exposure; each is a way one would get worse.

**Operational.** Every migration, `001` to `020`, is unapplied in production, so none of these
fixes is live until somebody applies them deliberately. Backup restores have never been tested —
the system health check reports that as an explicit unknown rather than assuming it is fine.

**Vendor.** Twelve possible processors, all but three off in the deployed config. If any is
switched on without the notice being updated, the notice becomes wrong the same day.

**Documentation.** The notice describes the retention mechanism honestly, which means it will
describe the periods once they exist. Setting a period without revisiting the notice would leave
it accurate but incomplete.

**Legal.** The nine items above. The largest is Article 22: this system automatically stops a
person working.

---

## Readiness decision

### TECHNICALLY READY FOR LEGAL REVIEW

Every P0 and P1 issue is fixed and tested. The documents describe what the code does. What
remains is two facts the owner holds, and nine questions for a solicitor.

---

## Before publication or launch

- [ ] Supply the registered office address and the ICO reference; set them in `src/lib/company.ts`
- [ ] Check the production database for `dev@ritagreenservices.com` and remove or reset it
- [ ] Confirm which integrations are live, and correct the recipient list if any is not
- [ ] Confirm what marketing is sent, and set `APP_SECRET` on the server — without it, no
      marketing email can carry a working unsubscribe and none should be sent
- [ ] Have a UK solicitor review all three documents and the nine questions above
- [ ] Set the retention periods, then run `php server/retention-sweep.php` and read the report
      before ever running it with `--go`
- [ ] Apply migrations `001` to `020` to production, deliberately, with a backup taken first
- [ ] Deploy the PHP tier by hand — the GitHub Actions workflow ships `dist/` only and excludes
      `*.php`
- [ ] Set `LEGAL_REVIEW_PENDING = false` in `src/lib/company.ts` once the review is done
- [ ] Re-read `docs/PRIVACY.md` and confirm it still matches the system before you rely on it
