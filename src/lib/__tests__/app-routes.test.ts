import { describe, it, expect } from "vitest"
import { DESTINATIONS, titleFor } from "@/lib/app-routes"
import { rolesFor } from "@/lib/access"

// The page registry behind the ⌘K palette and the browser tab title.

describe("titleFor", () => {
  it("names an exact page", () => {
    expect(titleFor("/app/invoicing")).toBe("Invoicing")
    expect(titleFor("/app")).toBe("Today")
  })

  it("takes the longest matching prefix, so a detail page keeps its section", () => {
    expect(titleFor("/app/clients/12")).toBe("Clients")
    expect(titleFor("/app/academy/3")).toBe("Training")
  })

  it("does not match on a shared word-prefix", () => {
    // "/app/academy-archive" is not under "/app/academy".
    expect(titleFor("/app/academy-archive")).toBe("Today")
  })

  it("returns null for a path outside the app", () => {
    expect(titleFor("/about")).toBeNull()
    expect(titleFor("/portal")).toBeNull()
  })
})

describe("DESTINATIONS", () => {
  it("lists no page twice", () => {
    const paths = DESTINATIONS.map((d) => d.path)
    expect(new Set(paths).size).toBe(paths.length)
  })

  it("still finds every page, including the ones that left the sidebar", () => {
    const paths = new Set(DESTINATIONS.map((d) => d.path))
    for (const p of [
      "/app", "/app/scheduling", "/app/jobs", "/app/field", "/app/timesheets", "/app/payroll",
      "/app/workers", "/app/compliance", "/app/ats", "/app/academy", "/app/incidents",
      "/app/clients", "/app/enquiries", "/app/bookings", "/app/quotes", "/app/contracts", "/app/invoicing",
      "/app/reports", "/app/inbox", "/app/notifications", "/app/comms",
      "/app/content-studio", "/app/blog", "/app/testimonials", "/app/files", "/app/settings", "/app/help",
      "/app/profile",
    ]) {
      expect(paths.has(p), p).toBe(true)
    }
  })

  it("finds a page by what it used to be called", () => {
    const find = (word: string) =>
      DESTINATIONS.filter((d) => `${d.label} ${d.keywords ?? ""}`.toLowerCase().includes(word)).map((d) => d.path)
    expect(find("scheduling")).toContain("/app/scheduling")
    expect(find("command centre")).toContain("/app")
    expect(find("workers")).toContain("/app/workers")
    expect(find("academy")).toContain("/app/academy")
    expect(find("bookings")).toContain("/app/bookings")
    expect(find("blog")).toContain("/app/blog")
  })

  it("ties the money pages to the finance switch and the inbox to its own", () => {
    const feature = (path: string) => DESTINATIONS.find((d) => d.path === path)?.feature
    for (const p of ["/app/invoicing", "/app/quotes", "/app/contracts", "/app/payroll"]) {
      expect(feature(p), p).toBe("finance")
    }
    expect(feature("/app/inbox")).toBe("inbox")
    expect(feature("/app/scheduling")).toBeUndefined()
  })

  it("points only at /app routes the access map governs", () => {
    // A destination the palette offers must be a page the router knows the
    // roles for, or canAccess() would wave everyone through to a dead end.
    for (const d of DESTINATIONS) {
      expect(d.path.startsWith("/app")).toBe(true)
      expect(rolesFor(d.path)).not.toBeNull()
    }
  })
})
