import { describe, it, expect } from "vitest"
import { NAV, visibleDestinations } from "@/lib/nav"
import { canAccess } from "@/lib/access"
import { DESTINATIONS } from "@/lib/app-routes"
import type { Role, User } from "@/providers/auth-provider"
import type { FeatureName } from "@/lib/types"

// The office sidebar: the eight destinations of the 2026 plan, Finance only
// when the owner has it switched on, and nothing a role could not open.

type Account = Pick<User, "role" | "client_id" | "worker_id">
const account = (role: Role): Account => ({ role, client_id: null, worker_id: null })

const ALL_ON: Record<FeatureName, boolean> = {
  finance: true,
  online_payments: true,
  sms_whatsapp: true,
  inbox: true,
  ai_assistant: true,
}

function navFor(role: Role, switches: Partial<Record<FeatureName, boolean>> = {}) {
  const on = { ...ALL_ON, ...switches }
  return visibleDestinations((url) => canAccess(account(role), url), (name) => on[name], {})
}

const titles = (nav: ReturnType<typeof navFor>) => nav.map((d) => d.title)

describe("the office sidebar", () => {
  it("shows the eight destinations when finance is off", () => {
    expect(titles(navFor("admin", { finance: false }))).toEqual([
      "Today", "Recruitment", "People", "Compliance", "Rota", "Website", "Reports", "Settings",
    ])
  })

  it("adds Finance, after the rota, when finance is on", () => {
    expect(titles(navFor("admin"))).toEqual([
      "Today", "Recruitment", "People", "Compliance", "Rota", "Finance", "Website", "Reports", "Settings",
    ])
  })

  it("puts the money pages under Finance", () => {
    const finance = navFor("admin").find((d) => d.title === "Finance")!
    expect([finance.url, ...(finance.items ?? []).map((i) => i.url)]).toEqual([
      "/app/invoicing", "/app/quotes", "/app/contracts", "/app/payroll",
    ])
  })

  it("puts the rota's pages under Rota", () => {
    const rota = navFor("admin").find((d) => d.title === "Rota")!
    expect(rota.url).toBe("/app/scheduling")
    expect((rota.items ?? []).map((i) => i.url)).toEqual([
      "/app/timesheets", "/app/clients", "/app/enquiries", "/app/bookings",
      "/app/jobs", "/app/field", "/app/incidents",
    ])
  })

  it("shows the inbox under Settings only while it is switched on", () => {
    const urls = (on: boolean) =>
      (navFor("admin", { inbox: on }).find((d) => d.title === "Settings")!.items ?? []).map((i) => i.url)
    expect(urls(true)).toContain("/app/inbox")
    expect(urls(false)).not.toContain("/app/inbox")
  })

  it("no longer lists the pages being retired, or Notifications", () => {
    const urls = NAV.flatMap((d) => [d.url, ...(d.items ?? []).map((i) => i.url)])
    for (const gone of ["/app/content-studio", "/app/analytics", "/app/insights", "/app/notifications"]) {
      expect(urls).not.toContain(gone)
    }
  })

  it("never shows a link the role cannot open", () => {
    const roles: Role[] = ["super_admin", "admin", "manager", "trainer", "editor", "employee"]
    for (const role of roles) {
      for (const d of navFor(role)) {
        expect(canAccess(account(role), d.url), `${role} -> ${d.url}`).toBe(true)
        for (const i of d.items ?? []) {
          expect(canAccess(account(role), i.url), `${role} -> ${i.url}`).toBe(true)
        }
      }
    }
  })

  it("sends a destination to its first open page when its own is closed", () => {
    // An employee may not open the rota or Settings, but may open Jobs and Help.
    const nav = navFor("employee")
    const rota = nav.find((d) => d.title === "Rota")!
    expect(rota.url).toBe("/app/jobs")
    expect((rota.items ?? []).map((i) => i.url)).toEqual(["/app/field"])
    expect(nav.find((d) => d.title === "Settings")!.url).toBe("/app/help")
  })

  it("drops a destination with nothing the role may open", () => {
    // Nothing on the website is an employee's to edit.
    expect(titles(navFor("employee"))).not.toContain("Website")
    expect(titles(navFor("employee"))).not.toContain("Finance")
  })

  it("gives an editor the website, without the owner's company details", () => {
    const website = navFor("editor").find((d) => d.title === "Website")!
    expect([website.url, ...(website.items ?? []).map((i) => i.url)]).toEqual([
      "/app/blog",
      "/app/vacancies",
      "/app/website-forms",
      "/app/testimonials",
    ])
  })

  it("gives an owner the company details too", () => {
    const website = navFor("admin").find((d) => d.title === "Website")!
    expect((website.items ?? []).map((i) => i.url)).toContain("/app/company")
  })

  it("links only to pages the search palette also knows", () => {
    // ⌘K is how the retired-from-nav pages stay reachable, and it names the tab.
    const known = new Set(DESTINATIONS.map((d) => d.path))
    for (const d of NAV) {
      expect(known.has(d.url), d.url).toBe(true)
      for (const i of d.items ?? []) expect(known.has(i.url), i.url).toBe(true)
    }
  })
})
