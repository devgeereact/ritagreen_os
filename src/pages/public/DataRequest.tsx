import { useState } from "react"
import { Link } from "react-router-dom"
import { ShieldCheck, Mail, Info } from "lucide-react"
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
import { COMPANY } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"

// The kinds of request the form offers. "Something else" is last and exists
// because this list is not the limit of what somebody is allowed to ask for.
const KINDS = [
  { value: "access", label: "A copy of the information you hold about me" },
  { value: "correction", label: "Correct something that is wrong" },
  { value: "deletion", label: "Delete information about me" },
  { value: "objection", label: "Object to how you are using it" },
  { value: "restriction", label: "Pause what you are doing with it" },
  { value: "portability", label: "A portable copy to take elsewhere" },
  { value: "withdraw_consent", label: "Withdraw consent I gave earlier" },
  { value: "other", label: "Something else" },
]

const STEPS = [
  "You send this form. We record it and give it a reference.",
  "We reply to the email address you give us, to confirm the request is really from you.",
  "We check what we hold, and tell you what we can do and what we cannot.",
  "We write back with the outcome, and keep a record of what was decided.",
]

export default function DataRequest() {
  useSeo({
    title: "Ask about your data — RitaGreen Services",
    description:
      "Ask for a copy of the information RitaGreen Services holds about you, have it corrected, or ask us to delete it.",
    path: "/data-request",
  })

  const [submitting, setSubmitting] = useState(false)
  const [kind, setKind] = useState("access")
  const [ref, setRef] = useState<string | null>(null)
  const [errors, setErrors] = useState<{ name?: string; email?: string }>({})

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = e.currentTarget
    const data = new FormData(form)
    const payload = {
      name: String(data.get("name") || "").trim(),
      email: String(data.get("email") || "").trim(),
      relationship: String(data.get("relationship") || "").trim(),
      detail: String(data.get("detail") || "").trim(),
      kind,
    }

    const next: typeof errors = {}
    if (!payload.name) next.name = "Please tell us your name."
    if (!payload.email) next.email = "We need an email address to reply to."
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(payload.email)) next.email = "That email doesn't look right."
    setErrors(next)
    if (Object.keys(next).length > 0) {
      form.querySelector<HTMLElement>("[aria-invalid='true']")?.focus()
      return
    }

    setSubmitting(true)
    try {
      const res = await api<{ ref: string }>("data-request.php", {
        method: "POST",
        body: payload,
        auth: false,
      })
      setRef(res.ref)
      form.reset()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Something went wrong. Please try again.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <>
      <PageHeader
        eyebrow="Your data"
        title="Ask us about the information we hold about you"
        intro="Whether you have worked for us, applied to us, trained with us, or been in touch as a client, you can ask what we hold and what we do with it."
      />
      <Section className="grid gap-12 md:grid-cols-3">
        <div className="md:col-span-2">
          {ref ? (
            <div className="surface bg-soft-mesh p-6" role="status">
              <ShieldCheck className="size-8 text-primary" aria-hidden="true" />
              <h2 className="mt-4 text-lg font-semibold">We have your request</h2>
              <p className="mt-2 leading-relaxed text-muted-foreground">
                Its reference is{" "}
                <span className="font-mono font-semibold text-foreground">{ref}</span>. We have
                emailed you a copy. Quote that reference if you need to chase it or add anything.
              </p>
              <p className="mt-3 text-sm text-muted-foreground">
                We will reply to the address you gave us to confirm the request came from you.
                We will never ask you to email us a passport or driving licence.
              </p>
            </div>
          ) : (
            <form onSubmit={onSubmit} className="space-y-5" noValidate>
              <div className="grid gap-2">
                <Label htmlFor="kind">What would you like us to do?</Label>
                <Select value={kind} onValueChange={setKind}>
                  <SelectTrigger id="kind"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {KINDS.map((k) => (
                      <SelectItem key={k.value} value={k.value}>{k.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="grid gap-5 sm:grid-cols-2">
                <div className="grid gap-2">
                  <Label htmlFor="name">
                    Your name <span className="text-destructive" aria-hidden="true">*</span>
                  </Label>
                  <Input
                    id="name"
                    name="name"
                    required
                    autoComplete="name"
                    aria-invalid={!!errors.name}
                    aria-describedby={errors.name ? "name-error" : undefined}
                    onChange={() => errors.name && setErrors((p) => ({ ...p, name: undefined }))}
                  />
                  {errors.name && <p id="name-error" className="text-xs text-destructive">{errors.name}</p>}
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="email">
                    Email <span className="text-destructive" aria-hidden="true">*</span>
                  </Label>
                  <Input
                    id="email"
                    name="email"
                    type="email"
                    required
                    autoComplete="email"
                    aria-invalid={!!errors.email}
                    aria-describedby={errors.email ? "email-error" : "email-hint"}
                    onChange={() => errors.email && setErrors((p) => ({ ...p, email: undefined }))}
                  />
                  {errors.email ? (
                    <p id="email-error" className="text-xs text-destructive">{errors.email}</p>
                  ) : (
                    <p id="email-hint" className="text-xs text-muted-foreground">
                      Use the address we already know you by, if you can — it makes confirming
                      the request quicker.
                    </p>
                  )}
                </div>
              </div>

              <div className="grid gap-2">
                <Label htmlFor="relationship">How do you know us? (optional)</Label>
                <Input
                  id="relationship"
                  name="relationship"
                  placeholder="e.g. I worked for you in 2024 · I applied for a carer role · I'm a client contact"
                />
                <p className="text-xs text-muted-foreground">
                  This tells us where to look. It is not a test.
                </p>
              </div>

              <div className="grid gap-2">
                <Label htmlFor="detail">Anything else we should know? (optional)</Label>
                <Textarea
                  id="detail"
                  name="detail"
                  rows={4}
                  placeholder="If your request is about something specific — a particular record, a date range, an email you had from us — say so here."
                />
              </div>

              {/* Said before they fill anything in, not after. */}
              <div className="surface flex items-start gap-3 border-l-2 border-primary p-4 text-sm text-muted-foreground">
                <Info className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                <p>
                  Please do not attach or email identity documents. We confirm who you are
                  using something we already hold, over the address above. We use the details
                  on this form only to handle your request.
                </p>
              </div>

              <Button type="submit" size="lg" disabled={submitting}>
                {submitting ? "Sending…" : "Send request"}
              </Button>
            </form>
          )}
        </div>

        <aside className="space-y-5 text-sm">
          <div className="surface p-5">
            <h2 className="font-semibold">What happens next</h2>
            <ol className="mt-4 space-y-3 text-muted-foreground">
              {STEPS.map((s, i) => (
                <li key={s} className="flex items-start gap-3">
                  <span className="grid size-6 shrink-0 place-items-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">
                    {i + 1}
                  </span>
                  <span className="leading-relaxed">{s}</span>
                </li>
              ))}
            </ol>
          </div>

          <div className="surface p-5">
            <h2 className="font-semibold">If you would rather email</h2>
            <p className="mt-2 text-muted-foreground">
              Write to{" "}
              <a href={`mailto:${COMPANY.email}`} className="font-medium text-primary hover:underline">
                {COMPANY.email}
              </a>{" "}
              and say what you would like us to do. The form simply makes sure it is logged.
            </p>
            <p className="mt-3 flex items-start gap-2 text-muted-foreground">
              <Mail className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
              <span>
                Not happy with how we handle it? You can complain to the Information
                Commissioner's Office at{" "}
                <a
                  href="https://ico.org.uk/make-a-complaint/"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="font-medium text-primary hover:underline"
                >
                  ico.org.uk
                </a>
                .
              </span>
            </p>
          </div>

          <div className="surface p-5">
            <h2 className="font-semibold">Before you ask</h2>
            <p className="mt-2 text-muted-foreground">
              Our{" "}
              <Link to="/privacy" className="font-medium text-primary hover:underline">
                privacy notice
              </Link>{" "}
              sets out what we collect and why, and the{" "}
              <Link to="/cookies" className="font-medium text-primary hover:underline">
                cookie and storage notice
              </Link>{" "}
              covers what this website itself stores.
            </p>
          </div>
        </aside>
      </Section>
    </>
  )
}
