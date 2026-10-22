# Roadmap

What remains after the 2026 build (sections S0 to S10). Everything on this page is **not yet
done**; what is built is described in [`docs/BLUEPRINT.md`](BLUEPRINT.md), Part 2, and tracked
item by item in [`docs/STATUS.json`](STATUS.json), rendered as [`docs/PROGRESS.md`](PROGRESS.md).
The decisions behind the build are in [`docs/DECISIONS-2026.md`](DECISIONS-2026.md).

The milestones this file used to list (person spine, compliance engine, verification,
deployment gate, permissions and zones, employment records, communications, intake, governance
and the PWA) are built. Their history is in [`docs/CHANGELOG.md`](CHANGELOG.md); the few items
still partial are in `docs/PROGRESS.md`.

---

## 1. Release to production

The 2026 build is on the build branch and has not been deployed. Merging it into `main` is the
owner's decision, and the steps (backup and restore proof, PHP upload, migrations, deleting the
retired PHP files on the host, the `.htaccess` blocks, cron, owner actions after the deploy,
rollback) are in [`docs/runbooks/release-2026.md`](runbooks/release-2026.md). Which migrations
the live database still needs is answered by `php server/migrate.php --status` on the server,
not by any document.

## 2. Facts the owner must confirm

Nothing below is published until confirmed. Most are entered in the office app, Website,
Company details (`/app/company`); `src/lib/company.ts` is the fallback.

| Fact | State in the code |
|---|---|
| Registered office address | Empty in `src/lib/company.ts`, left out of the footer |
| ICO registration reference | Empty in `src/lib/company.ts` |
| Urgent cover line `+44 (0)7377 563859` | In `src/lib/company.ts`, to confirm |
| `hello@ritagreenservices.com` is a live mailbox | To confirm |
| Office hours, Monday to Friday 9am to 5pm | To confirm |
| Area: London and surrounding boroughs, based in south-east London | To confirm |
| CQC registration for supported living or home care | Not established; the website says "talk to us" and makes no regulated claim |
| Current scope of supported living and home care | To confirm |
| Training course list | To confirm |
| Realistic response times on the service pages | To confirm |
| Pay per role on vacancies | Entered per vacancy when known |
| Company size (LinkedIn) and the "Home health care service" category (Google Business Profile) | To confirm before the social profiles are updated by the owner |
| Retention period for unsuccessful applicants | See section 6 |

## 3. Website: photography, logo, forms and the live server

- **Real photography.** The homepage hero and the service pages use stock photographs as
  placeholders, listed in `src/lib/site-images.ts` and never captioned as RitaGreen staff or
  clients. A consented shoot (staff in uniform, a training session, the office, a cleaning team,
  an officer on site) replaces them.
- **Logo.** The mark is drawn in code (`src/components/brand/Logo.tsx`, `Wordmark.tsx`). A
  professionally designed logo is a separate owner decision.
- **A real Google Form end to end.** The Apps Script in
  [`docs/runbooks/google-forms-intake.md`](runbooks/google-forms-intake.md) has not been run
  inside Google Apps Script, and no real form has been embedded and submitted through to
  Recruitment. The payload and secret path are covered by the database tests only.
- **An Apache test of `public/.htaccess`.** Development and the test suites run on the PHP
  built-in server, which ignores `.htaccess`. Only the 301 rules are checked, against the
  redirect map, by a test that reads the file's text
  (`src/lib/__tests__/public-routes.test.ts`). Nothing has exercised under Apache the 301
  redirects from the old paths, the CSP `frame-src` line for Google Forms, or the 403 for the
  CLI-only tools (`migrate.php`, `seed.php`, `restore-check.php` and the others). Check them on
  the host, or a local Apache, as part of the release.
- **The old domain.** `ritagreenhealthcareservices.com` redirects page by page once the owner
  confirms control of it.
- **Social profiles and the Google Business Profile** are updated by the owner, never by an
  agent, once the facts in section 2 are confirmed.

## 4. Pilot and independent testing

- **Staff pilot.** The office and a small group of workers use the office app and the staff
  portal for real before everyone moves to it. Not yet run.
- **Independent security testing.** All security review so far has been internal. An external
  test of the API, authentication, service scope and the public forms is outstanding.
- **What the browser journeys did not cover** (real email and SMS, geolocation on a real phone,
  offline use, screen readers and keyboard-only use, browsers other than Chromium) is listed in
  [`docs/TESTING.md`](TESTING.md), "2026 launch journeys".

## 5. Phase 6: Academy integration

Deferred. Linking training records to an external training provider needs a confirmed provider,
and there is none. Training stays a record and enrolment module (`docs/PRD.md`).

## 6. Retention periods

Every period in `retention_policies` is NULL, by design. `server/retention-sweep.php` reports
and, with `--go`, archives only where a period is set, and never hard-deletes. Setting the
periods is the owner's decision with legal advice. See [`docs/RETENTION.md`](RETENTION.md).

## 7. Remove the 2026 plan pack

`docs/Plans 2026/` has been folded into the permanent documents and is kept only until the owner
approves deleting exactly that folder.

## 8. Later, if wanted

Proposals only, none started:

- Policy acknowledgement (upload a policy, staff confirm they have read it).
- A cleaning job checklist.
- The client portal's future (it is restricted until that is decided).
- SMS and WhatsApp for shift notices, switched on per message type.
- A workflow rule engine ("when X, do Y"); every automation is bespoke code today.

## Deliberately not on this roadmap

The non-goals in [`docs/PRD.md`](PRD.md) §6 stay non-goals.
