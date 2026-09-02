import { useEffect, useState } from "react"
import { Link, useLocation, useNavigate } from "react-router-dom"
import { Eye, EyeOff } from "lucide-react"
import { Logo } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useAuth, homeFor } from "@/providers/auth-provider"
import { api, ApiError } from "@/lib/api"
import { useSeo } from "@/hooks/use-seo"

export default function Login() {
  // A sign-in form is a step, not a destination: noindex, and no canonical.
  // Without this it inherited the homepage title AND the static canonical in
  // index.html, telling Google /login was a duplicate of the homepage.
  useSeo({
    title: "Sign in",
    description: "Sign in to the RitaGreen workforce platform.",
    noindex: true,
  })

  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const from = (location.state as { from?: string } | null)?.from || "/app"

  const [showPassword, setShowPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [googleEnabled, setGoogleEnabled] = useState(false)

  // Show the Google button only when SSO is configured server-side.
  useEffect(() => {
    api<{ enabled: boolean }>("auth.php?action=google_config", { auth: false })
      .then((r) => setGoogleEnabled(r.enabled))
      .catch(() => {})
  }, [])

  async function googleSignIn() {
    try {
      const r = await api<{ url: string }>("auth.php?action=google_start", { auth: false })
      window.location.href = r.url
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not start Google sign-in.")
    }
  }
  // Two-step: once the server says 2FA is required we keep the credentials and
  // ask for the authenticator code.
  const [twofa, setTwofa] = useState(false)
  const [creds, setCreds] = useState<{ email: string; password: string } | null>(null)
  const [code, setCode] = useState("")

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setError(null)
    const data = new FormData(e.currentTarget)
    const email = creds?.email || String(data.get("email") || "").trim()
    const password = creds?.password || String(data.get("password") || "")
    if (!email || !password) {
      setError("Enter your email and password.")
      return
    }
    if (twofa && !code) {
      setError("Enter your 6-digit authentication code.")
      return
    }
    setSubmitting(true)
    try {
      const res = await login(email, password, twofa ? code : undefined)
      if (res.twofaRequired) {
        setTwofa(true)
        setCreds({ email, password })
        setError(null)
        return
      }
      // Route by account type unless a specific protected page was requested.
      const dest = from && from !== "/app" ? from : res.user ? homeFor(res.user) : "/app"
      navigate(dest, { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not sign in. Please try again.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-svh w-full items-center justify-center bg-muted p-6">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex justify-center">
          <Link to="/"><Logo /></Link>
        </div>
        <Card>
          <CardHeader className="text-center">
            <CardTitle className="text-xl">Member login</CardTitle>
            <CardDescription>Sign in to the RitaGreen platform</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={onSubmit} className="grid gap-5" noValidate>
              {error && (
                <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
                  {error}
                </p>
              )}
              {!twofa ? (
                <>
                  <div className="grid gap-2">
                    <Label htmlFor="email">Email</Label>
                    <Input id="email" name="email" type="email" autoComplete="email" required />
                  </div>
                  <div className="grid gap-2">
                    <div className="flex items-center">
                      <Label htmlFor="password">Password</Label>
                      <Link
                        to="/forgot"
                        className="ml-auto text-xs text-muted-foreground underline-offset-4 hover:underline"
                      >
                        Forgot password?
                      </Link>
                    </div>
                    <div className="relative">
                      <Input
                        id="password"
                        name="password"
                        type={showPassword ? "text" : "password"}
                        autoComplete="current-password"
                        required
                        className="pr-10"
                      />
                      <button
                        type="button"
                        onClick={() => setShowPassword((s) => !s)}
                        className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        aria-label={showPassword ? "Hide password" : "Show password"}
                      >
                        {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                      </button>
                    </div>
                  </div>
                </>
              ) : (
                <div className="grid gap-2">
                  <Label htmlFor="code">Authentication code</Label>
                  <Input
                    id="code"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    placeholder="123456"
                    value={code}
                    onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
                    autoFocus
                    required
                  />
                  <p className="text-xs text-muted-foreground">Enter the 6-digit code from your authenticator app.</p>
                </div>
              )}
              <Button type="submit" className="w-full" disabled={submitting}>
                {submitting ? "Signing in…" : twofa ? "Verify & sign in" : "Sign in"}
              </Button>
            </form>

            {!twofa && googleEnabled && (
              <>
                <div className="my-5 flex items-center gap-3 text-xs text-muted-foreground">
                  <span className="h-px flex-1 bg-border" /> or <span className="h-px flex-1 bg-border" />
                </div>
                <Button type="button" variant="outline" className="w-full" onClick={googleSignIn}>
                  <svg className="mr-2 size-4" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1A11 11 0 0 0 2.18 7.06l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/></svg>
                  Continue with Google
                </Button>
              </>
            )}

            <p className="mt-6 text-center text-xs text-muted-foreground">
              New to RitaGreen? <Link to="/register" className="text-primary hover:underline">Register your interest</Link>.
            </p>
            <p className="mt-2 text-center text-xs text-muted-foreground">
              Trouble signing in? <Link to="/contact" className="text-primary hover:underline">Contact the office</Link>.
            </p>
          </CardContent>
        </Card>
        <p className="mt-6 text-center text-sm text-muted-foreground">
          <Link to="/" className="hover:text-foreground">← Back to website</Link>
        </p>
      </div>
    </div>
  )
}
