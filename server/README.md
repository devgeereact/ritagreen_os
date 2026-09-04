# RitaGreenOS — server API

PHP endpoints for RitaGreenOS. React never touches MySQL; it calls these over
HTTP and gets JSON back. Each file begins with `require __DIR__ . '/lib.php';`,
which loads `config.php` (the `$conn` mysqli connection) and sets up CORS, JSON
helpers and token auth.

## What deploys vs what you place by hand

- The **React build** (`dist/`) is uploaded automatically by GitHub Actions on
  push. `public/.htaccess` ships with it and handles SPA routing.
- **These PHP files + `schema.sql` are NOT in the FTP deploy.** Upload them to
  the document root by hand once (and again when they change). They live next to
  `index.html` at the site root.
- **`config.php` is server-only and gitignored.** Copy `config.sample.php` to
  `config.php` on the server and fill in the real DB password + OpenRouter key.

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
3. Copy `config.sample.php` → `config.php` and fill in `DB_PASS`, `OR_API_KEY`
   and (optionally) a different `OR_MODEL`.
4. Upload all `*.php` to the document root.

## Smoke test (build-order step 1)

- `GET https://ritagreenservices.com/api.php` → `{"items":[]}`
- `POST https://ritagreenservices.com/api.php` with `{"name":"hello"}` → the new item
- In the app, sign in and open **/app/smoke-test** to exercise GET, POST and the
  `ai.php` proxy from the UI.

## Endpoints

| File | Purpose | Auth |
|---|---|---|
| `api.php` | items smoke test (GET/POST) | none |
| `auth.php` | `?action=login\|me\|logout` | login: none; me/logout: token |
| `ai.php` | OpenRouter proxy (POST `{prompt}`) | token |
| `clients.php` | clients CRM (GET list / GET `?id` / POST) | token; POST = office roles |
| `workers.php` | workforce (GET list / POST) | token; POST = office roles |
| `dashboard.php` | command-centre summary (`?division=`) | token |
| `contact.php` | public enquiry form (POST) | none |
| `certificates.php` | `?action=verify&id=` public check | none |
