import { useState } from "react"
import { Link } from "react-router-dom"
import { CheckCircle2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { api, ApiError } from "@/lib/api"
import { useSeo } from "@/hooks/use-seo"

const SERVICES = [
  { value: "care", label: "Healthcare staff" },
  { value: "cleaning", label: "Cleaning" },
  { value: "security", label: "Security" },
  { value: "academy", label: "Training" },
]

export default function RequestCover() {
  useSeo({
    title: "Request cover — care, cleaning or security staff in London",
    description:
      "Tell us the role, the site and the times. We match a qualified, checked worker and confirm fast — same-day where we can.",
    path: "/request",
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
      contact_name: String(form.get("contact_name") || "").trim(),
      email: String(form.get("email") || "").trim(),
      phone: String(form.get("phone") || "").trim(),
      division,
      site_name: String(form.get("site_name") || "").trim(),
      detail: String(form.get("detail") || "").trim(),
      preferred_start: String(form.get("preferred_start") || ""),
      consent,
    }
    if (!payload.contact_name || !payload.email || !payload.detail) {
      setError("Please add your name, email and what you need.")
      return
    }
    if (!consent) {
      setError("Please agree to be contacted about your request.")
      return
    }
    setSubmitting(true)
    try {
      await api("bookings.php", { method: "POST", body: payload, auth: false })
      setDone(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong. Please try again.")
    } finally {
      setSubmitting(false)
    }
  }

  if (done) {
    return (
      <div className="mx-auto max-w-2xl px-4 py-20 text-center">
        <CheckCircle2 className="mx-auto size-12 text-primary" />
        <h1 className="mt-4 text-2xl font-bold">Request received</h1>
        <p className="mt-2 text-muted-foreground">
          Thanks — our coordinators have your request and will be in touch shortly to arrange cover.
        </p>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-2xl px-4 py-12">
      <h1 className="text-3xl font-bold tracking-tight">Request cover</h1>
      <p className="mt-2 text-muted-foreground">
        Tell us what you need and when. We'll match a qualified, checked worker and confirm fast — same-day where we can.
      </p>

      <form onSubmit={onSubmit} className="mt-8 grid gap-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="grid gap-2">
            <Label htmlFor="contact_name">Your name</Label>
            <Input id="contact_name" name="contact_name" required />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="email">Email</Label>
            <Input id="email" name="email" type="email" required />
          </div>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="grid gap-2">
            <Label htmlFor="phone">Phone</Label>
            <Input id="phone" name="phone" type="tel" />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="division">Service needed</Label>
            <Select value={division} onValueChange={setDivision}>
              <SelectTrigger id="division"><SelectValue /></SelectTrigger>
              <SelectContent>
                {SERVICES.map((s) => <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="grid gap-2">
            <Label htmlFor="site_name">Site / location</Label>
            <Input id="site_name" name="site_name" placeholder="e.g. Rosewood Care Home" />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="preferred_start">Preferred start</Label>
            <Input id="preferred_start" name="preferred_start" type="datetime-local" />
          </div>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="detail">What do you need?</Label>
          <Textarea id="detail" name="detail" rows={5} placeholder="e.g. Two carers for a night shift, dementia experience preferred." required />
        </div>

        <label className="flex items-start gap-2 text-sm text-muted-foreground">
          <Checkbox checked={consent} onCheckedChange={(v) => setConsent(!!v)} className="mt-0.5" />
          <span>
            I agree that RitaGreen may contact me about this request and store these
            details, as described in the{" "}
            {/* A Link, not plain text. Asking somebody to agree to a policy and
                  then not letting them read it is not asking. A full page load
                  would also throw away everything typed into the form. */}
            <Link to="/privacy" className="font-medium text-primary hover:underline">
              privacy notice
            </Link>
            .
          </span>
        </label>

        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}

        <Button type="submit" size="lg" disabled={submitting} className="justify-self-start">
          {submitting ? "Sending…" : "Send request"}
        </Button>
      </form>
    </div>
  )
}
