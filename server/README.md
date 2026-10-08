# RitaGreenOS — server API

PHP endpoints for RitaGreenOS. React never touches MySQL; it calls these over
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
  `config.php` on the server and fill in the real DB password + OpenRouter key.

### Files in the document root that must not be served

Because `server/` is uploaded beside `index.html`, `schema.sql`, `migrations/`,
`tests/`, this `README.md` and the CLI tools (`migrate.php`, `seed.php`,
`backfill-people.php`) can end up in the document root. `public/.htaccess` refuses
them with three `RewriteRule ... [F]` lines next to the `^uploads/` rule. Those
lines were added to the tracked copy on 2026-10-08 and **must be added to the live
`.htaccess` by hand**. Afterwards, confirm that `/schema.sql`, `/README.md` and
`/migrations/001_people_spine.sql` return 403 and that `/contact.php` and the cron
endpoints still respond.

## First-time setup

1. In cPanel → MySQL Databases, create the database and user (the values in
   `config.sample.php`), and grant the user all privileges on the database.
2. In phpMyAdmin, select the database and import `schema.sql`. This creates the
   tables and seeds a super-admin, `dev@ritagreenservices.com`, with a default
   password set by the seed. **Treat that password as public and change it
   straight away.** Either use **Forgot password** on `/login` (needs mail
   working), or generate a hash locally and paste it into `users.password_hash`
   for that row in phpMyAdmin:
   `php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT), PHP_EOL;' '<new-password>'`
   (then clear that line from your shell history).
3. Copy `config.sample.php` → `config.php` and fill in `DB_PASS`, `OR_API_KEY`
   and (optionally) a different `OR_MODEL`.
4. Upload all `*.php` to the document root.

## Smoke test (build-order step 1)

`api.php` requires a bearer token (any role), so call it signed in:

- `GET https://ritagreenservices.com/api.php` with `Authorization: Bearer <token>`
  → `{"items":[...]}`; without a token → 401
- `POST https://ritagreenservices.com/api.php` with the same header and
  `{"name":"hello"}` → the new item
- In the app, sign in and open **/app/smoke-test** to exercise GET, POST and the
  `ai.php` proxy from the UI.

## Cron endpoints

`expiry-alerts.php`, `send-queued-mail.php` and `backup.php` run from the CLI with
no key. Over HTTP they need `ALERTS_KEY`, preferably in an `X-Cron-Key` header,
which keeps it out of the access log:

```sh
wget -qO- --header="X-Cron-Key: <ALERTS_KEY>" "https://ritagreenservices.com/send-queued-mail.php"
```

The older `?key=<ALERTS_KEY>` form still works so existing crontabs keep running.
When both are sent, the header wins. Move each crontab to the header form when
convenient. See `docs/API.md` §53 and §54.

## Endpoints

There are 55 HTTP endpoint files. The table below covers only the first few; the
full list, with each file's auth posture, is `docs/API.md` §7.

| File | Purpose | Auth |
|---|---|---|
| `api.php` | items smoke test (GET/POST) | token (any role) |
| `auth.php` | `?action=login\|me\|logout` | login: none; me/logout: token |
| `ai.php` | OpenRouter proxy (POST `{prompt}`) | token |
| `clients.php` | clients CRM (GET list / GET `?id` / POST) | token; POST = office roles |
| `workers.php` | workforce (GET list / POST) | token; POST = office roles |
| `dashboard.php` | command-centre summary (`?division=`) | token |
| `contact.php` | public enquiry form (POST) | none |
| `certificates.php` | `?action=verify&id=` public check | none |
