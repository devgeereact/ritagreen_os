# Cookie and browser-storage inventory

**Status:** verified at runtime against the dev server on 2026-09-04, not legal advice.

Method: cleared all browser state, loaded the site headless, and read `localStorage`,
`sessionStorage`, `document.cookie`, the Cache API and the network log. Repeated after
acknowledging the notice, after a reload, after signing in as a worker, and after signing out.

**The site makes no third-party requests.** Verified twice: the network log for a cold load
contains only `localhost:5122`, and the production build contains no reference to any external
origin. Google Fonts was the only one and is now self-hosted under `public/fonts`.

**One exception, and only on request (S7, 2026-10-09).** A role page (`/careers/:slug`) whose
vacancy links a Google Form shows a notice and a button, "Load the application form from
Google". Until it is pressed the page makes no request to Google. Pressing it adds an iframe
from `docs.google.com`, after which Google Forms runs in that frame and Google may set or read
its own cookies under its own policy; this site cannot see or control them and stores nothing
of its own when it happens. The "Open the form in a new tab" link and the office phone number
are always shown, so nobody has to load the frame to apply. The CSP allows frames from
`https://docs.google.com` and nothing else third-party. Because the frame is loaded only by an
explicit action at the point of use, the site-wide notice stays a notice; the notice's stored
version was not changed, since nothing this site stores changed. The cookie notice page
(`/cookies`, version 2.1) and the privacy notice ("If you visit this website") say this.

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

`sessionStorage` and `IndexedDB` are unused by application code. Workbox may create its own
IndexedDB database for precache metadata: URLs and revisions, no response bodies.

## Service-worker caches

| Cache | Contents | Lifetime | Personal data | Cleared |
|---|---|---|---|---|
| `workbox-precache-v2-*` | Built assets: JS, CSS, HTML, SVG, PNG, and the Latin webfont subsets | Until the next deploy | No | Automatically |
| `worker-reads` | **Retired in S4.** Held `me.php` and `notifications.php` responses for 12 hours. No API response is cached now; the staff portal deletes this cache on open and on sign-out, so an older install does not keep it | None from S4 on | Was: the worker's own profile and compliance record | On opening `/m`, on sign-out |

The Latin Extended font subsets are excluded from the precache (`vite.config.ts` `globIgnores`):
about 430 kB most visits never request, since the browser fetches a subset only when a glyph in
its range appears. Offline, an accented name falls back to the system font.

## Tracking, analytics and advertising

**None.** Verified by searching `src/`, `index.html`, `public/` and `package.json`: no Google
Analytics, no Tag Manager, no gtag, no Plausible, PostHog, Matomo, Segment, Hotjar, FullStory or
Clarity; no Sentry or any error-monitoring SDK; no advertising pixel; no session replay or
heatmap; no maps, video embeds, social widgets or CAPTCHA. The Google Form frame above is the only embed, and only after a click. The CSP is `script-src 'self'` and
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
`src/components/public/CookieConsent.tsx`, which explains the reasoning in full. It renders on
the marketing site and, through `src/layouts/BareLayout.tsx`, on the sign-in, registration,
password-reset and shift-offer pages, which previously had no footer, no legal links and no
notice at all — including the page that writes the session token. It carries a
version so that adding an optional category re-asks rather than inheriting an old
acknowledgement, and it can be reopened from the footer.

Verified at runtime: appears on first visit with cleared storage; acknowledging writes the
version and timestamp; a reload keeps it dismissed; the footer link reopens it and moves focus
to it; Escape closes it and returns focus to the footer button; changing the stored version
re-prompts; no console errors; no horizontal overflow at 375 px or 1280 px.

**If an optional category is ever added,** this becomes a real consent control: equally weighted
accept and reject, nothing preselected, granular categories, and the scripts stay inert until
the choice is made.
