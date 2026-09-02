import { useState } from "react"
import { Link } from "react-router-dom"
import {
  GraduationCap,
  BadgeCheck,
  HeartHandshake,
  CalendarCheck,
  Check,
  ArrowRight,
} from "lucide-react"
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
import { PageHeader, Section, SectionAlt, CtaBand } from "@/components/public/section"
import { CounterBand } from "@/components/public/marketing"
import { Faqs } from "@/components/public/Faqs"

const ACADEMY_STATS = [
  { n: "16", l: "Care Certificate standards" },
  { n: "12wk", l: "Recommended induction window" },
  { n: "100%", l: "DBS-checked before first shift" },
  { n: "1", l: "Guaranteed interview route" },
] as const
import { api, ApiError } from "@/lib/api"
import { COMPANY } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"

const STEPS = [
  {
    icon: GraduationCap,
    step: "01",
    title: "Train with us",
    body: "Complete the Care Certificate (16 standards) plus mandatory training — moving & handling, first aid, safeguarding, medication awareness — with assessor support.",
  },
  {
    icon: BadgeCheck,
    step: "02",
    title: "Get checked",
    body: "We complete your enhanced DBS, references and right-to-work, so you're ready to work the day your training signs off.",
  },
  {
    icon: HeartHandshake,
    step: "03",
    title: "Get hired",
    body: "Strong learners get a guaranteed interview with RitaGreen Care. Real training, a real job at the end — no experience needed, just the right attitude.",
  },
]

const LEARN = [
  "All 16 Care Certificate standards",
  "Moving & handling",
  "Basic life support and first aid",
  "Safeguarding adults",
  "Medication awareness",
  "Infection prevention and control",
]

const PERKS = [
  "Guaranteed interview with RitaGreen Care for strong learners",
  "No experience needed — we teach you from the ground up",
  "Classroom and blended delivery to fit around your life",
  "A verifiable certificate employers can check online",
  "Ongoing shifts and a named contact once you're placed",
  "A real route into a career that matters",
]

const FAQS_CAREERS = [
  {
    q: "Do I need experience to start?",
    a: "No. You don't need experience, just the right attitude. We teach the 16 Care Certificate standards, moving & handling, first aid and safeguarding, then help you into your first role.",
  },
  {
    q: "Is there really a job at the end?",
    a: "Strong learners get a guaranteed interview with RitaGreen Care. We train the carers we place, so completing the course with us puts you first in line for our own shifts.",
  },
  {
    q: "How long does the training take?",
    a: "The Care Certificate is designed to be completed inside the recommended 12-week induction window. We'll confirm the exact intake dates when we get in touch.",
  },
  {
    q: "What does it cost?",
    a: "Costs depend on the intake and any funding available. Tell us a bit about yourself and we'll explain your options on the next intake.",
  },
]

// `division` is the ATS column an application is filed under, so the choice made
// here decides which recruiter sees it. "Become a carer" is the academy route --
// they train first and are placed after -- which is why it is not `care`.
const ROLES = [
  { value: "carer", label: "Become a carer (Care Certificate)", division: "academy" },
  { value: "cleaning", label: "Cleaning operative", division: "cleaning" },
  { value: "security", label: "Security officer (SIA)", division: "security" },
  { value: "other", label: "Something else", division: "care" },
] as const

export default function Careers() {
  useSeo({
    title: "Careers — train as a carer and get hired",
    description:
      "Train with RitaGreen Academy and start a career in care. Complete the Care Certificate, get DBS-checked, and strong learners get a guaranteed interview with RitaGreen Care. No experience needed.",
    path: "/careers",
  })

  const [submitting, setSubmitting] = useState(false)
  const [role, setRole] = useState("carer")
  // Explicit consent, not an inferred one. ats.php writes a consent_log row for
  // every application; before this the form only showed a privacy notice, so
  // that row recorded a consent nobody had actually given.
  const [consent, setConsent] = useState(false)
  const [errors, setErrors] = useState<{ name?: string; email?: string; about?: string; consent?: string }>({})

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = e.currentTarget
    const data = new FormData(form)
    const name = String(data.get("name") || "").trim()
    const email = String(data.get("email") || "").trim()
    const phone = String(data.get("phone") || "").trim()
    const about = String(data.get("about") || "").trim()
    const chosen = ROLES.find((r) => r.value === role)
    const roleLabel = chosen?.label ?? role

    // Inline per-field validation so the offending field is marked, not just a toast.
    const next: typeof errors = {}
    if (!name) next.name = "Please tell us your name."
    if (!email) next.email = "Please add an email so we can reply."
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) next.email = "That email doesn't look right."
    if (!about) next.about = "Tell us a few words about you."
    if (!consent) next.consent = "Please agree to be contacted about your application."
    setErrors(next)
    if (Object.keys(next).length > 0) {
      form.querySelector<HTMLElement>("[aria-invalid='true']")?.focus()
      return
    }

    setSubmitting(true)
    try {
      // The applicant tracker, not the enquiry inbox.
      //
      // This posted to contact.php with the role flattened into a message body
      // prefixed "CAREERS APPLICATION". Every application from the website
      // therefore landed in contact_enquiries and NOTHING reached /app/ats — the
      // recruitment pipeline the CMS is built around, with stages, onboarding
      // checklists and the hand-off into a worker record, was fed by nothing.
      // ats.php also resolves the applicant to the person spine, so somebody who
      // applies twice lands on the record they already have.
      await api("ats.php", {
        method: "POST",
        body: {
          name,
          email,
          phone,
          division: chosen?.division ?? "care",
          role_applied: roleLabel,
          notes: about,
          consent,
        },
        auth: false,
      })
      toast.success("Thanks. We've got your application and will be in touch about the next intake.")
      form.reset()
      setRole("carer")
      setConsent(false)
      setErrors({})
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
        eyebrow="Careers · RitaGreen Academy"
        title="Train as a carer — and get hired"
        intro="We train the next wave of carers, then help them into work. Complete the Care Certificate with us and, if you're strong, get a guaranteed interview with RitaGreen Care. No experience needed — just the right attitude."
      />

      {/* How it works */}
      <Section>
        <div className="max-w-2xl">
          <p className="eyebrow">The route in</p>
          <h2 className="mt-3 text-2xl font-bold md:text-3xl">
            Train, get checked, get hired
          </h2>
          <p className="mt-4 leading-relaxed text-muted-foreground">
            Most training leaves you with a certificate and another job hunt. Ours leads somewhere
            real — because we hire the people we train.
          </p>
        </div>
        <div className="mt-10 grid gap-5 md:grid-cols-3">
          {STEPS.map((s) => (
            <div key={s.step} className="surface-interactive p-7">
              <span className="grid size-12 place-items-center rounded-xl bg-primary/10 text-primary ring-1 ring-inset ring-primary/10">
                <s.icon className="size-6" strokeWidth={1.75} />
              </span>
              <div className="mt-5 flex items-center gap-2">
                <span className="font-mono text-sm font-bold text-primary">{s.step}</span>
                <h3 className="font-semibold">{s.title}</h3>
              </div>
              <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{s.body}</p>
            </div>
          ))}
        </div>
      </Section>

      {/* What you learn + why */}
      <SectionAlt className="grid gap-10 md:grid-cols-2">
          <div>
            <h2 className="text-2xl font-bold">What you'll learn</h2>
            <p className="mt-3 text-muted-foreground">
              Content mapped to Skills for Care guidance and the Care Certificate framework, with a
              verifiable certificate on completion.
            </p>
            <ul className="mt-5 grid gap-3 sm:grid-cols-2">
              {LEARN.map((l) => (
                <li key={l} className="flex items-start gap-2 text-sm text-muted-foreground">
                  <Check className="mt-0.5 size-4 shrink-0 text-primary" /> {l}
                </li>
              ))}
            </ul>
          </div>
          <div>
            <h2 className="text-2xl font-bold">Why train with RitaGreen</h2>
            <ul className="mt-5 space-y-3">
              {PERKS.map((p) => (
                <li key={p} className="flex items-start gap-2 text-sm text-muted-foreground">
                  <Check className="mt-0.5 size-4 shrink-0 text-primary" /> {p}
                </li>
              ))}
            </ul>
          </div>
      </SectionAlt>

      {/* Academy counter band */}
      <CounterBand stats={ACADEMY_STATS} />

      {/* Apply form */}
      <Section id="apply" className="scroll-mt-24 grid gap-12 md:grid-cols-3">
        <div className="md:col-span-2">
          <h2 className="text-2xl font-bold tracking-tight">Apply for the next intake</h2>
          <p className="mt-3 text-muted-foreground">
            Tell us a little about you. We'll be in touch with intake dates, costs and what happens
            next — no commitment.
          </p>
          <form onSubmit={onSubmit} className="mt-6 space-y-5" noValidate>
            <div className="grid gap-5 sm:grid-cols-2">
              <div className="grid gap-2">
                <Label htmlFor="name">
                  Full name <span className="text-destructive" aria-hidden="true">*</span>
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
                  aria-describedby={errors.email ? "email-error" : undefined}
                  onChange={() => errors.email && setErrors((p) => ({ ...p, email: undefined }))}
                />
                {errors.email && <p id="email-error" className="text-xs text-destructive">{errors.email}</p>}
              </div>
              <div className="grid gap-2">
                <Label htmlFor="phone">Phone (optional)</Label>
                <Input id="phone" name="phone" type="tel" autoComplete="tel" />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="role">I'm interested in</Label>
                <Select value={role} onValueChange={setRole}>
                  <SelectTrigger id="role">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {ROLES.map((r) => (
                      <SelectItem key={r.value} value={r.value}>
                        {r.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="about">
                A bit about you <span className="text-destructive" aria-hidden="true">*</span>
              </Label>
              <Textarea
                id="about"
                name="about"
                rows={5}
                required
                placeholder="Why care work? Any availability, location or experience worth mentioning?"
                aria-invalid={!!errors.about}
                aria-describedby={errors.about ? "about-error" : undefined}
                onChange={() => errors.about && setErrors((p) => ({ ...p, about: undefined }))}
              />
              {errors.about && <p id="about-error" className="text-xs text-destructive">{errors.about}</p>}
            </div>
            <div className="grid gap-2">
              <label className="flex items-start gap-2 text-sm text-muted-foreground">
                <Checkbox
                  checked={consent}
                  onCheckedChange={(v) => {
                    setConsent(!!v)
                    if (v) setErrors((p) => ({ ...p, consent: undefined }))
                  }}
                  aria-invalid={!!errors.consent}
                  aria-describedby={errors.consent ? "consent-error" : undefined}
                  className="mt-0.5"
                />
                <span>
                  I agree that RitaGreen may store these details and contact me about my
                  application, as described in the{" "}
                  {/* Link, not <a href>. A full page load here threw away everything
                      typed into the form -- so reading the privacy policy before
                      agreeing to it cost the applicant their application. */}
                  <Link to="/privacy" className="font-medium text-primary hover:underline">
                    privacy policy
                  </Link>
                  .
                </span>
              </label>
              {errors.consent && (
                <p id="consent-error" role="alert" className="text-xs text-destructive">
                  {errors.consent}
                </p>
              )}
            </div>
            <Button type="submit" size="lg" disabled={submitting}>
              {submitting ? "Sending…" : "Send application"}
            </Button>
          </form>
        </div>

        <aside className="space-y-5 text-sm">
          <div className="surface p-6">
            <span className="grid size-11 place-items-center rounded-xl bg-primary/10 text-primary">
              <CalendarCheck className="size-6" strokeWidth={1.75} />
            </span>
            <h3 className="mt-4 font-semibold">Rolling intakes</h3>
            <p className="mt-2 leading-relaxed text-muted-foreground">
              We run regular intakes through the year. Apply any time and we'll place you on the next
              available cohort.
            </p>
          </div>
          <div className="surface bg-soft-mesh p-6 text-muted-foreground">
            <p>Employer upskilling a team instead?</p>
            <Link to="/services#academy" className="mt-2 inline-flex items-center gap-1 font-medium text-primary hover:underline">
              See academy training <ArrowRight className="size-3.5" />
            </Link>
          </div>
        </aside>
      </Section>

      {/* FAQ */}
      <SectionAlt className="max-w-3xl">
        <p className="eyebrow">FAQ</p>
        <h2 className="mt-3 text-2xl font-bold md:text-3xl">Questions about training</h2>
        <Faqs items={FAQS_CAREERS} className="mt-8" />
      </SectionAlt>

      <CtaBand
        title="Ready to start?"
        body={`Apply for the next intake, or call us on ${COMPANY.phone} to talk it through.`}
      />
    </>
  )
}
