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
import { api, ApiError } from "@/lib/api"
import { DIVISIONS } from "@/lib/divisions"

export default function Contact() {
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
        title="Tell us what you need"
        intro="One enquiry reaches the right division. Staff, training, cleaning or security, start here."
      />
      <Section className="grid gap-12 md:grid-cols-3">
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
            <Textarea id="message" name="message" rows={5} required />
          </div>
          <Button type="submit" size="lg" disabled={submitting}>
            {submitting ? "Sending…" : "Send enquiry"}
          </Button>
        </form>

        <aside className="space-y-5 text-sm">
          <div className="rounded-xl border border-border bg-card p-5">
            <h2 className="font-semibold">Get in touch</h2>
            <ul className="mt-3 space-y-3 text-muted-foreground">
              <li className="flex items-center gap-3"><Phone className="size-4 text-primary" /> 020 0000 0000</li>
              <li className="flex items-center gap-3"><Mail className="size-4 text-primary" /> hello@ritagreenservices.com</li>
              <li className="flex items-start gap-3"><MapPin className="mt-0.5 size-4 text-primary" /> London, United Kingdom</li>
              <li className="flex items-center gap-3"><Clock className="size-4 text-primary" /> Mon–Fri, 9am–5pm</li>
            </ul>
          </div>
          <div className="rounded-xl border border-border bg-card p-5 text-muted-foreground">
            <p>Already a client or worker? Use the member login to reach your account.</p>
            <a href="/login" className="mt-2 inline-block font-medium text-primary hover:underline">
              Member login
            </a>
          </div>
        </aside>
      </Section>
    </>
  )
}
