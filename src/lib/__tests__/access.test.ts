import { describe, it, expect } from "vitest"
import { canAccess, isStaff, isClientAccount, isWorkerAccount, rolesFor, OFFICE, STAFF } from "@/lib/access"
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
    // "/app/workers" must not be matched by a rule for "/app/work".
    expect(rolesFor("/app/workers")).toEqual(STAFF)
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
