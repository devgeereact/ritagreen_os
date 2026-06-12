import { Link, useParams } from "react-router-dom"
import { ArrowLeft, ArrowRight, Check, Phone, ShieldCheck } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Section, CtaBand } from "@/components/public/section"
import { findService } from "@/lib/divisions"
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

  return (
    <>
      {/* Hero */}
      <div className="border-b border-border bg-secondary/40">
        <div className="mx-auto max-w-6xl px-4 py-12 md:py-16">
          <Link
            to={`/services#${division.id}`}
            className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="size-4" /> {division.name}
          </Link>
          <div className="mt-3 flex items-center gap-2">
            <span className="size-3 rounded-full" style={{ backgroundColor: division.accent }} />
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              {division.name}
            </p>
          </div>
          <h1 className="mt-2 text-3xl font-bold tracking-tight md:text-4xl">{service.name}</h1>
          <p className="mt-3 max-w-2xl text-lg text-muted-foreground">{service.summary}</p>
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
          <div className="rounded-xl border border-border bg-secondary/40 p-6">
            <h2 className="flex items-center gap-2 text-xl font-semibold">
              <ShieldCheck className="size-5 text-primary" /> How we keep it safe and compliant
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
                    className="group rounded-xl border border-border bg-card p-5 transition-shadow hover:shadow-md"
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
        <aside className="md:sticky md:top-24 md:h-fit">
          <div className="rounded-xl border border-border bg-card p-6">
            <h3 className="font-semibold">Get a quote for {service.name.toLowerCase()}</h3>
            <p className="mt-2 text-sm text-muted-foreground">
              Tell us what you need and when. We'll confirm availability and a price in writing.
            </p>
            <Button asChild className="mt-4 w-full">
              <Link to={`/quote?service=${division.id}`}>Get a quote</Link>
            </Button>
            <Button asChild variant="outline" className="mt-2 w-full">
              <Link to="/contact">Ask a question</Link>
            </Button>
            <a
              href={COMPANY.phoneHref}
              className="mt-3 flex items-center justify-center gap-2 text-sm font-medium text-primary hover:underline"
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
