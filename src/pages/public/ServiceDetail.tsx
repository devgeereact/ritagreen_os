import { Link, useParams } from "react-router-dom"
import { ArrowLeft, Check } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Section, CtaBand } from "@/components/public/section"
import { findService } from "@/lib/divisions"
import NotFoundPublic from "@/pages/public/NotFoundPublic"

export default function ServiceDetail() {
  const { slug } = useParams()
  const found = slug ? findService(slug) : null

  if (!found) return <NotFoundPublic />
  const { division, service } = found

  return (
    <>
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
        <div className="md:col-span-2">
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
        <aside className="rounded-xl border border-border bg-card p-6">
          <h3 className="font-semibold">Enquire about {service.name.toLowerCase()}</h3>
          <p className="mt-2 text-sm text-muted-foreground">
            Tell us what you need and when. We will confirm availability and a price.
          </p>
          <Button asChild className="mt-4 w-full">
            <Link to="/contact">Enquire now</Link>
          </Button>
        </aside>
      </Section>

      <CtaBand />
    </>
  )
}
