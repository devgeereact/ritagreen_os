# Release runbook: the 2026 build to production

The steps to take the build branch `lean-cms/build` (pull request #8) to production. Written
in S10 on 10 October 2026. **Nothing here has been run against production.** Merging #8 is the
owner's decision and is the step that deploys the frontend; everything on the server is done
by hand, in this order.

Who: one person at the keyboard (the release lead) and the owner available for the owner
actions and the go/no-go. Allow two hours, out of office hours, with the office told.

## How to release: `npm run release` (from this Mac, over SSH)

Kit standard since 30 October 2026: no FTP, no GitHub Actions deploys. `.github/workflows/deploy.yml` is manual only and is removed, with its FTP secrets and the cPanel FTP account, after the first successful release below.

1. `npm run release` (dry run): `main` clean and pushed, the checks, the build with the live public Clerk key, then a checksum list of every file that would change in `~/ritagreenservices.com`: `dist/` and `server/` with `migrations/` and `schema.sql` (without tests, uploads and Markdown). Nothing changes.
2. `npm run release -- --go`: copies every server file about to change to `~/backups/ritagreen_os/releases/<time>/` (newest 3 kept), uploads (entry files last, nothing deleted; `config.php`, `.htaccess`, `.env*`, dumps never), then smoke-tests `/` (200), `/auth.php?action=me` and `/api.php` (401); a failure restores the copies automatically.
3. Undo: `npm run release -- --rollback --go`. Database changes stay a separate, backed-up step.

## 0. Before the day

- [ ] CI is green on the exact commit of #8 that will be merged (typecheck, lint, `npm test`,
      build with no source maps, `php -l`, `test:php`, `test:db` on MariaDB, docs check).
- [ ] The release lead has read this file, `docs/runbooks/backup-and-restore.md` and the
      `[Unreleased]` section of `docs/CHANGELOG.md`.
- [ ] The file list below is regenerated from the final branch, in case anything merged after
      this was written:
      ```bash
      git fetch origin
      git diff --name-only --diff-filter=AM origin/main...origin/lean-cms/build -- 'server/*.php'
      git diff --name-only --diff-filter=D  origin/main...origin/lean-cms/build -- 'server/*.php'
      git diff --name-only origin/main...origin/lean-cms/build -- server/migrations/
      ```
- [ ] SSH or cPanel Terminal access to the host works, and `php -v` there is 8.x with
      `mysqli`.

## 1. Back up, and prove the backup restores

- [ ] `php ~/ritagreenservices.com/backup.php` and note the dump name it prints.
- [ ] Copy the current server PHP aside, so any file can be put back in seconds:
      `mkdir -p ~/private_backups/php-predeploy-2026 && cp -a ~/ritagreenservices.com/*.php ~/ritagreenservices.com/.htaccess ~/private_backups/php-predeploy-2026/`
- [ ] Rehearse a restore of that dump into a separate database, as in
      `docs/runbooks/backup-and-restore.md`. `restore-check.php` is not on the server yet,
      so compare with `SELECT COUNT(*)` on `people`, `workers`, `applicants`, `files`,
      `timesheets` and `audit_log` in both databases, and download one document. Record the
      figures here. Do not go on until it matches.

## 2. What the database needs

- [ ] `php ~/ritagreenservices.com/migrate.php --status` and record the output. The last
      deployment record (2026-09-03) says 19 applied, 0 pending; trust what this prints, not
      that record.
- [ ] Set `RG_ENV` in the live `config.php` (it has no such line yet):
      `define('RG_ENV', 'production');` near the top. Without it the CLI tools treat the
      database as production anyway, but say so explicitly. Never write `'local'` there.
- [ ] Add `define('APP_SECRET', '<64 random hex characters>');` to the live `config.php`
      (`php -r "echo bin2hex(random_bytes(32));"`). It signs unsubscribe links. Keep it in the
      owner's password manager.
- [ ] Remove the retired `OR_*` (OpenRouter) and `OPENWEATHER_*` lines from the live
      `config.php` once step 4 has deleted the files that used them. Revoke those keys at the
      providers afterwards.

## 3. Upload the PHP, then migrate

Upload first, then migrate: the new endpoints read the new tables, and the old endpoints keep
working against a migrated database because every migration is additive.

Upload these 77 files to the document root, beside `index.html` (generated from
`git diff --name-only --diff-filter=AM origin/main...origin/lean-cms/build -- 'server/*.php'`
on 10 October 2026, plus S10's `restore-check.php`; `config.sample.php` is not uploaded):

```
academy.php action-centre.php api.php ats.php backfill-payroll.php backfill-people.php
backup.php blog.php bookings.php clients.php comms.php company.php compliance-lib.php
compliance.php contact.php contracts.php create-admin.php credit-notes.php dashboard.php
data-request.php employment-contracts.php enquiries.php expiry-alerts.php features.php
field.php files-lib.php files.php forms-intake.php gdpr.php gocardless.php governance.php
inbox.php incidents.php insurance.php invoices.php jobs.php leave.php lib.php me.php
migrate.php notifications.php offer.php onboarding-templates.php pay-lib.php payments.php
payroll.php people-lib.php people.php portal-lib.php portal.php pure.php quotes.php
references.php report-activation.php reports.php requests.php restore-check.php
retention-sweep.php right-to-work.php rota-lib.php scheduling.php seed.php
send-queued-mail.php sheet-import.php shift-templates.php sitemap.php sites.php skills.php
system-health.php testimonials.php timesheets.php unsubscribe.php users.php vacancies.php
verifications.php website-forms.php workers.php
```

Unchanged since `main`, leave as they are: `audit.php`, `auth.php`, `certificates.php`,
`margin.php`. Also upload `schema.sql` and the `migrations/` folder (020 to 043; there is no
040 or 041, by design).

- [ ] After uploading, on the host: `for f in *.php; do php -l "$f" >/dev/null || echo "BAD $f"; done`
      prints nothing.
- [ ] `php migrate.php --status`: 020, 021, ... 039, 042, 043 pending.
- [ ] `php report-activation.php > ~/activation-before.txt` (read-only; who would be blocked).
- [ ] `php migrate.php` (dry run) and read what it will apply.
- [ ] `php migrate.php --go --production`, then type the database name when asked.
- [ ] `php migrate.php --status`: nothing pending.
- [ ] `php backfill-payroll.php` (report), then `php backfill-payroll.php --go` if it lists
      workers without a payroll number. It never renumbers anyone.
- [ ] `php backfill-people.php` (report). Expect nothing to do; `--go` only if it lists rows.
- [ ] `php report-activation.php > ~/activation-after.txt` and compare. Migration 034
      backfills approval for everyone already active, so nobody active should newly appear as
      blocked. If somebody does, find out why before going on.

## 4. Delete the retired files on the host

The S9 retirements removed these endpoints from the code. The deploy never touches PHP, so
they are still on the server and still answer:

- [ ] `rm ~/ritagreenservices.com/ai.php ~/ritagreenservices.com/weather.php ~/ritagreenservices.com/analytics.php`
- [ ] Confirm each now answers 404 (or the SPA page), not JSON.

No other file was deleted from `server/` between `main` and the build branch.

## 5. `.htaccess`, by hand

The live `.htaccess` is authoritative (it carries the Cloudflare origin lock). **Merge** these
three changes from `public/.htaccess` into it; never overwrite it.

- [ ] The block refusing server-side files beside `index.html`:
      ```
      RewriteRule ^(migrations|tests)(/|$) - [F,L]
      RewriteRule \.(sql|md)$ - [F,NC,L]
      RewriteRule ^(migrate|seed|backfill-people|backfill-payroll|create-admin|report-activation|restore-check)\.php$ - [F,NC,L]
      ```
      next to the existing `^uploads/` rule.
- [ ] The S6 block of 301 redirects from the previous website's addresses (`services/...`,
      `sectors`, `accreditations`, `resources`, `apply`, `quote?service=...`), copied whole.
- [ ] The Content-Security-Policy line, replacing the old one: it adds
      `frame-src 'self' https://docs.google.com` (embedded Google Forms after a click) and drops
      the Google Fonts origins (fonts are self-hosted).
- [ ] Re-check the origin lock: a direct-to-origin request still answers 403.

## 6. Cron

`cron_runs` (migration 043) now records each job, and Settings, System health shows when each
last succeeded.

| Job | Command | When | Change |
|---|---|---|---|
| Backup | `php ~/ritagreenservices.com/backup.php` | daily, 02:00 | existing |
| Expiry alerts and eligibility | `php ~/ritagreenservices.com/expiry-alerts.php` | daily, 03:00 | existing |
| Send queued mail | `php ~/ritagreenservices.com/send-queued-mail.php` | every 5 to 15 minutes, at least hourly | existing |
| Retention sweep (report only) | `php ~/ritagreenservices.com/retention-sweep.php` | weekly, Sunday 04:00 | **new**; reports only without `--go`, and every period is NULL until the owner sets one |

- [ ] Any crontab still calling a job over HTTP with `?key=` moves to the CLI form above, or to
      the `X-Cron-Key` header (`server/README.md`).

## 7. Merge, which deploys the frontend

- [ ] Owner gives the go.
- [ ] Merge #8 into `main` on GitHub. CI runs on `main`; `deploy.yml` uploads `dist/` only when
      that run succeeds.
- [ ] Watch the Actions run to the end. If CI fails, nothing is uploaded: fix forward on a
      branch, never by pushing to `main` directly.

## 8. Owner actions, after the deploy

- [ ] **Company details** (`/app/company`): check phone, email, address, hours, company and
      registration numbers and social links against the real ones. Anything marked `[confirm]`
      in the plan stays unpublished until confirmed.
- [ ] **Feature switches** (Settings, Features): review each. Seeds keep production behaviour
      (finance, online payments, SMS and the inbox on), which is safe but not the plan's
      recommendation; switch off what the office does not use.
- [ ] **Rota rules** (Settings, Features): confirm the minimum rest (default 11 hours).
- [ ] **Form intake secret** (Settings, Form intake): set a secret for each Google Form, then
      install the Apps Script in each form as in `docs/runbooks/google-forms-intake.md`, and
      send one real test response.
- [ ] **Forms directory and vacancies** (`/app/website-forms`, `/app/vacancies`): add the real
      form ids, link each role to its form, publish roles.
- [ ] **Users**: deactivate anybody who has left; give each worker a login linked to their
      worker record if they will use the staff app.
- [ ] Name the owner of the restore rehearsal and of each failure queue (email queue, form
      intake, data requests).

## 9. Checks after release

- [ ] `https://ritagreenservices.com/`, `/healthcare`, `/careers`, `/training`, `/contact`
      load; an old address such as `/services/concierge` answers 301.
- [ ] `/schema.sql`, `/README.md`, `/migrations/001_people_spine.sql`, `/migrate.php` and
      `/restore-check.php` answer 403. `/ai.php` does not answer JSON.
- [ ] Browser console on the home page: no request to any origin but the site's own.
- [ ] Sign in as the owner: Today loads; Settings, System health shows the database, cron jobs
      (a job that has not run yet says unknown until its first run), backups and storage.
- [ ] Open three person profiles and one document each.
- [ ] Rota: the current week shows; publish nothing as a test on the live system.
- [ ] A worker signs in to `/m` on a phone and sees their shifts.
- [ ] Submit the public application form once with a test name and the office's own address,
      then archive it through Settings, Data requests.
- [ ] Next morning: System health shows backup and expiry alerts succeeded overnight.

## 10. Rollback

Decide within the first hour. The database changes are additive, so rolling back the code does
not require rolling back the database.

- **Frontend:** revert the merge commit on a branch, open a pull request, merge it; CI deploys
  the previous `dist/`. Do not push to `main` directly.
- **PHP:** copy the files back from `~/private_backups/php-predeploy-2026/`. The old code ignores
  the new tables and columns. Put back `ai.php`, `weather.php` and `analytics.php` only if the
  old frontend is also back.
- **`.htaccess`:** restore the saved copy from the same folder.
- **Database:** only if data is damaged, restore the dump from step 1 (backup runbook,
  "Real restore after a loss"). Everything written since then is lost, so this is a last resort
  and the owner's call.

Record what was done, with times and the `--status` output, as a dated "Deployed" entry in
`docs/CHANGELOG.md`.


## Clerk sign-in (added 2026-10-30, not yet run)

Read-only findings over `ssh cpanel` on 30 October 2026 (nothing changed): the live site is the 3 to 4 September build. `lib.php` and `auth.php` are dated 2026-09-03, migrations 001 to 019 are applied and 20 to 44 are not (23 migration files in the repo are not applied), 23 server PHP files in the repo are missing on the server (for example `people.php`, `people-lib.php`, `portal-lib.php`, `files-lib.php`, `features.php`, `company.php`, `employment-contracts.php`, `onboarding-templates.php`), `config.php` is mode 644 and has no Clerk keys, and PHP is 8.4.27. Live data: 8 users, 8 workers, 6 clients, 15 people. So the Clerk sign-in cannot go up alone: the new app needs the whole newer server and database, and the migrations run on real employment records. That is one full release, not a Clerk deploy; take a database backup first.

Additive. The password sign-in keeps working until the owner confirms it can go. In order, each step approved by the owner:

1. Backup (the owner may waive it; say so in the handoff), then add `CLERK_ISSUER` (`https://clerk.ritagreenservices.com`) and `CLERK_AUTHORIZED_PARTIES` to the server `config.php` (see `config.sample.php`).
2. `php migrate.php --status`, then apply migration 044 (adds `users.clerk_user_id`; read the dry run first).
3. Upload `server/clerk-lib.php`, then `server/lib.php`. Never `lib.php` first.
4. **Merge** `public/.htaccess` into the live one; never overwrite it (the live file carries the Cloudflare origin lock). Add the file-refusal block and the old-address redirects after the `uploads/` rule and replace the Content-Security-Policy line (it names the Clerk hosts; without it the sign-in form is blocked). Upload the merged file immediately before `dist/`, because the old policy allows icon-font origins the new app no longer uses.
5. Build with `VITE_CLERK_PUBLISHABLE_KEY` set to the live key and upload `dist/` with `--exclude .htaccess` (Vite copies `public/.htaccess` into `dist/`, which would overwrite the live file).
6. Every person who should sign in needs an active `users` row with their email and a Clerk user in the production instance; then restrict sign-up in Clerk.
7. Check: one Clerk sign-in per role (office, worker `/m`, client `/portal`), an unknown email shows "No access yet", a bad token gets 401, the public site loads no Clerk script.

Rollback: restore the previous `lib.php`, `.htaccess` and `dist/`; the new column is harmless.
