import { useState } from "react"
import { Link } from "react-router-dom"
import { CheckCircle2 } from "lucide-react"
import { Logo } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select"
import { api, ApiError } from "@/lib/api"
import { useSeo } from "@/hooks/use-seo"

const DIVS = [
  { value: "care", label: "Care" },
  { value: "academy", label: "Academy / training" },
  { value: "cleaning", label: "Cleaning" },
  { value: "security", label: "Security" },
]

export default function Register() {
  useSeo({
    title: "Create a worker account",
    description: "Register your details to work with RitaGreen.",
    noindex: true,
  })

  const [division, setDivision] = useState("care")
  const [consent, setConsent] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [done, setDone] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setError(null)
    const form = new FormData(e.currentTarget)
    const payload = {
      name: String(form.get("name") || "").trim(),
      email: String(form.get("email") || "").trim(),
      phone: String(form.get("phone") || "").trim(),
      role_applied: String(form.get("role_applied") || "").trim(),
      division,
      consent,
    }
    if (!payload.name || !payload.email) {
      setError("Add your name and email.")
      return
    }
    if (!consent) {
      setError("Please agree to be contacted.")
      return
    }
    setSubmitting(true)
    try {
      await api("auth.php?action=register", { method: "POST", body: payload, auth: false })
      setDone(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong. Try again.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-svh w-full items-center justify-center bg-muted p-6">
      <div className="w-full max-w-md">
        <div className="mb-6 flex justify-center"><Link to="/"><Logo /></Link></div>
        <Card>
          <CardHeader className="text-center">
            <CardTitle className="text-xl"><h1>Register your interest</h1></CardTitle>
            <CardDescription>Join the RitaGreen workforce — we'll review and get you onboarded.</CardDescription>
          </CardHeader>
          <CardContent>
            {done ? (
              <div className="flex flex-col items-center gap-2 py-4 text-center">
                <CheckCircle2 className="size-10 text-primary" />
                <p className="text-sm text-muted-foreground">Thanks for registering. Our team will review your details and be in touch.</p>
                <Link to="/login" className="mt-2 text-sm text-primary hover:underline">Back to sign in</Link>
              </div>
            ) : (
              <form onSubmit={onSubmit} className="grid gap-4" noValidate>
                {error && <p role="alert" className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>}
                <div className="grid gap-2"><Label htmlFor="name">Full name</Label><Input id="name" name="name" required /></div>
                <div className="grid gap-2"><Label htmlFor="email">Email</Label><Input id="email" name="email" type="email" required /></div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="grid gap-2"><Label htmlFor="phone">Phone</Label><Input id="phone" name="phone" type="tel" /></div>
                  <div className="grid gap-2">
                    <Label htmlFor="division">Division</Label>
                    <Select value={division} onValueChange={setDivision}>
                      <SelectTrigger id="division"><SelectValue /></SelectTrigger>
                      <SelectContent>{DIVS.map((d) => <SelectItem key={d.value} value={d.value}>{d.label}</SelectItem>)}</SelectContent>
                    </Select>
                  </div>
                </div>
                <div className="grid gap-2"><Label htmlFor="role_applied">Role you're after</Label><Input id="role_applied" name="role_applied" placeholder="e.g. Carer, SIA guard" /></div>
                <label className="flex items-start gap-2 text-sm text-muted-foreground">
                  <Checkbox checked={consent} onCheckedChange={(v) => setConsent(!!v)} className="mt-0.5" />
                  <span>
                    I agree that RitaGreen may store these details and contact me about
                    registering, as described in the{" "}
                    {/* A Link, not plain text. Asking somebody to agree to a policy and
                  then not letting them read it is not asking. A full page load
                  would also throw away everything typed into the form. */}
                    <Link to="/privacy" className="font-medium text-primary hover:underline">
                      privacy notice
                    </Link>
                    .
                  </span>
                </label>
                <Button type="submit" disabled={submitting}>{submitting ? "Submitting…" : "Register"}</Button>
              </form>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
