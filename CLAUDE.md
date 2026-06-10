# CLAUDE.md — <PROJECT_NAME>

> **Project memory for Claude Code.** Read this fully before doing any work on this project.
> This is a reusable template: copy it into each new project's repo root and fill in §1.
> The full one-time setup steps live in the separate **PROJECT-SETUP-GUIDE**.

---

## 1. Project-specific values — FILL THESE IN

| Item | Value |
|---|---|
| Project / repo | `devgeereact/ritagreen_os` |
| Domain | `ritagreenservices.com` |
| Document root | `/home/<cpanel_user>/ritagreenservices.com` (confirm cPanel user) |
| Database name | `ritagreen_os` (cPanel may prefix as `<cpaneluser>_ritagreen_os`) |
| Database user | `ritagreen_os` (usually same as DB name) |
| Database host | `localhost` |
| Deploy branch | `main` |
| FTP username | `ritagreen_os@ritagreenservices.com` |

> **Stack note:** this project is **TypeScript** (Pulse-UI kit), not plain JS. It is a
> **merged single Vite app**: public corporate site at the root, internal CMS behind
> `/login` → `/app/*`. PHP API + `schema.sql` live in `server/` (version-controlled,
> uploaded to the doc root by hand, excluded from the FTP deploy). OpenRouter model is
> set in `config.php` as `OR_MODEL`. Seed super-admin: `dev@ritagreenservices.com` / `Testing123!`.

---

## 2. Shared hosting infrastructure (same for every project on this account)

| Item | Value |
|---|---|
| Hosting | Namecheap shared hosting (cPanel) |
| cPanel account | `<cpanel_user>` |
| Home directory | `/home/<cpanel_user>` |
| Server IP | `<server_ip>` |
| DNS provider | Cloudflare — records set to **DNS only (grey cloud)** |
| FTP server | `<server_ip>` |

> Credentials are NEVER stored in this repo. The DB password lives only in `config.php` on the server; FTP credentials live only in GitHub repo Secrets.

---

## 3. What this project is

A **React (Vite) single-page app** hosted on Namecheap shared hosting (cPanel), served at the domain above. It reads/writes a **MySQL database** on the same server through a small **PHP API** (`api.php`). Deployment is **automatic**: pushing to the deploy branch triggers a GitHub Actions workflow that builds the app and uploads it via FTP.

---

## 4. Architecture & data flow

```
Browser (React)
   │  fetch() → JSON
   ▼
api.php  (PHP, on the server)
   │  mysqli, host = localhost
   ▼
MySQL database (cPanel)
```

- **React runs in the browser and NEVER connects to MySQL directly.** It only calls `api.php` over HTTP and receives JSON.
- `api.php` is the only thing that touches the database, using credentials from `config.php`.
- The React build (static files) and the PHP files live together in the document root.

---

## 5. Tech stack

- **Frontend:** React 18 + Vite (JavaScript)
- **API:** PHP with `mysqli` + prepared statements, returns JSON
- **Database:** MySQL (cPanel → phpMyAdmin)
- **DNS:** Cloudflare (DNS only / grey cloud)
- **CI/CD:** GitHub Actions → FTP deploy (`SamKirkland/FTP-Deploy-Action`)

---

## 6. Key files & where they live

**In the repo (version-controlled, auto-deployed on push):**
- `src/` — React app source
- `index.html`, `vite.config.js`, `package.json`, `package-lock.json` — Vite project
- `.github/workflows/deploy.yml` — the auto-deploy workflow
- `.gitignore` — must exclude `node_modules/`, `dist/`, and `config.php`

**On the server only (placed by hand once, NOT in the repo, NOT touched by deploys):**
- `config.php` — DB credentials
- `api.php` — the API endpoints
- `.htaccess` — SPA routing (`RewriteBase /`)

Deploys upload **only the `dist/` build**, so the three server files are never disturbed.

---

## 7. Golden rules (do not break these)

1. **Never commit `config.php` or any password.** It's gitignored. DB creds live only in `config.php` on the server.
2. **React never connects to MySQL directly.** Any data need = add/extend a PHP endpoint, then `fetch` it.
3. **Always use the `API_BASE` pattern** so dev and production both work:
   ```js
   // dev → hit the live API (CORS enabled in api.php); production → relative (same domain)
   const API_BASE = import.meta.env.DEV ? "https://<domain.com>" : "";
   fetch(`${API_BASE}/api.php`)
   ```
4. **Deploys upload only `dist/`.** `config.php`/`api.php`/`.htaccess` are managed by hand on the server.
5. **Cloudflare records stay grey-cloud (DNS only)** so cPanel can issue SSL.
6. **Don't manually FTP files.** `git push` is the deploy.

---

## 8. Local development

```bash
npm install      # first time only
npm run dev      # http://localhost:5173, hot reload
```
`fetch` calls hit the live API at the project's domain (no PHP/MySQL on the laptop). Edit components in `src/`.

---

## 9. Build & deploy

- **Build:** `npm run build` → `dist/`.
- **Deploy:** `git push` to the deploy branch. GitHub Actions runs `npm ci` → `npm run build` → FTP-uploads `dist/`. Watch the repo's **Actions** tab; live in ~1–2 minutes.

Everyday loop:
```bash
git add . && git commit -m "..." && git push   # → live
```

---

## 10. Database & adding features

- Managed in cPanel → phpMyAdmin, on the database named in §1.
- To add a feature with data:
  1. Create the table(s) in phpMyAdmin.
  2. Add an endpoint in `api.php` — read `$_SERVER['REQUEST_METHOD']`, use **prepared statements**, `echo json_encode(...)`. For a separate resource, create another PHP file (e.g. `products.php`).
  3. `fetch` it from React using `API_BASE`.
- `api.php` begins with `require 'config.php';`, which provides `$conn` (a `mysqli` connection).

---

## 11. Troubleshooting

- **Site 404 / wrong content:** Cloudflare record may be orange-cloud (set grey) or wrong IP. Hard refresh / incognito for cache.
- **`api.php` "Database connection failed":** wrong creds in `config.php`, or user lacks privileges.
- **`api.php` errors on GET:** the queried table doesn't exist — create it in phpMyAdmin.
- **Deploy red X:** open the Actions run; `npm ci` fail → commit `package-lock.json`; FTP auth fail → check the three Secrets match the FTP account.
- **HTTPS warning:** SSL still issuing — use `http://` or run cPanel → SSL/TLS Status → Run AutoSSL.

---

## 12. What NOT to do

- Don't commit `config.php`, `node_modules/`, `dist/`, or any secret.
- Don't write database connection code in React.
- Don't manually FTP/upload the build — push instead.
- Don't switch Cloudflare to orange-cloud without proper SSL setup.
- Don't deploy into a subfolder — the site lives at the document root (`server-dir: ./`).
