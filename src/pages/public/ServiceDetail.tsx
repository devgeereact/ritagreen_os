import { Link, useParams } from "react-router-dom"
import { ArrowLeft, ArrowRight, Check, Phone, ShieldCheck } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Section, CtaBand } from "@/components/public/section"
import { findService } from "@/lib/divisions"
import { divisionIcon, accentTint } from "@/lib/division-icons"
import { COMPANY } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"
import NotFoundPublic from "@/pages/public/NotFoundPublic"

export default function ServiceDetail() {
  const { slug } = useParams()
  const found = slug ? findService(slug) : null

  useSeo({
    title: found ? `${found.service.name} — ${found.division.name}` : "Service not found",
    description: found
      ? `${found.service.summary} ${found.division.headline} Checked, qualified people from ${COMPANY.legalName}.`
      : "We couldn't find that service.",
    path: `/services/${slug ?? ""}`,
    jsonLd: found
      ? {
          "@context": "https://schema.org",
          "@type": "Service",
          name: found.service.name,
          serviceType: found.division.name,
          description: found.service.summary,
          areaServed: "London, United Kingdom",
          provider: {
            "@type": "Organization",
            name: COMPANY.legalName,
            url: "https://ritagreenservices.com",
          },
        }
      : undefined,
  })

  if (!found) return <NotFoundPublic />
  const { division, service } = found
  const related = division.services.filter((s) => s.slug !== service.slug)
  const Icon = divisionIcon(division.id)

  return (
    <>
      {/* Hero */}
      <div className="relative overflow-hidden border-b border-border bg-hero-mesh">
        <div className="mx-auto max-w-6xl px-4 py-14 md:py-20">
          <Link
            to={`/services#${division.id}`}
            className="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
          >
            <ArrowLeft className="size-4" /> {division.name}
          </Link>
          <div className="mt-6 flex items-start gap-4 animate-fade-up">
            <span
              className="grid size-14 shrink-0 place-items-center rounded-2xl ring-1 ring-inset ring-black/5"
              style={{ backgroundColor: accentTint(division.accent, 0.14), color: division.accent }}
            >
              <Icon className="size-7" strokeWidth={1.75} />
            </span>
            <div>
              <p className="eyebrow">{division.name}</p>
              <h1 className="mt-1.5 text-3xl font-bold leading-tight md:text-5xl">{service.name}</h1>
            </div>
          </div>
          <p className="mt-5 max-w-2xl text-lg leading-relaxed text-muted-foreground animate-fade-up animation-delay-100">
            {service.summary}
          </p>
        </div>
      </div>

      <Section className="grid gap-12 md:grid-cols-3">
        <div className="space-y-10 md:col-span-2">
          {/* Overview */}
          <div>
            <h2 className="text-xl font-semibold">Overview</h2>
            <p className="mt-3 text-muted-foreground">{division.intro}</p>
          </div>

          {/* What's included */}
          <div>
            <h2 className="text-xl font-semibold">What's included</h2>
            <ul className="mt-4 space-y-3">
              {service.points.map((p) => (
                <li key={p} className="flex items-start gap-3 text-muted-foreground">
                  <Check className="mt-0.5 size-5 shrink-0 text-primary" />
                  <span>{p}</span>
                </li>
              ))}
            </ul>
          </div>

          {/* Assurance */}
          <div className="surface bg-soft-mesh p-7">
            <h2 className="flex items-center gap-2 text-xl font-semibold">
              <span className="grid size-9 place-items-center rounded-xl bg-primary/10 text-primary">
                <ShieldCheck className="size-5" />
              </span>
              How we keep it safe and compliant
            </h2>
            <ul className="mt-4 space-y-3">
              {division.assurance.map((a) => (
                <li key={a} className="flex items-start gap-3 text-sm text-muted-foreground">
                  <Check className="mt-0.5 size-4 shrink-0 text-primary" />
                  <span>{a}</span>
                </li>
              ))}
            </ul>
          </div>

          {/* Who it's for */}
          <div>
            <h2 className="text-xl font-semibold">Who it's for</h2>
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
              {division.audience.map((a) => (
                <div
                  key={a}
                  className="flex items-start gap-2 rounded-lg border border-border bg-card p-4 text-sm text-muted-foreground"
                >
                  <Check className="mt-0.5 size-4 shrink-0 text-primary" /> {a}
                </div>
              ))}
            </div>
          </div>

          {/* Related */}
          {related.length > 0 && (
            <div>
              <h2 className="text-xl font-semibold">More from {division.name}</h2>
              <div className="mt-4 grid gap-4 sm:grid-cols-2">
                {related.map((s) => (
                  <Link
                    key={s.slug}
                    to={`/services/${s.slug}`}
                    className="group surface p-5 transition-shadow hover:shadow-md"
                  >
                    <h3 className="font-semibold">{s.name}</h3>
                    <p className="mt-2 text-sm text-muted-foreground">{s.summary}</p>
                    <span className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary">
                      View <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                    </span>
                  </Link>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Sticky enquiry aside */}
        <aside className="md:sticky md:top-28 md:h-fit">
          <div className="surface overflow-hidden p-7 shadow-elevate">
            <span
              className="grid size-11 place-items-center rounded-xl ring-1 ring-inset ring-black/5"
              style={{ backgroundColor: accentTint(division.accent, 0.14), color: division.accent }}
            >
              <Icon className="size-5" strokeWidth={1.75} />
            </span>
            <h3 className="mt-4 text-lg font-semibold">Get a quote for {service.name.toLowerCase()}</h3>
            <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
              Tell us what you need and when. We'll confirm availability and a price in writing.
            </p>
            <Button asChild className="mt-5 w-full shadow-lift">
              <Link to={`/quote?service=${division.id}`}>Get a quote</Link>
            </Button>
            <Button asChild variant="outline" className="mt-2 w-full">
              <Link to="/contact">Ask a question</Link>
            </Button>
            <a
              href={COMPANY.phoneHref}
              className="mt-4 flex items-center justify-center gap-2 text-sm font-medium text-primary hover:underline"
            >
              <Phone className="size-4" /> {COMPANY.phone}
            </a>
          </div>
        </aside>
      </Section>

      <CtaBand />
    </>
  )
}
