import { useEffect, useState, type ReactNode } from "react"
import { Navigate, useLocation } from "react-router-dom"
import { useAuth as useClerkAuth } from "@clerk/clerk-react"
import { Button } from "@/components/ui/button"
import { api } from "@/lib/api"
import { clerkSignOut } from "@/lib/clerk"
import { useAuth, type User } from "@/providers/auth-provider"

/**
 * Resolves the signed-in Clerk person to a RitaGreenOS user (auth.php?action=me) before the
 * existing role gate runs. Being signed in to Clerk is not enough: the email must also belong to an
 * active user (the API answers 401 otherwise), and then we say so instead of looping.
 */
export default function ClerkGate({ children }: { children: ReactNode }) {
  const { isLoaded, isSignedIn } = useClerkAuth()
  const { user, adoptClerkUser } = useAuth()
  const location = useLocation()
  const [denied, setDenied] = useState(false)

  useEffect(() => {
    if (!isLoaded) return
    if (!isSignedIn) {
      adoptClerkUser(null)
      return
    }
    let alive = true
    api<{ user: User }>("auth.php?action=me")
      .then((res) => {
        if (!alive) return
        setDenied(false)
        adoptClerkUser(res.user)
      })
      .catch(() => alive && setDenied(true))
    return () => {
      alive = false
    }
  }, [isLoaded, isSignedIn, adoptClerkUser])

  const wait = <div className="flex min-h-svh items-center justify-center text-muted-foreground">Loading…</div>
  if (!isLoaded) return wait
  if (!isSignedIn) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (denied) {
    return (
      <div className="flex min-h-svh items-center justify-center p-6 text-center">
        <div className="max-w-sm">
          <h1 className="text-xl font-semibold">No access yet</h1>
          <p className="mt-2 text-sm text-muted-foreground">
            You are signed in, but this email is not set up in RitaGreenOS. Ask an administrator to add it, then sign in again.
          </p>
          <Button variant="outline" className="mt-4" onClick={() => clerkSignOut().then(() => setDenied(false))}>
            Sign out
          </Button>
        </div>
      </div>
    )
  }
  if (!user) return wait
  return <>{children}</>
}
