import type { Role, User } from "@/providers/auth-provider"

// Who may see what, in one place.
//
// This is the single source of truth for both the router guard and the sidebar,
// so a link can never appear for someone who would be bounced off the page it
// points at. It mirrors the require_role() calls in server/, which remain the
// only enforced boundary — this layer exists so the interface does not offer
// people work they cannot do.

/** Roles that run the office. Matches require_role(..., 'super_admin', 'admin', 'manager'). */
export const OFFICE: Role[] = ["super_admin", "admin", "manager"]

/** Every internal role, i.e. everyone who belongs in /app at all. */
export const STAFF: Role[] = [...OFFICE, "trainer", "editor", "employee"]

const OFFICE_PLUS_EDITOR: Role[] = [...OFFICE, "editor"]

/** The owner's roles: require_role($user, 'super_admin', 'admin') on the server. */
const OWNER: Role[] = ["super_admin", "admin"]
const OFFICE_PLUS_TRAINER: Role[] = [...OFFICE, "trainer"]

/**
 * Roles holding the 'compliance' zone in server/migrations/015.
 *
 * Editor is the one internal role without it: it is a content role, and vetting
 * records are not content. Mirrors can_view_zone($conn, $user, 'compliance').
 */
const COMPLIANCE: Role[] = [...OFFICE, "trainer", "employee"]

/**
 * Roles holding the 'payroll' zone in server/migrations/015.
 *
 * Only the office. Hours are what pay is calculated from, so the screens that
 * show them are payroll screens: server/timesheets.php and server/payroll.php
 * both call can_view_zone($conn, $user, 'payroll'), and this mirrors that. It
 * read as STAFF here while the server let any internal account read every
 * worker's hours, so the two agreed with each other and both were wrong.
 */
const PAYROLL: Role[] = [...OFFICE]

/**
 * Is this an internal staff account, as opposed to a worker or client login?
 *
 * Mirrors is_staff() in server/lib.php, and for the same reason: role alone
 * cannot answer it. A worker's login is an ordinary account with `worker_id`
 * set, and a client portal login has `client_id` set, so either may carry a
 * role like "employee". Privileged roles are staff unconditionally, so a
 * manager who also holds a worker record keeps their office access.
 */
export function isStaff(user: Pick<User, "role" | "client_id" | "worker_id">): boolean {
  if ((OFFICE as string[]).includes(user.role)) return true
  if (user.role === "client") return false
  if (user.client_id || user.worker_id) return false
  return true
}

/** Path prefix → the roles allowed to open it. Longest matching prefix wins. */
const ROUTE_ROLES: { path: string; roles: Role[] }[] = [
  // Operations
  { path: "/app/scheduling", roles: OFFICE },
  // /app/timetable is a redirect to /app/timesheets; the rule stays so the
  // redirect itself is behind the same gate as its destination.
  { path: "/app/timetable", roles: PAYROLL },
  { path: "/app/jobs", roles: STAFF },
  { path: "/app/field", roles: STAFF },
  { path: "/app/timesheets", roles: PAYROLL },
  { path: "/app/payroll", roles: PAYROLL },

  // People
  { path: "/app/workers", roles: COMPLIANCE },
  // The person profile. Same roles as server/people.php: no editor.
  { path: "/app/people", roles: COMPLIANCE },
  { path: "/app/compliance", roles: COMPLIANCE },
  { path: "/app/ats", roles: OFFICE_PLUS_TRAINER },
  { path: "/app/academy", roles: [...OFFICE, "trainer", "editor"] },

  // Quality
  { path: "/app/incidents", roles: OFFICE },

  // Clients and sales
  { path: "/app/clients", roles: OFFICE },
  { path: "/app/enquiries", roles: OFFICE },
  { path: "/app/bookings", roles: OFFICE },
  { path: "/app/quotes", roles: OFFICE },
  { path: "/app/contracts", roles: OFFICE },
  { path: "/app/invoicing", roles: OFFICE },

  // Insight
  { path: "/app/analytics", roles: OFFICE },
  { path: "/app/insights", roles: OFFICE },
  { path: "/app/reports", roles: OFFICE },

  // Comms and files
  { path: "/app/inbox", roles: OFFICE },
  { path: "/app/notifications", roles: STAFF },
  { path: "/app/comms", roles: OFFICE },
  { path: "/app/content-studio", roles: OFFICE_PLUS_EDITOR },
  { path: "/app/blog", roles: OFFICE_PLUS_EDITOR },
  { path: "/app/testimonials", roles: OFFICE_PLUS_EDITOR },
  // Website area (S7). Vacancies and the forms directory: the same roles as
  // server/vacancies.php and server/website-forms.php. Company details: owner
  // only, as server/company.php.
  { path: "/app/vacancies", roles: OFFICE_PLUS_EDITOR },
  { path: "/app/website-forms", roles: OFFICE_PLUS_EDITOR },
  { path: "/app/company", roles: OWNER },
  { path: "/app/files", roles: OFFICE_PLUS_EDITOR },

  // Admin and personal
  { path: "/app/settings", roles: OFFICE },
  { path: "/app/help", roles: STAFF },
  { path: "/app/profile", roles: STAFF },
  { path: "/app/smoke-test", roles: STAFF },

  // The command centre, and the /app shell itself. Keep last: as the shortest
  // prefix it would otherwise shadow every entry above.
  { path: "/app", roles: STAFF },
]

/** The roles allowed to open a path, or null if the path is not access-controlled. */
export function rolesFor(path: string): Role[] | null {
  let best: { path: string; roles: Role[] } | null = null
  for (const rule of ROUTE_ROLES) {
    if (path === rule.path || path.startsWith(rule.path + "/")) {
      if (!best || rule.path.length > best.path.length) best = rule
    }
  }
  return best ? best.roles : null
}

/** Is this login attached to a client record, i.e. a portal account? */
export function isClientAccount(user: Pick<User, "role" | "client_id">): boolean {
  return user.role === "client" || Boolean(user.client_id)
}

/** Is this login attached to a worker record, i.e. a worker app account? */
export function isWorkerAccount(user: Pick<User, "worker_id">): boolean {
  return Boolean(user.worker_id)
}

/**
 * Where a user lands after login, or on hitting the app, by account type.
 *
 * Here rather than in auth-provider.tsx: a module that exports both components
 * and plain functions loses fast refresh, and this is the same question as
 * isClientAccount()/isWorkerAccount() above — it belongs beside them.
 */
export function homeFor(user: Pick<User, "role" | "client_id" | "worker_id">): string {
  if (isClientAccount(user)) return "/portal"
  if (isWorkerAccount(user)) return "/m"
  return "/app"
}

/** May this user open this path? Unknown paths are allowed; the server still decides. */
export function canAccess(
  user: Pick<User, "role" | "client_id" | "worker_id"> | null,
  path: string,
): boolean {
  if (!user) return false

  // The client portal scopes every query to users.client_id, and the worker app
  // to users.worker_id. An account without the matching link would land on an
  // empty or erroring page, so send it home instead of showing it a dead end.
  if (path === "/portal" || path.startsWith("/portal/")) return isClientAccount(user)
  if (path === "/m" || path.startsWith("/m/")) return isWorkerAccount(user)

  if (path === "/app" || path.startsWith("/app/")) {
    if (!isStaff(user)) return false
  }

  const roles = rolesFor(path)
  if (!roles) return true
  return roles.includes(user.role)
}
