import { readFileSync } from "node:fs"
import { resolve } from "node:path"
import { describe, expect, it } from "vitest"
import {
  LEGACY_REDIRECTS,
  LEGACY_SERVICE_SLUGS,
  PUBLIC_PAGES,
  buildSitemapXml,
  legacyQuoteTarget,
  legacyServiceTarget,
  legacyServicesTarget,
} from "@/lib/public-routes"
import { SERVICE_PAGES } from "@/lib/service-pages"

const htaccess = readFileSync(resolve(__dirname, "../../../public/.htaccess"), "utf8")
const routerSource = readFileSync(resolve(__dirname, "../../routes/index.tsx"), "utf8")

describe("the public route table", () => {
  it("lists every page in the 2026 sitemap", () => {
    const paths = PUBLIC_PAGES.map((p) => p.path)
    for (const p of [
      "/",
      "/healthcare",
      "/healthcare/staffing",
      "/healthcare/supported-living",
      "/cleaning",
      "/security",
      "/training",
      "/about",
      "/news",
      "/contact",
      "/request",
      "/careers",
      "/verify",
      "/privacy",
      "/terms",
      "/cookies",
      "/data-request",
    ]) {
      expect(paths).toContain(p)
    }
  })

  it("lists no retired address as a page", () => {
    const paths = PUBLIC_PAGES.map((p) => p.path)
    for (const old of Object.keys(LEGACY_REDIRECTS)) expect(paths).not.toContain(old)
    expect(paths.some((p) => p.startsWith("/services") || p.startsWith("/resources"))).toBe(false)
  })

  it("has a route in the router for every listed page", () => {
    for (const { path } of PUBLIC_PAGES) {
      if (path === "/") continue
      expect(routerSource).toContain(`path: "${path.slice(1)}"`)
    }
  })

  it("has content for every service page it lists", () => {
    for (const page of Object.values(SERVICE_PAGES)) {
      expect(PUBLIC_PAGES.map((p) => p.path)).toContain(page.path)
    }
  })
})

describe("redirects from the previous site", () => {
  it("maps all twelve old service pages to a new page", () => {
    expect(Object.keys(LEGACY_SERVICE_SLUGS)).toHaveLength(12)
    const pages = PUBLIC_PAGES.map((p) => p.path)
    for (const target of Object.values(LEGACY_SERVICE_SLUGS)) expect(pages).toContain(target)
    expect(legacyServiceTarget("care-home-staffing")).toBe("/healthcare/staffing")
    expect(legacyServiceTarget("concierge")).toBe("/security")
    expect(legacyServiceTarget("no-such-page")).toBe("/healthcare")
    expect(legacyServiceTarget(undefined)).toBe("/healthcare")
  })

  it("sends /services to the overview, and its old anchors to their own pages", () => {
    expect(legacyServicesTarget("")).toBe("/#services")
    expect(legacyServicesTarget("#care")).toBe("/healthcare")
    expect(legacyServicesTarget("#academy")).toBe("/training")
    expect(legacyServicesTarget("#nonsense")).toBe("/#services")
  })

  it("sends /quote to the cleaning quote unless it named another service", () => {
    expect(legacyQuoteTarget(null)).toBe("/request?type=cleaning")
    expect(legacyQuoteTarget("cleaning")).toBe("/request?type=cleaning")
    expect(legacyQuoteTarget("care")).toBe("/request?type=staff&service=care")
    expect(legacyQuoteTarget("academy")).toBe("/request?type=training")
  })

  it("has a matching 301 rule in public/.htaccess for every old address", () => {
    for (const [from, to] of Object.entries(LEGACY_REDIRECTS)) {
      const rule = new RegExp(`RewriteRule \\^${from.slice(1)}/\\?\\$ ${to.replace(/[?#]/g, "\\$&")} \\[R=301`)
      expect(htaccess, `${from} -> ${to}`).toMatch(rule)
    }
    for (const [slug, to] of Object.entries(LEGACY_SERVICE_SLUGS)) {
      const line = htaccess.split("\n").find((l) => l.includes("RewriteRule ^services/") && l.includes(slug))
      expect(line, slug).toBeDefined()
      expect(line).toContain(` ${to} [R=301`)
    }
    expect(htaccess).toMatch(/RewriteRule \^resources\/\(\[\^\/\]\+\)\/\?\$ \/news\/\$1 \[R=301/)
  })
})

describe("the generated sitemap", () => {
  const xml = buildSitemapXml()

  it("is a sitemap with one entry per page, on the production origin", () => {
    expect(xml.startsWith('<?xml version="1.0" encoding="UTF-8"?>')).toBe(true)
    expect(xml.match(/<url>/g)).toHaveLength(PUBLIC_PAGES.length)
    expect(xml).toContain("<loc>https://ritagreenservices.com/</loc>")
    expect(xml).toContain("<loc>https://ritagreenservices.com/healthcare/staffing</loc>")
  })

  it("leaves out retired and private addresses", () => {
    for (const bad of ["/services", "/resources", "/quote", "/apply", "/sectors", "/login", "/app"]) {
      expect(xml).not.toContain(`ritagreenservices.com${bad}<`)
    }
  })
})
