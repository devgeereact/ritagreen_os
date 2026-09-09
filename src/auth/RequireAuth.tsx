import { Navigate, Outlet, useLocation } from "react-router-dom"
import { useAuth, type Role } from "@/providers/auth-provider"
import { canAccess, homeFor } from "@/lib/access"

// Gate for the authenticated surfaces.
//
// Redirects anonymous visitors to /login, preserving where they were going. For
// a signed-in user it checks src/lib/access.ts — the same map the sidebar reads,
// so a link is never shown to someone who would be bounced off the page behind
// it. Someone who does not belong on a surface is sent to their own home rather
// than to a dead end: a client lands on /portal, a worker on /m.
//
// This is a user-experience boundary, not a security one. Every endpoint
// re-checks server-side; see docs/ARCHITECTURE.md.
export default function RequireAuth({ roles }: { roles?: Role[] }) {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) {
    return (
      <div className="flex min-h-svh items-center justify-center text-muted-foreground">
        Loading…
      </div>
    )
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  // An explicit roles prop wins; otherwise fall back to the shared route map.
  const permitted = roles && roles.length > 0
    ? roles.includes(user.role)
    : canAccess(user, location.pathname)

  if (!permitted) {
    const home = homeFor(user)
    // Never bounce someone to the page they are already on.
    return <Navigate to={home === location.pathname ? "/" : home} replace />
  }

  return <Outlet />
}
