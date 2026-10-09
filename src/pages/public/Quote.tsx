import { useState } from "react"
import { Link, useSearchParams } from "react-router-dom"
import { Check, ShieldCheck, Clock, FileText } from "lucide-react"
import { toast } from "sonner"
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
import { PageHeader, Section } from "@/components/public/section"
import { DIVISIONS } from "@/lib/divisions"
import { COMPANY, ENGAGEMENTS } from "@/lib/company"
import { api, ApiError } from "@/lib/api"
import { useSeo } from "@/hooks/use-seo"

const INCLUDED = [
  "Fully vetted workers — enhanced DBS, references and right-to-work",
  "Role-appropriate qualifications and training, evidenced before the first shift",
  "A named RitaGreen contact for the duration of the booking",
  "Public liability and employers' liability cover; certificates on request",
]

const STEPS = [
  { icon: FileText, title: "You send the details", body: "The more you tell us, the faster and tighter the quote." },
  { icon: Clock, title: "We reply in writing", body: "A named contact confirms scope and rates — usually the same working day." },
  { icon: ShieldCheck, title: "We vet and cover", body: "On acceptance we vet, brief and send the right people, then keep cover steady." },
]

const VALID_SERVICE = new Set(DIVISIONS.map((d) => d.id))

export default function Quote() {
  useSeo({
    title: "Request a quote — care, training, cleaning or security",
    description:
      "Tell us what you need and we'll send a written quote, usually the same working day. Fully vetted workers, a named contact, and cover you can rely on.",
    path: "/quote",
  })

  const [params] = useSearchParams()
  const initialService = params.get("service") ?? ""
  const [service, setService] = useState(VALID_SERVICE.has(initialService) ? initialService : "")
  const [engagement, setEngagement] = useState("")
  const [submitting, setSubmitting] = useState(false)
  // Unticked, and separate from the quote request itself.
  const [marketing, setMarketing] = useState(false)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = e.currentTarget
    const data = new FormData(form)
    const name = String(data.get("name") || "").trim()
    const org = String(data.get("org") || "").trim()
    const email = String(data.get("email") || "").trim()
    const phone = String(data.get("phone") || "").trim()
    const location = String(data.get("location") || "").trim()
    const startDate = String(data.get("startDate") || "").trim()
    const scale = String(data.get("scale") || "").trim()
    const notes = String(data.get("notes") || "").trim()

    if (!name || !email || !service || !notes) {
      toast.error("Please add your name, email, the service, and what you need.")
      return
    }

    const serviceName = DIVISIONS.find((d) => d.id === service)?.name ?? service
    const engagementName = ENGAGEMENTS.find((en) => en.name === engagement)?.name ?? "Not sure yet"

    // Reuse the public enquiry endpoint — quote requests land in the same inbox,
    // tagged to the right division, with the brief composed into the message.
    const message =
      `QUOTE REQUEST\n` +
      `Service: ${serviceName}\n` +
      `Organisation: ${org || "—"}\n` +
      `Location: ${location || "—"}\n` +
      `Start / when: ${startDate || "—"}\n` +
      `Engagement: ${engagementName}\n` +
      `Scale (hours / headcount): ${scale || "—"}\n\n` +
      `Details:\n${notes}`

    // contact.php refuses a message over 5000 characters. Say so here, before
    // the request, rather than after it.
    if (message.length > 5000) {
      toast.error("That request is too long. Please shorten the details and try again.")
      return
    }

    setSubmitting(true)
    try {
      await api("contact.php", {
        method: "POST",
        body: { name, email, phone, division: service, message, marketing_consent: marketing, source: "quote" },
        auth: false,
      })
      toast.success("Thanks. We've got your request and will send a written quote shortly.")
      form.reset()
      setEngagement("")
      setMarketing(false)
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : "Something went wrong. Please try again."
      toast.error(msg)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <>
      <PageHeader
        eyebrow="Request a quote"
        title="Tell us what you need — get it in writing"
        intro="A few details and we'll send a clear, written quote, usually the same working day. No obligation, no hard sell — just the right people at a fair, confirmed rate."
      />

      <Section className="grid gap-12 md:grid-cols-3">
        {/* Form. maxLength values mirror the caps in server/contact.php; the notes
            field leaves room for the brief composed around it in onSubmit. */}
        <form onSubmit={onSubmit} className="space-y-5 md:col-span-2" noValidate>
          <div className="grid gap-5 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="name">Your name</Label>
              <Input id="name" name="name" required maxLength={160} autoComplete="name" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="org">Organisation (optional)</Label>
              <Input id="org" name="org" autoComplete="organization" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="email">Email</Label>
              <Input id="email" name="email" type="email" required maxLength={190} autoComplete="email" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="phone">Phone (optional)</Label>
              <Input id="phone" name="phone" type="tel" maxLength={40} autoComplete="tel" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="service">Service</Label>
              <Select value={service} onValueChange={setService}>
                <SelectTrigger id="service">
                  <SelectValue placeholder="Choose a service" />
                </SelectTrigger>
                <SelectContent>
                  {DIVISIONS.map((d) => (
                    <SelectItem key={d.id} value={d.id}>
                      {d.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="engagement">How often?</Label>
              <Select value={engagement} onValueChange={setEngagement}>
                <SelectTrigger id="engagement">
                  <SelectValue placeholder="Not sure yet" />
                </SelectTrigger>
                <SelectContent>
                  {ENGAGEMENTS.map((en) => (
                    <SelectItem key={en.name} value={en.name}>
                      {en.name} — {en.bestFor}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="location">Location / postcode</Label>
              <Input id="location" name="location" placeholder="e.g. SE1, or a site address" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="startDate">When do you need it?</Label>
              <Input id="startDate" name="startDate" placeholder="e.g. ASAP, or a start date" />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="scale">Scale — hours, shifts or headcount (optional)</Label>
            <Input id="scale" name="scale" placeholder="e.g. 2 carers, day shifts, ~40 hrs/week" />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="notes">What do you need?</Label>
            <Textarea
              id="notes"
              name="notes"
              rows={5}
              required
              maxLength={4000}
              placeholder="Tell us the role, the setting and anything that affects the quote — nights, weekends, access, special requirements."
            />
          </div>
          {/* Optional, unticked, and it does not gate the quote. */}
          <label className="flex items-start gap-2 text-sm text-muted-foreground">
            <Checkbox
              checked={marketing}
              onCheckedChange={(v) => setMarketing(!!v)}
              className="mt-0.5"
            />
            <span>
              Optional: email me occasionally about RitaGreen services, training and
              availability. You can unsubscribe from any of those emails.
            </span>
          </label>
          <Button type="submit" size="lg" disabled={submitting}>
            {submitting ? "Sending…" : "Request quote"}
          </Button>
          <p className="text-xs text-muted-foreground">
            Rates are confirmed per engagement and set out clearly in your quote. We use your
            details to prepare and send it, which is not marketing and does not need the box
            above. See our{" "}
            <Link to="/privacy" className="font-medium text-primary hover:underline">privacy notice</Link>.
          </p>
        </form>

        {/* Aside */}
        <aside className="space-y-5 text-sm">
          <div className="surface p-5">
            <h2 className="font-semibold">What's included</h2>
            <ul className="mt-3 space-y-2">
              {INCLUDED.map((i) => (
                <li key={i} className="flex items-start gap-2 text-muted-foreground">
                  <Check className="mt-0.5 size-4 shrink-0 text-primary" /> {i}
                </li>
              ))}
            </ul>
          </div>
          <div className="surface bg-soft-mesh p-5">
            <h2 className="font-semibold">What happens next</h2>
            <ol className="mt-4 space-y-4">
              {STEPS.map((s) => (
                <li key={s.title} className="flex gap-3">
                  <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary ring-1 ring-inset ring-primary/10">
                    <s.icon className="size-4" strokeWidth={1.75} />
                  </span>
                  <div>
                    <p className="font-medium text-foreground">{s.title}</p>
                    <p className="mt-0.5 leading-relaxed text-muted-foreground">{s.body}</p>
                  </div>
                </li>
              ))}
            </ol>
          </div>
          <div className="surface p-5 text-muted-foreground">
            <p>Prefer to talk it through first?</p>
            <a href={COMPANY.phoneHref} className="mt-1 block font-medium text-primary hover:underline">
              {COMPANY.phone}
            </a>
            <Link to="/contact" className="mt-1 inline-block font-medium text-primary hover:underline">
              Send a general enquiry
            </Link>
          </div>
        </aside>
      </Section>
    </>
  )
}
