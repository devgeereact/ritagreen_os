# RitaGreenOS — server API

PHP endpoints for RitaGreenOS. React never touches the database; it calls these over
HTTP and gets JSON back. Each file begins with `require __DIR__ . '/lib.php';`,
which loads `config.php` (the `$conn` mysqli connection) and sets up CORS, JSON
helpers and token auth.

## What deploys vs what you place by hand

- The **React build** (`dist/`) is uploaded automatically by GitHub Actions on
  push. `.htaccess` is **not**: the deploy excludes it, and the copy on the
  server is hand-maintained and authoritative. `public/.htaccess` is the tracked
  mirror of it (SPA routing, the `Authorization` pass-through, the Cloudflare
  origin lock, security headers and the deny rules below).
- **A change to `public/.htaccess` reaches production only when someone copies it
  into the live `.htaccess` by hand.** Merge it into the server copy rather than
  overwriting it, then re-check the origin lock (direct-to-origin still 403).
- **These PHP files + `schema.sql` are NOT in the FTP deploy.** Upload them to
  the document root by hand once (and again when they change). They live next to
  `index.html` at the site root.
- **`config.php` is server-only and gitignored.** Copy `config.sample.php` to
  `config.php` on the server and fill in the real database password and the
  integration keys you use.

### Files in the document root that must not be served

Because `server/` is uploaded beside `index.html`, `schema.sql`, `migrations/`,
`tests/`, this `README.md` and the CLI-only tools (`migrate.php`, `seed.php`,
`backfill-people.php`, `backfill-payroll.php`, `create-admin.php`,
`report-activation.php`, `restore-check.php`) can end up in the document root. `public/.htaccess` refuses
them with three `RewriteRule ... [F]` lines next to the `^uploads/` rule. Those
lines were added to the tracked copy on 2026-10-08 and **must be added to the live
`.htaccess` by hand**. Afterwards, confirm that `/schema.sql`, `/README.md` and
`/migrations/001_people_spine.sql` return 403 and that `/contact.php` and the cron
endpoints still respond.

## First-time setup

1. In cPanel → MySQL Databases, create the database and user (the values in
   `config.sample.php`), and grant the user all privileges on the database.
2. In phpMyAdmin, select the database and import `schema.sql`. This creates the
   tables. **No administrator is created** -- `schema.sql` used to seed one whose
   password was printed in the file, which is not something a tracked file should
   ever contain.
   Create the first administrator over SSH with a password you choose:
   `php server/create-admin.php --email you@example.com --name "Your Name"`.
   It prompts for the password rather than taking it as an argument, so it never
   reaches your shell history or the process list.
3. Copy `config.sample.php` → `config.php` and fill in `DB_PASS` and the
   integration keys you use. Leave `RG_ENV` as `'production'`:
   `migrate.php`, `seed.php` and `backfill-people.php` read it to decide what
   they may do, and `DB_HOST` cannot answer that here — on cPanel the production
   database is `localhost` too.
4. Upload all `*.php` to the document root.

## Smoke test (build-order step 1)

`api.php` requires a bearer token from a staff account, so call it signed in as staff:

- `GET https://ritagreenservices.com/api.php` with `Authorization: Bearer <token>`
  → `{"items":[...]}`; without a token → 401
- `POST https://ritagreenservices.com/api.php` with the same header and
  `{"name":"hello"}` → the new item
- In the app (development builds only), sign in and open **/app/smoke-test** to
  exercise GET and POST from the UI.

## Backups, restore test and release

`backup.php` writes the daily dump. After a backup has been restored into a separate
database, `restore-check.php` (CLI only) compares it, and the restored uploads, with the source,
and with `--record` records a passing restore test for Settings, System health. The procedure is
[`docs/runbooks/backup-and-restore.md`](../docs/runbooks/backup-and-restore.md). Releasing the
2026 build, including which files to upload and delete, is
[`docs/runbooks/release-2026.md`](../docs/runbooks/release-2026.md).

## Cron endpoints

`expiry-alerts.php`, `send-queued-mail.php`, `backup.php` and `retention-sweep.php`
run from the CLI with no key. `retention-sweep.php` runs weekly and only reports unless
given `--go`; the schedule for each job is in the release runbook, section 6. Over HTTP they need `ALERTS_KEY`, preferably in an `X-Cron-Key` header,
which keeps it out of the access log:

```sh
wget -qO- --header="X-Cron-Key: <ALERTS_KEY>" "https://ritagreenservices.com/send-queued-mail.php"
```

The older `?key=<ALERTS_KEY>` form still works so existing crontabs keep running.
When both are sent, the header wins. Move each crontab to the header form when
convenient. See `docs/API.md`, the `expiry-alerts.php` and `backup.php` sections.

## Endpoints

A few, as a starting point. **`docs/API.md` is the reference**: it
covers every file, every action and the exact auth posture of each, and it is
kept in step with the code. This table is a signpost, not a source of truth.

"Office roles" means `super_admin`, `admin`, `manager`. "Staff" means any
internal account, which is decided by the absence of a `worker_id` or
`client_id` link rather than by role alone — see `is_staff()` in `lib.php`.

| File | Purpose | Auth |
|---|---|---|
| `api.php` | items smoke test (GET/POST) | staff |
| `auth.php` | `?action=login\|me\|logout` | login: none; me/logout: token |
| `clients.php` | clients CRM (GET list / GET `?id` / POST) | office roles throughout, reads included |
| `workers.php` | workforce (GET list / POST) | staff to read, with pay and compliance fields filtered by zone; POST = office roles |
| `dashboard.php` | command-centre summary (`?division=`) | staff |
| `contact.php` | public enquiry form (POST) | none |
| `certificates.php` | `?action=verify&id=` public check | none |
