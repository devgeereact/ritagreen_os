import { Building2, HeartPulse, Home, PartyPopper, Briefcase, GraduationCap } from "lucide-react"
import { PageHeader, Section, CtaBand } from "@/components/public/section"

const SECTORS = [
  {
    icon: HeartPulse,
    name: "Care homes & nursing homes",
    body: "Carers and nurses for planned cover, sickness and short-notice gaps, plus cleaning that keeps settings inspection-ready.",
  },
  {
    icon: Building2,
    name: "NHS & healthcare",
    body: "Checked, compliant staff to support healthcare teams, with the paperwork to match.",
  },
  {
    icon: Home,
    name: "Landlords & managing agents",
    body: "Communal cleaning, end-of-tenancy turnarounds and concierge or security cover across residential blocks.",
  },
  {
    icon: PartyPopper,
    name: "Event organisers",
    body: "SIA-licensed stewards and door supervisors, scaled to the event and its risk assessment.",
  },
  {
    icon: Briefcase,
    name: "Offices & commercial",
    body: "Daily and periodic cleaning, plus manned guarding and front-of-house cover.",
  },
  {
    icon: GraduationCap,
    name: "Learners & job seekers",
    body: "Accredited Health & Social Care courses, with the best learners flowing into roles across the group.",
  },
]

export default function Sectors() {
  return (
    <>
      <PageHeader
        eyebrow="Who we serve"
        title="Built around the people who rely on us"
        intro="We work across care, housing, events, commercial property and training. Whatever the setting, the standard is the same."
      />
      <Section>
        <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
          {SECTORS.map((s) => (
            <div key={s.name} className="rounded-xl border border-border bg-card p-6">
              <span className="grid size-11 place-items-center rounded-lg bg-accent text-primary">
                <s.icon className="size-5" />
              </span>
              <h3 className="mt-4 font-semibold">{s.name}</h3>
              <p className="mt-2 text-sm text-muted-foreground">{s.body}</p>
            </div>
          ))}
        </div>
      </Section>
      <CtaBand />
    </>
  )
}
