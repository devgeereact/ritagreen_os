import { Link } from "react-router-dom"
import {
  ShieldCheck,
  Award,
  FileCheck,
  Lock,
  HeartPulse,
  GraduationCap,
  Sparkles,
  Umbrella,
  BadgeCheck,
  ArrowRight,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { PageHeader, Section, CtaBand } from "@/components/public/section"
import { useSeo } from "@/hooks/use-seo"

const ITEMS = [
  {
    icon: HeartPulse,
    name: "CQC-aligned care",
    body: "Our care division works to Care Quality Commission expectations. Where we run managed care we operate against a Statement of Purpose, with evidence held and ready for inspection.",
  },
  {
    icon: GraduationCap,
    name: "Care Certificate & Skills for Care",
    body: "Carers are trained against the Care Certificate's 16 standards, with academy content mapped to Skills for Care guidance and completion inside the recommended 12-week window.",
  },
  {
    icon: Award,
    name: "SIA-licensed security",
    body: "Every security officer holds a valid Security Industry Authority licence for their role. We verify licence validity before an officer is ever scheduled, and work towards Approved Contractor Scheme standards.",
  },
  {
    icon: FileCheck,
    name: "DBS & BS 7858 vetting",
    body: "Staff are enhanced-DBS checked, referenced and right-to-work verified before their first shift. Security officers are vetted to BS 7858 principles, with renewals tracked to expiry.",
  },
  {
    icon: Sparkles,
    name: "COSHH-controlled cleaning",
    body: "Cleaning follows recognised industry standards with documented COSHH assessments for every chemical used, plus method statements and risk assessments supplied per site.",
  },
  {
    icon: Umbrella,
    name: "Insurance",
    body: "We hold public liability and employers' liability insurance (minimum £5m). Certificates and cover limits are available on request.",
  },
  {
    icon: ShieldCheck,
    name: "Health & safety",
    body: "We meet our duties under the Health and Safety at Work etc. Act 1974, with risk assessments per task and site, lone-working procedures, and RIDDOR reporting where required.",
  },
  {
    icon: Lock,
    name: "GDPR & data protection",
    body: "Client and worker records are handled under UK GDPR and the Data Protection Act 2018, with clear retention, access controls and ICO registration.",
  },
]

const VETTING = [
  {
    step: "01",
    title: "Identity & right to work",
    body: "We confirm identity and the legal right to work in the UK before anyone is offered an assignment.",
  },
  {
    step: "02",
    title: "Enhanced DBS & references",
    body: "An enhanced DBS check and reference checks are completed and recorded before the first shift.",
  },
  {
    step: "03",
    title: "Role qualifications & licences",
    body: "We evidence the right training — Care Certificate for carers, a valid SIA licence for officers — for the specific role.",
  },
  {
    step: "04",
    title: "Brief, deploy & renew",
    body: "Workers are briefed per assignment, then we track licence and training expiry and refresh on schedule.",
  },
]

export default function Accreditations() {
  useSeo({
    title: "Accreditations & compliance — the standards behind every shift",
    description:
      "CQC-aligned care, SIA-licensed security, enhanced DBS and BS 7858 vetting, COSHH-controlled cleaning, insurance and GDPR. Compliance checked before staff are dispatched and tracked to renewal.",
    path: "/accreditations",
  })

  return (
    <>
      <PageHeader
        eyebrow="Accreditations & compliance"
        title="The standards behind every shift"
        intro="Compliance is not a badge we point to once a year. It is checked before staff are dispatched and tracked to renewal — the whole model rests on 'qualified, checked and ready'."
      />

      {/* Standards grid */}
      <Section>
        <div className="grid gap-5 md:grid-cols-2">
          {ITEMS.map((i) => (
            <div key={i.name} className="surface-interactive flex gap-4 p-7">
              <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary ring-1 ring-inset ring-primary/10">
                <i.icon className="size-6" strokeWidth={1.75} />
              </span>
              <div>
                <h3 className="font-semibold">{i.name}</h3>
                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{i.body}</p>
              </div>
            </div>
          ))}
        </div>
      </Section>

      {/* Vetting process */}
      <section className="border-y border-border bg-soft-mesh">
        <div className="mx-auto max-w-6xl px-4 py-16 md:py-24">
          <p className="eyebrow">How we vet</p>
          <h2 className="mt-3 text-2xl font-bold md:text-3xl">
            Every worker, checked before they start
          </h2>
          <p className="mt-4 max-w-2xl leading-relaxed text-muted-foreground">
            We never let vetting or licensing slip to win a job. Here's the order we do it in.
          </p>
          <div className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {VETTING.map((v) => (
              <div key={v.step} className="surface relative p-7">
                <span className="grid size-10 place-items-center rounded-full bg-primary font-mono text-sm font-bold text-primary-foreground shadow-soft">
                  {v.step}
                </span>
                <h3 className="mt-4 font-semibold">{v.title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{v.body}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Verify certificate */}
      <Section>
        <div className="surface bg-soft-mesh p-8 shadow-elevate md:p-12">
          <div className="flex flex-col items-start justify-between gap-6 md:flex-row md:items-center">
            <div className="max-w-xl">
              <span className="grid size-12 place-items-center rounded-2xl bg-primary/10 text-primary">
                <BadgeCheck className="size-6" />
              </span>
              <h2 className="mt-4 text-2xl font-bold">
                Verify a RitaGreen Academy certificate
              </h2>
              <p className="mt-3 leading-relaxed text-muted-foreground">
                Employers can confirm any certificate we issue is genuine in seconds — enter the
                certificate ID and we'll show you the learner, the course and the issue date.
              </p>
            </div>
            <Button asChild size="lg" className="shrink-0 shadow-lift">
              <Link to="/verify">
                Verify a certificate <ArrowRight className="ml-1 size-4" />
              </Link>
            </Button>
          </div>
        </div>
      </Section>

      <CtaBand
        title="Want our compliance pack?"
        body="We can share DBS, SIA, insurance and policy evidence on request. Tell us what you need to see."
      />
    </>
  )
}
