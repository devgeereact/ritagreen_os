import { Link } from "react-router-dom"
import { ArrowRight, Check, Users, ShieldCheck } from "lucide-react"
import { Button } from "@/components/ui/button"
import { PageHeader, Section, CtaBand } from "@/components/public/section"
import { DIVISIONS } from "@/lib/divisions"
import { ENGAGEMENTS } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"

export default function Services() {
  useSeo({
    title: "Services — care, training, cleaning & security",
    description:
      "Four divisions, one standard. Care staffing, Care Certificate training, commercial and care cleaning, and SIA-licensed security — every service delivered by checked, qualified people under one account.",
    path: "/services",
  })

  return (
    <>
      <PageHeader
        eyebrow="What we offer"
        title="Services across care, training, cleaning and security"
        intro="Four divisions, one standard. Every service is delivered by checked, qualified people under one account. Browse what each division does, or send a single enquiry and we'll point you to the right team."
      />

      {/* Quick nav */}
      <div className="border-b border-border bg-secondary/30">
        <div className="mx-auto flex max-w-6xl flex-wrap gap-2 px-4 py-4">
          {DIVISIONS.map((d) => (
            <a
              key={d.id}
              href={`#${d.id}`}
              className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-4 py-1.5 text-sm font-medium text-foreground transition-colors hover:bg-accent"
            >
              <span className="size-2.5 rounded-full" style={{ backgroundColor: d.accent }} />
              {d.name}
            </a>
          ))}
        </div>
      </div>

      {DIVISIONS.map((d) => (
        <Section key={d.id} id={d.id} className="scroll-mt-24 border-b border-border last:border-0">
          <div className="flex items-center gap-3">
            <span className="size-3.5 rounded-full" style={{ backgroundColor: d.accent }} />
            <h2 className="text-2xl font-bold tracking-tight md:text-3xl">{d.name}</h2>
          </div>
          <p className="mt-2 font-serif text-lg italic text-foreground">{d.headline}</p>
          <p className="mt-3 max-w-3xl text-muted-foreground">{d.intro}</p>

          {/* Service cards */}
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

          {/* Audience + assurance */}
          <div className="mt-8 grid gap-5 md:grid-cols-2">
            <div className="rounded-xl border border-border bg-card p-6">
              <h3 className="flex items-center gap-2 font-semibold">
                <Users className="size-4 text-primary" /> Who it's for
              </h3>
              <ul className="mt-3 space-y-2">
                {d.audience.map((a) => (
                  <li key={a} className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Check className="mt-0.5 size-4 shrink-0 text-primary" /> {a}
                  </li>
                ))}
              </ul>
            </div>
            <div className="rounded-xl border border-border bg-secondary/40 p-6">
              <h3 className="flex items-center gap-2 font-semibold">
                <ShieldCheck className="size-4 text-primary" /> Our assurance
              </h3>
              <ul className="mt-3 space-y-2">
                {d.assurance.map((a) => (
                  <li key={a} className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Check className="mt-0.5 size-4 shrink-0 text-primary" /> {a}
                  </li>
                ))}
              </ul>
            </div>
          </div>

          <div className="mt-6 flex flex-wrap gap-3">
            <Button asChild>
              <Link to={`/quote?service=${d.id}`}>Get a {d.descriptor.toLowerCase()} quote</Link>
            </Button>
            <Button asChild variant="outline">
              <Link to="/contact">Ask a question</Link>
            </Button>
          </div>
        </Section>
      ))}

      {/* Engagement models */}
      <Section>
        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-primary">
          Buying from RitaGreen
        </p>
        <h2 className="mt-3 text-2xl font-bold tracking-tight md:text-3xl">
          Ways to work with us
        </h2>
        <p className="mt-3 max-w-2xl text-muted-foreground">
          From one-off emergency cover to a managed contract across a portfolio — pick the engagement
          that fits, and we'll confirm scope and rates in writing.
        </p>
        <div className="mt-8 overflow-hidden rounded-xl border border-border">
          <table className="w-full text-left text-sm">
            <thead className="bg-secondary/60 text-foreground">
              <tr>
                <th className="px-5 py-3 font-semibold">Engagement</th>
                <th className="px-5 py-3 font-semibold">Best for</th>
                <th className="px-5 py-3 font-semibold">Typical terms</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border bg-card">
              {ENGAGEMENTS.map((e) => (
                <tr key={e.name}>
                  <td className="px-5 py-4 font-medium text-foreground">{e.name}</td>
                  <td className="px-5 py-4 text-muted-foreground">{e.bestFor}</td>
                  <td className="px-5 py-4 text-muted-foreground">{e.terms}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-4 text-sm text-muted-foreground">
          Rates are confirmed per engagement. Bank holidays and unsociable hours are charged at an
          agreed uplift, set out clearly in your quote.
        </p>
      </Section>

      <CtaBand />
    </>
  )
}
