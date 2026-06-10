import { ShieldCheck, Award, FileCheck, Lock } from "lucide-react"
import { PageHeader, Section, CtaBand } from "@/components/public/section"

const ITEMS = [
  {
    icon: ShieldCheck,
    name: "CQC-aligned care",
    body: "Our care division works to Care Quality Commission standards, with evidence held and ready for inspection.",
  },
  {
    icon: Award,
    name: "SIA-licensed security",
    body: "Every security officer holds a valid Security Industry Authority licence, checked before they are scheduled.",
  },
  {
    icon: FileCheck,
    name: "DBS-checked workforce",
    body: "Staff are DBS-checked and right-to-work verified before their first shift, with renewals tracked.",
  },
  {
    icon: Lock,
    name: "GDPR & data protection",
    body: "Client and worker records are handled under UK GDPR, with clear retention and access controls.",
  },
]

export default function Accreditations() {
  return (
    <>
      <PageHeader
        eyebrow="Accreditations & compliance"
        title="The standards behind every shift"
        intro="Compliance is not a badge we point to once a year. It is checked before staff are dispatched and tracked to renewal."
      />
      <Section>
        <div className="grid gap-5 md:grid-cols-2">
          {ITEMS.map((i) => (
            <div key={i.name} className="flex gap-4 rounded-xl border border-border bg-card p-6">
              <span className="grid size-11 shrink-0 place-items-center rounded-lg bg-accent text-primary">
                <i.icon className="size-5" />
              </span>
              <div>
                <h3 className="font-semibold">{i.name}</h3>
                <p className="mt-2 text-sm text-muted-foreground">{i.body}</p>
              </div>
            </div>
          ))}
        </div>
        <p className="mt-8 text-sm text-muted-foreground">
          Holding an academy certificate from us? Anyone can{" "}
          <a href="/verify" className="font-medium text-primary hover:underline">verify it here</a>.
        </p>
      </Section>
      <CtaBand />
    </>
  )
}
