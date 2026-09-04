# Cookie and browser-storage inventory

**Status:** verified at runtime against the dev server on 2026-09-04, not legal advice.

Method: cleared all browser state, loaded the site headless, and read `localStorage`,
`sessionStorage`, `document.cookie`, the Cache API and the network log. Repeated after
acknowledging the notice, after a reload, after signing in as a worker, and after signing out.

**The site makes no third-party requests.** Verified twice: the network log for a cold load
contains only `localhost:5122`, and the production build contains no reference to any external
origin. Google Fonts was the only one and is now self-hosted under `public/fonts`.

---

## Cookies

| Name | Provider | Purpose | Category | Party | Set when | Duration | Data | Essential | Consent likely needed | Disclosed | How to remove |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `sidebar_state` | First party | Remembers whether the CMS sidebar is open | Preference | First | Toggling the sidebar in `/app` | 7 days | `true` or `false` | No, but it carries no personal data | **LEGAL** — a preference cookie the user set themselves | `/cookies` | Browser settings |

That is the only cookie. `SameSite=Lax` and, over HTTPS, `Secure` were added by this audit;
it previously had neither. Evidence: `src/components/ui/sidebar.tsx`.

## Local storage

| Key | Purpose | Category | Set when | Cleared | Data | Essential | Disclosed |
|---|---|---|---|---|---|---|---|
| `rgos_token` | Session bearer token | Strictly necessary | Signing in | Sign-out, a failed session check, or 30 days | A 64-character server-issued token | Yes | `/cookies` |
| `rgos_token_issued` | When that token was issued, so the browser can expire it | Strictly necessary | Signing in | With the token | A timestamp | Yes | `/cookies` |
| `rg-storage-notice` | That the notice was seen, and which version | Strictly necessary | Acknowledging the notice | Browser settings | `{"version","at"}` | Yes | `/cookies` |
| `theme` | Light or dark | Preference | Changing the theme | Browser settings | `light`, `dark` or `system` | No | `/cookies` |
| `rg-worker-location-notice` | That the worker location notice was seen | Strictly necessary | Acknowledging it in `/m` | Browser settings | A version string | Yes | `/cookies` and the notice itself |

`sessionStorage` and `IndexedDB` are unused by application code. Workbox creates its own
IndexedDB database for cache expiry metadata — URLs and timestamps, no response bodies.

## Service-worker caches

| Cache | Contents | Lifetime | Personal data | Cleared |
|---|---|---|---|---|
| `workbox-precache-v2-*` | Built assets: JS, CSS, HTML, SVG, PNG, and the Latin webfont subsets | Until the next deploy | No | Automatically |
| `worker-reads` | `me.php` and `notifications.php` responses, so the worker app works with no signal | 20 entries, 12 hours | **Yes** — the worker's own profile, right-to-work flag and compliance record with expiry dates | On sign-out (added by this audit), and by expiry |

The Latin Extended font subsets are excluded from the precache (`vite.config.ts` `globIgnores`):
about 430 kB most visits never request, since the browser fetches a subset only when a glyph in
its range appears. Offline, an accented name falls back to the system font.

## Tracking, analytics and advertising

**None.** Verified by searching `src/`, `index.html`, `public/` and `package.json`: no Google
Analytics, no Tag Manager, no gtag, no Plausible, PostHog, Matomo, Segment, Hotjar, FullStory or
Clarity; no Sentry or any error-monitoring SDK; no advertising pixel; no session replay or
heatmap; no maps, video embeds, social widgets or CAPTCHA. The CSP is `script-src 'self'` and
`connect-src 'self'`, so the bundle cannot reach a third party even if one were added by
mistake.

One outbound link exists, on `/verify`: a user-clicked "add to LinkedIn profile" link carrying
the course name and certificate id. It is a link, not an embed, and nothing loads from LinkedIn.

## Server-side, for completeness

Not browser storage, but the same disclosure question: the web server keeps ordinary request
logs, `rate_limits` records IP addresses for a day, and `audit_log`, `consent_log`, `export_log`,
`data_requests` and `tokens` each store an IP address alongside the action they describe.

## Consent control

There is nothing optional to consent to, so the site shows a notice rather than a choice — see
`src/components/public/CookieConsent.tsx`, which explains the reasoning in full. It carries a
version so that adding an optional category re-asks rather than inheriting an old
acknowledgement, and it can be reopened from the footer.

Verified at runtime: appears on first visit with cleared storage; acknowledging writes the
version and timestamp; a reload keeps it dismissed; the footer link reopens it and moves focus
to it; Escape closes it and returns focus to the footer button; changing the stored version
re-prompts; no console errors; no horizontal overflow at 375 px or 1280 px.

**If an optional category is ever added,** this becomes a real consent control: equally weighted
accept and reject, nothing preselected, granular categories, and the scripts stay inert until
the choice is made.
