// The public website's address book: which pages exist, where retired addresses
// now go, and the sitemap built from them.
//
// Plain data and pure functions with no imports, on purpose: vite.config.ts
// imports this file to write dist/sitemap.xml at build time, and the router
// (src/routes/index.tsx and src/routes/legacy-redirects.tsx) reads the same
// tables, so the sitemap, the redirects and the pages cannot drift apart. The
// 301 rules in public/.htaccess repeat the redirects for crawlers and old
// bookmarks; src/lib/__tests__/public-routes.test.ts fails if a legacy address
// here has no rule there.

export const SITE_ORIGIN = "https://ritagreenservices.com"

/** An indexable public page. `priority` is the sitemap hint, 0 to 1. */
export type PublicPage = { path: string; priority: number }

// Every page a visitor can land on from a search. Not listed: /login and the
// account flows (robots.txt disallows them), /news/:slug and /verify/:id (one
// per record; posts are added when the sitemap can read them), the 404, and the
// legacy addresses below, which redirect.
export const PUBLIC_PAGES: PublicPage[] = [
  { path: "/", priority: 1.0 },
  { path: "/healthcare", priority: 0.9 },
  { path: "/healthcare/staffing", priority: 0.9 },
  { path: "/healthcare/supported-living", priority: 0.8 },
  { path: "/cleaning", priority: 0.8 },
  { path: "/security", priority: 0.8 },
  { path: "/training", priority: 0.8 },
  { path: "/request", priority: 0.9 },
  { path: "/careers", priority: 0.8 },
  { path: "/about", priority: 0.6 },
  { path: "/news", priority: 0.6 },
  { path: "/contact", priority: 0.7 },
  { path: "/verify", priority: 0.4 },
  { path: "/privacy", priority: 0.3 },
  { path: "/terms", priority: 0.3 },
  { path: "/cookies", priority: 0.3 },
  { path: "/data-request", priority: 0.3 },
]

// The twelve service pages of the previous site, each sent to the page that now
// covers it. Home care and domiciliary care go to supported living, which is
// where "care at home" enquiries are now taken.
export const LEGACY_SERVICE_SLUGS: Record<string, string> = {
  "care-home-staffing": "/healthcare/staffing",
  "domiciliary-care": "/healthcare/supported-living",
  "supported-living": "/healthcare/supported-living",
  "health-social-care": "/training",
  "mandatory-training": "/training",
  "cpd-courses": "/training",
  "commercial-cleaning": "/cleaning",
  "care-environment-cleaning": "/cleaning",
  "communal-end-of-tenancy": "/cleaning",
  "manned-guarding": "/security",
  "event-security": "/security",
  concierge: "/security",
}

/** Where /services/:slug now lives. An unknown slug goes to Healthcare, the lead service. */
export function legacyServiceTarget(slug: string | undefined): string {
  return (slug && LEGACY_SERVICE_SLUGS[slug]) || "/healthcare"
}

// /services was an overview of all four services. It goes to the overview of
// all four on the home page, not to Healthcare alone: somebody who bookmarked
// "services" wanted the list. The old site linked to /services#care and so on;
// those fragments never reach the server, so the client-side redirect reads
// them and sends each one to its own page.
const LEGACY_SERVICE_ANCHORS: Record<string, string> = {
  care: "/healthcare",
  academy: "/training",
  cleaning: "/cleaning",
  security: "/security",
}

export const SERVICES_OVERVIEW = "/#services"

export function legacyServicesTarget(hash: string | undefined): string {
  const key = (hash ?? "").replace(/^#/, "")
  return LEGACY_SERVICE_ANCHORS[key] ?? SERVICES_OVERVIEW
}

/** Retired single addresses and where they go. Query strings are part of the target. */
export const LEGACY_REDIRECTS: Record<string, string> = {
  "/services": SERVICES_OVERVIEW,
  "/sectors": "/about",
  "/accreditations": "/about",
  "/resources": "/news",
  "/apply": "/careers",
  "/quote": "/request?type=cleaning",
}

/**
 * Where /quote?service=... now goes. /quote was the cleaning-heavy quote form,
 * so a bare /quote opens the cleaning quote; the old links that named another
 * service open the form for that service instead.
 */
export function legacyQuoteTarget(service: string | null): string {
  if (service === "care" || service === "security") return `/request?type=staff&service=${service}`
  if (service === "academy") return "/request?type=training"
  return LEGACY_REDIRECTS["/quote"]
}

function escapeXml(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
}

/**
 * The sitemap for a list of pages.
 *
 * No <lastmod>: the build cannot know when a page's content last changed, and a
 * build date on every URL tells a crawler nothing. Published posts are not
 * included because they live in the database, which the build (in CI) cannot
 * reach; see docs/ARCHITECTURE.md.
 */
export function buildSitemapXml(pages: PublicPage[] = PUBLIC_PAGES, origin: string = SITE_ORIGIN): string {
  const urls = pages
    .map((p) => {
      const loc = escapeXml(origin + (p.path === "/" ? "/" : p.path))
      return `  <url><loc>${loc}</loc><priority>${p.priority.toFixed(1)}</priority></url>`
    })
    .join("\n")
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`
}
