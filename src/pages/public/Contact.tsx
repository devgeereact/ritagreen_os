import { useState } from "react"
import { Mail, Phone, MapPin, Clock } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { PageHeader, Section } from "@/components/public/section"
import { Faqs } from "@/components/public/Faqs"
import { api, ApiError } from "@/lib/api"
import { DIVISIONS } from "@/lib/divisions"
import { COMPANY, FAQS } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"

const NEXT_STEPS = [
  "We read your enquiry and route it to the right division.",
  "A named contact replies — usually the same working day.",
  "We confirm scope, availability and rates in writing.",
  "We vet, brief and send the right people, then keep cover steady.",
]

export default function Contact() {
  useSeo({
    title: "Contact RitaGreen — tell us what you need",
    description:
      "One enquiry reaches the right division — care staff, training, cleaning or security. We'll get the right people ready, usually the same day. Call or send a message.",
    path: "/contact",
  })

  const [submitting, setSubmitting] = useState(false)
  const [division, setDivision] = useState("")

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = e.currentTarget
    const data = new FormData(form)
    const payload = {
      name: String(data.get("name") || "").trim(),
      email: String(data.get("email") || "").trim(),
      phone: String(data.get("phone") || "").trim(),
      division,
      message: String(data.get("message") || "").trim(),
    }
    if (!payload.name || !payload.email || !payload.message) {
      toast.error("Please add your name, email and a message.")
      return
    }

    setSubmitting(true)
    try {
      await api("contact.php", { method: "POST", body: payload, auth: false })
      toast.success("Thanks. We've received your enquiry and will be in touch shortly.")
      form.reset()
      setDivision("")
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
        eyebrow="Contact"
        title="Tell us what you need, and when"
        intro="One enquiry reaches the right division — staff, training, cleaning or security. We'll get the right people ready, usually the same day."
      />
      <Section className="grid gap-12 md:grid-cols-3">
        {/* Form */}
        <form onSubmit={onSubmit} className="space-y-5 md:col-span-2" noValidate>
          <div className="grid gap-5 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="name">Name</Label>
              <Input id="name" name="name" required autoComplete="name" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="email">Email</Label>
              <Input id="email" name="email" type="email" required autoComplete="email" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="phone">Phone (optional)</Label>
              <Input id="phone" name="phone" type="tel" autoComplete="tel" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="division">What is this about?</Label>
              <Select value={division} onValueChange={setDivision}>
                <SelectTrigger id="division">
                  <SelectValue placeholder="Choose a service" />
                </SelectTrigger>
                <SelectContent>
                  {DIVISIONS.map((d) => (
                    <SelectItem key={d.id} value={d.id}>
                      {d.name}
                    </SelectItem>
                  ))}
                  <SelectItem value="other">Something else</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="message">Message</Label>
            <Textarea
              id="message"
              name="message"
              rows={5}
              required
              placeholder="Tell us the role, the site and the times you need covered."
            />
          </div>
          <Button type="submit" size="lg" disabled={submitting}>
            {submitting ? "Sending…" : "Send enquiry"}
          </Button>
          <p className="text-xs text-muted-foreground">
            By sending this you agree we may contact you about your enquiry. We handle your details
            under our{" "}
            <a href="/privacy" className="font-medium text-primary hover:underline">privacy policy</a>.
          </p>
        </form>

        {/* Aside */}
        <aside className="space-y-5 text-sm">
          <div className="surface p-5">
            <h2 className="font-semibold">Get in touch</h2>
            <ul className="mt-3 space-y-3 text-muted-foreground">
              <li>
                <a href={COMPANY.phoneHref} className="flex items-center gap-3 hover:text-foreground">
                  <Phone className="size-4 text-primary" /> {COMPANY.phone}
                </a>
              </li>
              <li>
                <a href={`mailto:${COMPANY.email}`} className="flex items-center gap-3 hover:text-foreground">
                  <Mail className="size-4 text-primary" /> {COMPANY.email}
                </a>
              </li>
              <li className="flex items-start gap-3"><MapPin className="mt-0.5 size-4 text-primary" /> {COMPANY.area}</li>
              <li className="flex items-start gap-3"><Clock className="mt-0.5 size-4 text-primary" /> {COMPANY.hours}</li>
            </ul>
          </div>

          <div className="surface bg-soft-mesh p-5">
            <h2 className="font-semibold">What happens next</h2>
            <ol className="mt-4 space-y-3 text-muted-foreground">
              {NEXT_STEPS.map((s, i) => (
                <li key={s} className="flex items-start gap-3">
                  <span className="grid size-6 shrink-0 place-items-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">
                    {i + 1}
                  </span>
                  <span className="leading-relaxed">{s}</span>
                </li>
              ))}
            </ol>
          </div>

          <div className="surface p-5 text-muted-foreground">
            <p>Already a client or worker? Use the member login to reach your account.</p>
            <a href="/login" className="mt-2 inline-block font-medium text-primary hover:underline">
              Member login
            </a>
          </div>
        </aside>
      </Section>

      {/* FAQ */}
      <section className="border-t border-border bg-soft-mesh">
        <div className="mx-auto max-w-3xl px-4 py-16 md:py-24">
          <p className="eyebrow">FAQ</p>
          <h2 className="mt-3 text-2xl font-bold md:text-3xl">Before you ask</h2>
          <p className="mt-4 leading-relaxed text-muted-foreground">
            A few answers to the questions we hear most. Anything else, just send a message.
          </p>
          <Faqs items={FAQS} className="mt-8" />
        </div>
      </section>
    </>
  )
}
