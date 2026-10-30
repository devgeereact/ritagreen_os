import { useEffect, useState } from "react"
import { Link, Navigate, useLocation } from "react-router-dom"
import { SignIn, useAuth as useClerkAuth } from "@clerk/clerk-react"
import { Logo } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"
import ClerkSession from "@/auth/ClerkSession"
import { api } from "@/lib/api"
import { clerkSignOut } from "@/lib/clerk"
import { homeFor } from "@/lib/access"
import { useAuth, type User } from "@/providers/auth-provider"

/** Email-code sign-in by Clerk, for the office, the worker app and the client portal alike. */
function Inner() {
  const { isLoaded, isSignedIn } = useClerkAuth()
  const { user, adoptClerkUser } = useAuth()
  const location = useLocation()
  const from = (location.state as { from?: string } | null)?.from
  const [denied, setDenied] = useState(false)

  useEffect(() => {
    if (!isLoaded || !isSignedIn) return
    let alive = true
    api<{ user: User }>("auth.php?action=me")
      .then((res) => alive && adoptClerkUser(res.user))
      .catch(() => alive && setDenied(true))
    return () => {
      alive = false
    }
  }, [isLoaded, isSignedIn, adoptClerkUser])

  if (isLoaded && isSignedIn && user && !denied) {
    return <Navigate to={from && from !== "/login" ? from : homeFor(user)} replace />
  }
  if (isSignedIn && denied) {
    return (
      <div className="text-center">
        <h1 className="text-xl font-semibold">No access yet</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          You are signed in, but this email is not set up in RitaGreenOS. Ask an administrator to add it, then sign in again.
        </p>
        <Button variant="outline" className="mt-4" onClick={() => clerkSignOut().then(() => setDenied(false))}>
          Sign out
        </Button>
      </div>
    )
  }
  if (!isLoaded || isSignedIn) return <p className="text-center text-sm text-muted-foreground">Loading…</p>
  return <SignIn routing="hash" />
}

export default function ClerkLogin() {
  return (
    <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted/30 p-6">
      <Link to="/"><Logo /></Link>
      <ClerkSession>
        <Inner />
      </ClerkSession>
    </div>
  )
}
