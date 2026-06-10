import { Link } from "react-router-dom"
import { ArrowRight } from "lucide-react"
import { PageHeader, Section, CtaBand } from "@/components/public/section"
import { DIVISIONS } from "@/lib/divisions"

export default function Services() {
  return (
    <>
      <PageHeader
        eyebrow="What we offer"
        title="Services across care, training, cleaning and security"
        intro="Four divisions, one standard. Browse what each one does, or send a single enquiry and we will point you to the right team."
      />

      {DIVISIONS.map((d) => (
        <Section key={d.id} id={d.id} className="scroll-mt-20 border-b border-border last:border-0">
          <div className="flex items-center gap-3">
            <span className="size-3.5 rounded-full" style={{ backgroundColor: d.accent }} />
            <h2 className="text-2xl font-bold tracking-tight">{d.name}</h2>
          </div>
          <p className="mt-3 max-w-2xl text-muted-foreground">{d.blurb}</p>

          <div className="mt-8 grid gap-5 md:grid-cols-3">
            {d.services.map((s) => (
              <Link
                key={s.slug}
                to={`/services/${s.slug}`}
                className="group flex flex-col rounded-xl border border-border bg-card p-5 transition-shadow hover:shadow-md"
              >
                <h3 className="font-semibold">{s.name}</h3>
                <p className="mt-2 flex-1 text-sm text-muted-foreground">{s.summary}</p>
                <span className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary">
                  Details <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                </span>
              </Link>
            ))}
          </div>
        </Section>
      ))}

      <CtaBand />
    </>
  )
}
