import { useState } from "react"
import { Link, useLocation, useNavigate } from "react-router-dom"
import { Eye, EyeOff } from "lucide-react"
import { Logo } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useAuth, homeFor } from "@/providers/auth-provider"
import { ApiError } from "@/lib/api"

export default function Login() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const from = (location.state as { from?: string } | null)?.from || "/app"

  const [showPassword, setShowPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
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
            <p className="mt-6 text-center text-xs text-muted-foreground">
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
