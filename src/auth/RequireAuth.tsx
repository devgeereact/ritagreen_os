import { Navigate, Outlet, useLocation } from "react-router-dom"
import { useAuth, type Role } from "@/providers/auth-provider"

// Gate for /app/*. Redirects unauthenticated users to /login (preserving the
// intended destination). Role gating here is a UX convenience only; every
// mutating endpoint re-checks the role server-side.
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

  if (roles && roles.length > 0 && !roles.includes(user.role)) {
    return <Navigate to="/app" replace />
  }

  return <Outlet />
}
