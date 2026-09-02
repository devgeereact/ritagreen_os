import { useState } from "react"
import { Link } from "react-router-dom"
import { MailCheck } from "lucide-react"
import { Logo } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { api, ApiError } from "@/lib/api"
import { useSeo } from "@/hooks/use-seo"

export default function ForgotPassword() {
  useSeo({
    title: "Reset your password",
    description: "Request a password reset link for your RitaGreen account.",
    noindex: true,
  })

  const [submitting, setSubmitting] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setError(null)
    const email = String(new FormData(e.currentTarget).get("email") || "").trim()
    if (!email) {
      setError("Enter your email.")
      return
    }
    setSubmitting(true)
    try {
      await api("auth.php?action=forgot", { method: "POST", body: { email }, auth: false })
      setSent(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong. Try again.")
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
            <CardTitle className="text-xl">Reset password</CardTitle>
            <CardDescription>We'll email you a reset link.</CardDescription>
          </CardHeader>
          <CardContent>
            {sent ? (
              <div className="flex flex-col items-center gap-2 text-center">
                <MailCheck className="size-10 text-primary" />
                <p className="text-sm text-muted-foreground">
                  If that email is registered, a reset link is on its way. Check your inbox.
                </p>
              </div>
            ) : (
              <form onSubmit={onSubmit} className="grid gap-5" noValidate>
                {error && <p role="alert" className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>}
                <div className="grid gap-2">
                  <Label htmlFor="email">Email</Label>
                  <Input id="email" name="email" type="email" autoComplete="email" required />
                </div>
                <Button type="submit" className="w-full" disabled={submitting}>
                  {submitting ? "Sending…" : "Send reset link"}
                </Button>
              </form>
            )}
          </CardContent>
        </Card>
        <p className="mt-6 text-center text-sm text-muted-foreground">
          <Link to="/login" className="hover:text-foreground">← Back to sign in</Link>
        </p>
      </div>
    </div>
  )
}
