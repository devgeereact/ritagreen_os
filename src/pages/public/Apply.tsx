import { useState } from "react"
import { CheckCircle2 } from "lucide-react"
import { Button } from "@/components/ui/button"
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

export default function Apply() {
  useSeo({
    title: "Work with RitaGreen — care, cleaning, security and training jobs in London",
    description:
      "Apply to join RitaGreen. Care, cleaning, security and training roles across London — DBS-checked, trained and supported from day one.",
    path: "/apply",
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
      setError("Please agree to be contacted about your application.")
      return
    }
    setSubmitting(true)
    try {
      await api("ats.php", { method: "POST", body: payload, auth: false })
      setDone(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong. Try again.")
    } finally {
      setSubmitting(false)
    }
  }

  if (done) {
    return (
      <div className="mx-auto max-w-2xl px-4 py-20 text-center">
        <CheckCircle2 className="mx-auto size-12 text-primary" />
        <h1 className="mt-4 text-2xl font-bold">Application received</h1>
        <p className="mt-2 text-muted-foreground">Thanks for applying to RitaGreen. Our team will review and be in touch.</p>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-2xl px-4 py-12">
      <h1 className="text-3xl font-bold tracking-tight">Join RitaGreen</h1>
      <p className="mt-2 text-muted-foreground">Care, training, cleaning or security — tell us where you'd like to work and we'll be in touch.</p>

      <form onSubmit={onSubmit} className="mt-8 grid gap-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="grid gap-2"><Label htmlFor="name">Full name</Label><Input id="name" name="name" required /></div>
          <div className="grid gap-2"><Label htmlFor="email">Email</Label><Input id="email" name="email" type="email" required /></div>
        </div>
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
        <div className="grid gap-2"><Label htmlFor="role_applied">Role you're after</Label><Input id="role_applied" name="role_applied" placeholder="e.g. Carer, SIA guard, cleaner" /></div>

        <label className="flex items-start gap-2 text-sm text-muted-foreground">
          <Checkbox checked={consent} onCheckedChange={(v) => setConsent(!!v)} className="mt-0.5" />
          <span>I agree that RitaGreen may store these details and contact me about my application, per the privacy policy.</span>
        </label>

        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <Button type="submit" size="lg" disabled={submitting} className="justify-self-start">{submitting ? "Sending…" : "Submit application"}</Button>
      </form>
    </div>
  )
}
