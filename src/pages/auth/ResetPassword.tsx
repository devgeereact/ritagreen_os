import { useState } from "react"
import { Link, useNavigate, useParams } from "react-router-dom"
import { CheckCircle2 } from "lucide-react"
import { toast } from "sonner"
import { Logo } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { api, ApiError } from "@/lib/api"
import { useSeo } from "@/hooks/use-seo"

export default function ResetPassword() {
  useSeo({
    title: "Choose a new password",
    description: "Set a new password for your RitaGreen account.",
    noindex: true,
  })

  const { token } = useParams()
  const navigate = useNavigate()
  const [submitting, setSubmitting] = useState(false)
  const [done, setDone] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setError(null)
    const form = new FormData(e.currentTarget)
    const password = String(form.get("password") || "")
    const confirm = String(form.get("confirm") || "")
    if (password.length < 8) {
      setError("Use at least 8 characters.")
      return
    }
    if (password !== confirm) {
      setError("Passwords don't match.")
      return
    }
    setSubmitting(true)
    try {
      await api("auth.php?action=reset", { method: "POST", body: { token, password }, auth: false })
      setDone(true)
      toast.success("Password updated. You can sign in now.")
      setTimeout(() => navigate("/login", { replace: true }), 1500)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not reset. The link may have expired.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-svh w-full items-center justify-center bg-muted p-6">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex justify-center"><Link to="/"><Logo /></Link></div>
        <Card>
          <CardHeader className="text-center">
            <CardTitle className="text-xl"><h1>Choose a new password</h1></CardTitle>
            <CardDescription>Set a password of at least 8 characters.</CardDescription>
          </CardHeader>
          <CardContent>
            {done ? (
              <div className="flex flex-col items-center gap-2 text-center">
                <CheckCircle2 className="size-10 text-primary" />
                <p className="text-sm text-muted-foreground">Password updated — taking you to sign in…</p>
              </div>
            ) : (
              <form onSubmit={onSubmit} className="grid gap-5" noValidate>
                {error && <p role="alert" className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>}
                <div className="grid gap-2">
                  <Label htmlFor="password">New password</Label>
                  <Input id="password" name="password" type="password" autoComplete="new-password" minLength={8} required />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="confirm">Confirm password</Label>
                  <Input id="confirm" name="confirm" type="password" autoComplete="new-password" minLength={8} required />
                </div>
                <Button type="submit" className="w-full" disabled={submitting}>
                  {submitting ? "Saving…" : "Reset password"}
                </Button>
              </form>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
