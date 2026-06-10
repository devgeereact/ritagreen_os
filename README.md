# RitaGreenOS

The platform for RitaGreen Healthcare Services Ltd: a public corporate website and
an internal management CMS for four divisions (Care, Academy, Cleaning, Security),
on one domain.

- **Public site** — multi-page corporate site at the root (`/`, `/about`, `/services`,
  `/sectors`, `/accreditations`, `/resources`, `/contact`, `/verify`, legal).
- **CMS** — behind `/login` → `/app/*` (Command Centre, Clients, Workers, …).

## Stack

- React 19 + Vite + TypeScript, shadcn/Radix/Tailwind (Pulse-UI kit base)
- PHP (`mysqli`, prepared statements) + MySQL — see [`server/`](server/README.md)
- OpenRouter AI, server-side only via `server/ai.php`
- Hosting: cPanel shared hosting; FTP auto-deploy from GitHub Actions on push to `main`

## Develop

```bash
npm install
npm run dev      # http://localhost:5173 (fetches the live API at ritagreenservices.com)
npm run build    # -> dist/  (this is what deploy.yml ships)
```

## Deploy

- `git push` to `main` → GitHub Actions builds and FTP-uploads `dist/` to the doc root.
- The PHP API (`server/*.php`) + `schema.sql` are uploaded by hand once; `config.php`
  (DB + OpenRouter secrets) is server-only and gitignored. See [server/README.md](server/README.md).

See [CLAUDE.md](CLAUDE.md) for full project memory and conventions.
