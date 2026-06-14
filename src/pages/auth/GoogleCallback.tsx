import { useEffect, useRef, useState } from "react"
import { Link, useNavigate, useSearchParams } from "react-router-dom"
import { Loader2 } from "lucide-react"
import { api, ApiError } from "@/lib/api"
import { useAuth, homeFor, type User } from "@/providers/auth-provider"

export default function GoogleCallback() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { adoptSession } = useAuth()
  const [error, setError] = useState<string | null>(null)
  const ran = useRef(false)

  useEffect(() => {
    if (ran.current) return // StrictMode double-invoke guard (codes are single-use)
    ran.current = true
    const code = params.get("code")
    const gErr = params.get("error")
    if (gErr || !code) {
      setError("Google sign-in was cancelled.")
      return
    }
    api<{ token: string; user: User }>("auth.php?action=google_callback", {
      method: "POST",
      body: { code },
      auth: false,
    })
      .then((res) => {
        adoptSession(res.token, res.user)
        navigate(homeFor(res.user), { replace: true })
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : "Google sign-in failed."))
  }, [params, navigate, adoptSession])

  return (
    <div className="flex min-h-svh flex-col items-center justify-center gap-3 bg-muted px-6 text-center">
      {error ? (
        <>
          <p className="font-medium text-destructive">{error}</p>
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
