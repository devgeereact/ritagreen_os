import { Link } from "react-router-dom"
import { ArrowRight, Check, Users, ShieldCheck } from "lucide-react"
import { Button } from "@/components/ui/button"
import { PageHeader, Section, CtaBand } from "@/components/public/section"
import { SectionHeading, TrustStrip } from "@/components/public/marketing"
import { DIVISIONS } from "@/lib/divisions"
import { divisionIcon, accentTint } from "@/lib/division-icons"
import { ENGAGEMENTS } from "@/lib/company"

const TRUST = [
  "CQC-aligned care",
  "SIA-licensed officers",
  "Enhanced DBS checked",
  "COSHH-controlled cleaning",
  "£5m insurance",
]

// Division → on-brand photo (Unsplash, licensed for commercial use).
const DIVISION_IMG: Record<string, { src: string; alt: string }> = {
  care: { src: "/images/divisions/care.jpg", alt: "A carer supporting older people with an activity at home" },
  academy: { src: "/images/divisions/training.jpg", alt: "Adult learners in a classroom during academy training" },
  cleaning: { src: "/images/divisions/cleaning.jpg", alt: "A cleaning operative mopping a clean commercial interior" },
  security: { src: "/images/divisions/security.jpg", alt: "A uniformed security officer in a building lobby" },
}
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

      {/* Trust strip */}
      <section className="border-b border-border bg-soft-mesh">
        <div className="mx-auto max-w-6xl px-4 py-10">
          <TrustStrip items={TRUST} />
        </div>
      </section>

      {/* Quick nav */}
      <div className="sticky top-16 z-30 border-b border-border bg-background/85 backdrop-blur supports-[backdrop-filter]:bg-background/70">
        <div className="mx-auto flex max-w-6xl flex-wrap gap-2 px-4 py-3">
          {DIVISIONS.map((d) => {
            const Icon = divisionIcon(d.id)
            return (
              <a
                key={d.id}
                href={`#${d.id}`}
                className="inline-flex items-center gap-2 rounded-full border border-border/70 bg-card px-4 py-1.5 text-sm font-medium text-foreground shadow-soft transition-all hover:-translate-y-0.5 hover:border-primary/30"
              >
                <Icon className="size-4" style={{ color: d.accent }} strokeWidth={2} />
                {d.name}
              </a>
            )
          })}
        </div>
      </div>

      {DIVISIONS.map((d, idx) => {
        const Icon = divisionIcon(d.id)
        const img = DIVISION_IMG[d.id]
        return (
        <Section key={d.id} id={d.id} className="scroll-mt-28 border-b border-border last:border-0">
          {/* Division header — content + photo, alternating sides */}
          <div className="grid gap-8 md:grid-cols-2 md:items-center md:gap-12">
            <div className={idx % 2 === 1 ? "md:order-2" : undefined}>
              <div className="flex items-center gap-4">
                <span
                  className="grid size-14 place-items-center rounded-2xl ring-1 ring-inset ring-black/5"
                  style={{ backgroundColor: accentTint(d.accent, 0.14), color: d.accent }}
                >
                  <Icon className="size-7" strokeWidth={1.75} />
                </span>
                <div>
                  <h2 className="text-2xl font-bold md:text-3xl">{d.name}</h2>
                  <p className="mt-1 font-serif text-lg italic text-foreground">{d.headline}</p>
                </div>
              </div>
              <p className="mt-5 leading-relaxed text-muted-foreground">{d.intro}</p>
            </div>
            {img && (
              <div
                className={
                  "relative aspect-[4/3] overflow-hidden rounded-3xl border border-border shadow-elevate " +
                  (idx % 2 === 1 ? "md:order-1" : "")
                }
              >
                <img
                  src={img.src}
                  alt={img.alt}
                  loading="lazy"
                  className="absolute inset-0 size-full object-cover"
                />
                <span
                  className="absolute bottom-4 left-4 inline-flex items-center gap-2 rounded-full bg-background/90 px-3 py-1 text-xs font-semibold text-foreground shadow-soft backdrop-blur"
                >
                  <span className="size-2 rounded-full" style={{ backgroundColor: d.accent }} />
                  {d.descriptor}
                </span>
              </div>
            )}
          </div>

          {/* Service cards */}
          <div className="mt-8 grid gap-5 md:grid-cols-3">
            {d.services.map((s) => (
              <Link
                key={s.slug}
                to={`/services/${s.slug}`}
                className="group surface-interactive flex flex-col p-6"
              >
                <h3 className="font-semibold">{s.name}</h3>
                <p className="mt-2 flex-1 text-sm leading-relaxed text-muted-foreground">{s.summary}</p>
                <span className="mt-5 inline-flex items-center gap-1 text-sm font-medium text-primary">
                  Details <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-1" />
                </span>
              </Link>
            ))}
          </div>

          {/* Audience + assurance */}
          <div className="mt-6 grid gap-5 md:grid-cols-2">
            <div className="surface p-7">
              <h3 className="flex items-center gap-2 font-semibold">
                <span className="grid size-8 place-items-center rounded-lg bg-primary/10 text-primary">
                  <Users className="size-4" />
                </span>
                Who it's for
              </h3>
              <ul className="mt-4 space-y-2.5">
                {d.audience.map((a) => (
                  <li key={a} className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Check className="mt-0.5 size-4 shrink-0 text-primary" /> {a}
                  </li>
                ))}
              </ul>
            </div>
            <div className="surface bg-soft-mesh p-7">
              <h3 className="flex items-center gap-2 font-semibold">
                <span className="grid size-8 place-items-center rounded-lg bg-primary/10 text-primary">
                  <ShieldCheck className="size-4" />
                </span>
                Our assurance
              </h3>
              <ul className="mt-4 space-y-2.5">
                {d.assurance.map((a) => (
                  <li key={a} className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Check className="mt-0.5 size-4 shrink-0 text-primary" /> {a}
                  </li>
                ))}
              </ul>
            </div>
          </div>

          <div className="mt-7 flex flex-wrap gap-3">
            <Button asChild>
              <Link to={`/quote?service=${d.id}`}>Get a {d.descriptor.toLowerCase()} quote</Link>
            </Button>
            <Button asChild variant="outline">
              <Link to="/contact">Ask a question</Link>
            </Button>
          </div>
        </Section>
        )
      })}

      {/* Engagement models */}
      <Section>
        <SectionHeading
          eyebrow="Buying from RitaGreen"
          title="Ways to work with us"
          intro="From one-off emergency cover to a managed contract across a portfolio — pick the engagement that fits, and we'll confirm scope and rates in writing."
        />
        {/* overflow-x-auto, not overflow-hidden.
            Three columns of prose need roughly 36rem before the text starts
            wrapping to one word a line; the container was `overflow-hidden`, so
            on a 360px phone the third column ("Typical terms" -- the rates and
            rebate wording) was simply cut off with no way to scroll to it.
            min-w keeps the columns readable and lets the wrapper scroll. */}
        <div className="surface mt-8 overflow-x-auto p-0">
          <table className="w-full min-w-[36rem] text-left text-sm">
            <caption className="sr-only">
              Ways to work with RitaGreen: engagement type, what it suits, and typical terms.
            </caption>
            <thead className="bg-soft-mesh text-foreground">
              <tr>
                <th scope="col" className="px-6 py-4 font-semibold">Engagement</th>
                <th scope="col" className="px-6 py-4 font-semibold">Best for</th>
                <th scope="col" className="px-6 py-4 font-semibold">Typical terms</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {ENGAGEMENTS.map((e) => (
                <tr key={e.name} className="transition-colors hover:bg-muted/40">
                  <td className="px-6 py-4 font-medium text-foreground">{e.name}</td>
                  <td className="px-6 py-4 text-muted-foreground">{e.bestFor}</td>
                  <td className="px-6 py-4 text-muted-foreground">{e.terms}</td>
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
