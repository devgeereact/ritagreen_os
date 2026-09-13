import { describe, it, expect } from "vitest"
import { canAccess, homeFor, isStaff, isClientAccount, isWorkerAccount, rolesFor, OFFICE, STAFF } from "@/lib/access"
import type { Role, User } from "@/providers/auth-provider"

// The permission map decides what the interface offers. It is not the security
// boundary — the server is — but it is what stops the app showing someone work
// they cannot do, and what stops a client portal login wandering into /app.

type Account = Pick<User, "role" | "client_id" | "worker_id">

const account = (role: Role, extra: Partial<Account> = {}): Account => ({
  role,
  client_id: null,
  worker_id: null,
  ...extra,
})

const ALL_ROLES: Role[] = [
  "super_admin", "admin", "manager", "trainer", "editor", "employee", "client",
]

describe("isStaff", () => {
  it("treats every internal role as staff", () => {
    for (const role of ["super_admin", "admin", "manager", "trainer", "editor", "employee"] as Role[]) {
      expect(isStaff(account(role))).toBe(true)
    }
  })

  it("does not treat a client role as staff", () => {
    expect(isStaff(account("client"))).toBe(false)
  })

  // The reason the function exists. A worker's login is an ordinary account with
  // worker_id set, so role alone would wave it straight into the office.
  it("does not treat a worker-linked login as staff", () => {
    expect(isStaff(account("employee", { worker_id: 7 }))).toBe(false)
  })

  it("does not treat a client-linked login as staff", () => {
    expect(isStaff(account("employee", { client_id: 3 }))).toBe(false)
  })

  // A manager who also holds a worker record must keep their office access.
  it("keeps privileged roles as staff even when linked to a worker", () => {
    expect(isStaff(account("manager", { worker_id: 7 }))).toBe(true)
    expect(isStaff(account("admin", { client_id: 3 }))).toBe(true)
  })

  it("mirrors is_staff() in server/pure.php", () => {
    // Same cases as the PHP suite, so a change to one without the other shows up.
    expect(isStaff(account("super_admin"))).toBe(true)
    expect(isStaff(account("employee", { worker_id: 7 }))).toBe(false)
    expect(isStaff(account("client"))).toBe(false)
    expect(isStaff(account("manager", { worker_id: 7 }))).toBe(true)
  })
})

describe("account kinds", () => {
  it("recognises a client account by role or link", () => {
    expect(isClientAccount(account("client"))).toBe(true)
    expect(isClientAccount(account("employee", { client_id: 3 }))).toBe(true)
    expect(isClientAccount(account("manager"))).toBe(false)
  })

  it("recognises a worker account by its link", () => {
    expect(isWorkerAccount(account("employee", { worker_id: 7 }))).toBe(true)
    expect(isWorkerAccount(account("employee"))).toBe(false)
  })
})

describe("rolesFor", () => {
  // /app is the shortest prefix and must never shadow the specific entries.
  it("prefers the longest matching prefix", () => {
    expect(rolesFor("/app/payroll")).toEqual(OFFICE)
    expect(rolesFor("/app")).toEqual(STAFF)
  })

  it("matches nested paths", () => {
    expect(rolesFor("/app/clients/42")).toEqual(OFFICE)
    expect(rolesFor("/app/academy/7")).toContain("trainer")
  })

  it("does not treat a shared word-prefix as a match", () => {
    // "/app/workers" must not be matched by a rule for "/app/work". Asserted on
    // the rule it resolves to rather than a role list, so tightening who may
    // open the page does not look like a prefix-matching regression.
    expect(rolesFor("/app/workers")).toEqual(rolesFor("/app/workers/42"))
    expect(rolesFor("/app/workers")).not.toEqual(rolesFor("/app"))
  })

  // Special-category pages are narrower than "any internal login". Editor is a
  // content role and holds no compliance zone in server/migrations/015, so the
  // link must not appear for it; incidents carry safeguarding and RIDDOR detail
  // and are office-only. These mirror the server checks, which are the boundary.
  it("keeps vetting records away from the content role", () => {
    for (const path of ["/app/workers", "/app/compliance"]) {
      expect(rolesFor(path)).not.toContain("editor")
      expect(rolesFor(path)).toContain("trainer")
      expect(rolesFor(path)).toContain("manager")
    }
  })

  it("keeps safeguarding incidents to the office", () => {
    expect(rolesFor("/app/incidents")).toEqual(OFFICE)
  })

  // Hours are what pay is calculated from, so the screens showing them are
  // payroll screens. server/migrations/015 gives the payroll zone to the office
  // alone, and server/timesheets.php and server/payroll.php both enforce it.
  // These three read as STAFF while the server let any internal account read
  // every worker's hours, so the two layers agreed and both were wrong.
  it("keeps other people's hours inside the payroll zone", () => {
    for (const path of ["/app/timesheets", "/app/timetable", "/app/payroll"]) {
      expect(rolesFor(path)).toEqual(OFFICE)
      for (const role of ["editor", "trainer", "employee"] as const) {
        expect(canAccess(account(role), path)).toBe(false)
      }
    }
  })

  it("returns null for a path it does not govern", () => {
    expect(rolesFor("/about")).toBeNull()
  })
})

describe("canAccess", () => {
  it("refuses anyone who is not signed in", () => {
    expect(canAccess(null, "/app")).toBe(false)
  })

  it("lets the office into the office", () => {
    expect(canAccess(account("admin"), "/app")).toBe(true)
    expect(canAccess(account("admin"), "/app/payroll")).toBe(true)
  })

  // The specific bug this map was written to fix.
  it("keeps a client account out of /app entirely", () => {
    const client = account("client", { client_id: 3 })
    for (const path of ["/app", "/app/payroll", "/app/workers", "/app/settings"]) {
      expect(canAccess(client, path)).toBe(false)
    }
  })

  it("keeps a worker account out of /app entirely", () => {
    const worker = account("employee", { worker_id: 7 })
    expect(canAccess(worker, "/app")).toBe(false)
    expect(canAccess(worker, "/app/workers")).toBe(false)
  })

  it("holds pay and money behind the office roles", () => {
    for (const path of ["/app/payroll", "/app/invoicing", "/app/quotes", "/app/reports"]) {
      expect(canAccess(account("employee"), path)).toBe(false)
      expect(canAccess(account("editor"), path)).toBe(false)
      expect(canAccess(account("manager"), path)).toBe(true)
    }
  })

  it("holds the shared mailbox behind the office roles", () => {
    expect(canAccess(account("employee"), "/app/inbox")).toBe(false)
    expect(canAccess(account("editor"), "/app/inbox")).toBe(false)
    expect(canAccess(account("manager"), "/app/inbox")).toBe(true)
  })

  it("lets editors at the content surfaces and nothing else", () => {
    const editor = account("editor")
    expect(canAccess(editor, "/app/blog")).toBe(true)
    expect(canAccess(editor, "/app/testimonials")).toBe(true)
    expect(canAccess(editor, "/app/content-studio")).toBe(true)
    expect(canAccess(editor, "/app/payroll")).toBe(false)
    expect(canAccess(editor, "/app/clients")).toBe(false)
  })

  it("gives every internal role the pages they need to work", () => {
    for (const path of ["/app", "/app/notifications", "/app/help", "/app/profile"]) {
      for (const role of ["super_admin", "admin", "manager", "trainer", "editor", "employee"] as Role[]) {
        expect(canAccess(account(role), path)).toBe(true)
      }
    }
  })

  it("gates the portal to accounts that have a client record", () => {
    expect(canAccess(account("client", { client_id: 3 }), "/portal")).toBe(true)
    // An office account without a client_id would see an empty portal, because
    // portal.php scopes every query to that link.
    expect(canAccess(account("admin"), "/portal")).toBe(false)
    expect(canAccess(account("employee", { worker_id: 7 }), "/portal")).toBe(false)
  })

  it("gates the worker app to accounts that have a worker record", () => {
    expect(canAccess(account("employee", { worker_id: 7 }), "/m")).toBe(true)
    expect(canAccess(account("admin"), "/m")).toBe(false)
    expect(canAccess(account("client", { client_id: 3 }), "/m")).toBe(false)
  })

  it("leaves ungoverned paths to the server", () => {
    for (const role of ALL_ROLES) {
      expect(canAccess(account(role), "/about")).toBe(true)
    }
  })

  it("never grants a client role anything under /app, whatever the map says", () => {
    // Belt and braces: the isStaff gate runs before the role list is consulted.
    const client = account("client")
    for (const path of ["/app/help", "/app/profile", "/app/notifications"]) {
      expect(canAccess(client, path)).toBe(false)
    }
  })
})

describe("homeFor", () => {
  it("sends a client account to the portal", () => {
    expect(homeFor(account("client"))).toBe("/portal")
    // A client_id decides it even when the role does not say "client".
    expect(homeFor(account("employee", { client_id: 4 }))).toBe("/portal")
  })

  it("sends a worker-linked account to the worker app", () => {
    expect(homeFor(account("employee", { worker_id: 9 }))).toBe("/m")
  })

  it("sends internal staff to the office", () => {
    for (const role of ["super_admin", "admin", "manager", "trainer", "editor", "employee"] as Role[]) {
      expect(homeFor(account(role))).toBe("/app")
    }
  })

  it("prefers the portal when an account is linked to both", () => {
    // Rare, but it must be decided rather than left to ordering: a client
    // contact who also holds a worker record is a client first.
    expect(homeFor(account("client", { client_id: 1, worker_id: 2 }))).toBe("/portal")
  })

  it("only ever lands somewhere the account may actually go", () => {
    for (const role of ALL_ROLES) {
      for (const extra of [{}, { client_id: 1 }, { worker_id: 1 }]) {
        const user = account(role, extra)
        expect(canAccess(user, homeFor(user))).toBe(true)
      }
    }
  })
})
