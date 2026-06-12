import { Link } from "react-router-dom"
import {
  Building2,
  HeartPulse,
  Home,
  PartyPopper,
  Briefcase,
  GraduationCap,
  Stethoscope,
  HardHat,
} from "lucide-react"
import { PageHeader, Section, CtaBand } from "@/components/public/section"
import { useSeo } from "@/hooks/use-seo"

const SECTORS = [
  {
    icon: HeartPulse,
    name: "Care & nursing homes",
    body: "Carers and nurses for planned cover, sickness and short-notice gaps, plus infection-control cleaning that keeps settings inspection-ready.",
    serves: ["care", "cleaning"],
  },
  {
    icon: Stethoscope,
    name: "NHS & healthcare",
    body: "Checked, compliant staff to support healthcare teams, with the paperwork to match and training mapped to recognised frameworks.",
    serves: ["care", "academy"],
  },
  {
    icon: Home,
    name: "Landlords & managing agents",
    body: "Communal cleaning, end-of-tenancy turnarounds and concierge or security cover across residential blocks — one supplier, one invoice.",
    serves: ["cleaning", "security"],
  },
  {
    icon: PartyPopper,
    name: "Event organisers",
    body: "SIA-licensed stewards and door supervisors, scaled to the event and its risk assessment, with a briefed team and a named lead.",
    serves: ["security"],
  },
  {
    icon: Briefcase,
    name: "Offices & commercial",
    body: "Daily and periodic cleaning, plus manned guarding and calm front-of-house cover for commercial premises.",
    serves: ["cleaning", "security"],
  },
  {
    icon: HardHat,
    name: "Construction & void property",
    body: "Static guarding, mobile patrols and key-holding for sites and empty buildings, plus void-property cleans on turnaround.",
    serves: ["security", "cleaning"],
  },
  {
    icon: GraduationCap,
    name: "Learners & job seekers",
    body: "The Care Certificate and mandatory training, with strong learners flowing straight into roles across RitaGreen Care.",
    serves: ["academy", "care"],
  },
  {
    icon: Building2,
    name: "Supported living providers",
    body: "Consistent support-worker teams matched to each person's plan, with outcomes tracked and shared with funders.",
    serves: ["care"],
  },
]

const DIVISION_LABEL: Record<string, { label: string; accent: string }> = {
  care: { label: "Care", accent: "#2E7D32" },
  academy: { label: "Academy", accent: "#B7791C" },
  cleaning: { label: "Cleaning", accent: "#4F8A6B" },
  security: { label: "Security", accent: "#2C3A33" },
}

export default function Sectors() {
  useSeo({
    title: "Sectors we serve — care, housing, events & more",
    description:
      "RitaGreen works across care and nursing homes, the NHS, landlords and managing agents, events, offices, construction and training. Whatever the setting, the standard is the same.",
    path: "/sectors",
  })

  return (
    <>
      <PageHeader
        eyebrow="Who we serve"
        title="Built around the people who rely on us"
        intro="We work across care, healthcare, housing, events, commercial property, construction and training. Whatever the setting, the standard is the same — qualified, checked and ready."
      />
      <Section>
        <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
          {SECTORS.map((s) => (
            <div key={s.name} className="flex flex-col surface p-6">
              <span className="grid size-11 place-items-center rounded-lg bg-accent text-primary">
                <s.icon className="size-5" />
              </span>
              <h3 className="mt-4 font-semibold">{s.name}</h3>
              <p className="mt-2 flex-1 text-sm text-muted-foreground">{s.body}</p>
              <div className="mt-4 flex flex-wrap gap-2">
                {s.serves.map((id) => (
                  <Link
                    key={id}
                    to={`/services#${id}`}
                    className="inline-flex items-center gap-1.5 rounded-full bg-secondary/60 px-2.5 py-1 text-xs font-medium text-foreground hover:bg-accent"
                  >
                    <span
                      className="size-2 rounded-full"
                      style={{ backgroundColor: DIVISION_LABEL[id].accent }}
                    />
                    {DIVISION_LABEL[id].label}
                  </Link>
                ))}
              </div>
            </div>
          ))}
        </div>
      </Section>
      <CtaBand
        title="Don't see your sector?"
        body="If you look after people or premises, we can probably help. Tell us the setting and we'll point you to the right team."
      />
    </>
  )
}
