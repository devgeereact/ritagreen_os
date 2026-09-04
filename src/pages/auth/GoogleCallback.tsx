import { useEffect, useRef, useState } from "react"
import { Link, useNavigate, useSearchParams } from "react-router-dom"
import { Loader2, ShieldCheck } from "lucide-react"
import { api, ApiError } from "@/lib/api"
import { useAuth, homeFor, type User } from "@/providers/auth-provider"
import { useSeo } from "@/hooks/use-seo"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"

type CallbackResult = {
  token?: string
  user?: User
  twofa_required?: boolean
  ticket?: string
}

export default function GoogleCallback() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { adoptSession } = useAuth()
  const [error, setError] = useState<string | null>(null)
  // Set when the account has an authenticator enrolled: Google verified the
  // email, but the second factor is still owed. The ticket stands in for the
  // authorization code, which Google has already spent by this point.
  const [ticket, setTicket] = useState<string | null>(null)
  const [code, setCode] = useState("")
  const [verifying, setVerifying] = useState(false)
  const ran = useRef(false)

  useSeo({
    title: "Signing in",
    description: "Completing sign-in to the RitaGreen workforce platform.",
    path: "/auth/google/callback",
  })

  useEffect(() => {
    if (ran.current) return // StrictMode double-invoke guard (codes are single-use)
    ran.current = true
    const authCode = params.get("code")
    const state = params.get("state")
    const gErr = params.get("error")
    if (gErr || !authCode) {
      setError("Google sign-in was cancelled.")
      return
    }
    api<CallbackResult>("auth.php?action=google_callback", {
      method: "POST",
      body: { code: authCode, state },
      auth: false,
    })
      .then((res) => {
        if (res.twofa_required && res.ticket) {
          setTicket(res.ticket)
          return
        }
        adoptSession(res.token!, res.user!)
        navigate(homeFor(res.user!), { replace: true })
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : "Google sign-in failed."))
  }, [params, navigate, adoptSession])

  async function submitCode(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    setVerifying(true)
    try {
      const res = await api<{ token: string; user: User }>("auth.php?action=google_2fa", {
        method: "POST",
        body: { ticket, code },
        auth: false,
      })
      adoptSession(res.token, res.user)
      navigate(homeFor(res.user), { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That code could not be checked.")
    } finally {
      setVerifying(false)
    }
  }

  if (ticket) {
    return (
      <div className="flex min-h-svh items-center justify-center bg-muted px-6">
        <form onSubmit={submitCode} className="w-full max-w-sm rounded-2xl border bg-card p-6 shadow-soft">
          <ShieldCheck className="size-6 text-primary" />
          <h1 className="mt-3 text-xl font-bold tracking-tight">One more step</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Enter the six-digit code from your authenticator app to finish signing in.
          </p>
          <div className="mt-5 grid gap-2">
            <Label htmlFor="code">Authentication code</Label>
            <Input
              id="code"
              name="code"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              placeholder="000000"
              autoFocus
              required
            />
          </div>
          {error && (
            <p role="alert" className="mt-3 text-sm text-destructive">
              {error}
            </p>
          )}
          <Button type="submit" className="mt-5 w-full" disabled={verifying || code.length < 6}>
            {verifying ? "Checking…" : "Verify and sign in"}
          </Button>
          <Link to="/login" className="mt-4 block text-center text-sm text-primary hover:underline">
            ← Back to sign in
          </Link>
        </form>
      </div>
    )
  }

  return (
    <div className="flex min-h-svh flex-col items-center justify-center gap-3 bg-muted px-6 text-center">
      {error ? (
        <>
          <p role="alert" className="font-medium text-destructive">
            {error}
          </p>
          <Link to="/login" className="text-sm text-primary hover:underline">← Back to sign in</Link>
        </>
      ) : (
        <>
          <Loader2 className="size-6 animate-spin text-muted-foreground" />
          <p className="text-sm text-muted-foreground">Completing Google sign-in…</p>
        </>
      )}
    </div>
  )
}
