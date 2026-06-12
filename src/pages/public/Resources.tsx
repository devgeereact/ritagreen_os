import { useState } from "react"
import { Link } from "react-router-dom"
import { ArrowRight, Clock } from "lucide-react"
import { PageHeader, Section, CtaBand } from "@/components/public/section"
import { Badge } from "@/components/ui/badge"
import { ARTICLES, CATEGORIES } from "@/lib/resources"
import { useSeo } from "@/hooks/use-seo"

function fmt(date: string) {
  return new Date(date).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" })
}

export default function Resources() {
  useSeo({
    title: "Resources — guides on care, training, cleaning & security",
    description:
      "Practical reading for the people who hire us — care managers, landlords, agents and event organisers. Guides on choosing a care provider, SIA checks, infection control and more.",
    path: "/resources",
  })

  const [cat, setCat] = useState<string>("All")
  const sorted = [...ARTICLES].sort((a, b) => (a.date < b.date ? 1 : -1))
  const [featured, ...rest] = sorted
  const filtered = cat === "All" ? rest : rest.filter((a) => a.category === cat)

  return (
    <>
      <PageHeader
        eyebrow="Resources"
        title="Guides and articles"
        intro="Practical reading on care, training, cleaning and security, written for the people who hire us — care managers, landlords, agents and event organisers."
      />

      {/* Featured */}
      <Section className="!pb-0">
        <Link
          to={`/resources/${featured.slug}`}
          className="group grid overflow-hidden surface md:grid-cols-2"
        >
          <div className="flex min-h-[12rem] items-center justify-center bg-secondary/50 p-8">
            <span className="font-serif text-2xl font-bold text-primary/70">{featured.category}</span>
          </div>
          <div className="flex flex-col p-6 md:p-8">
            <div className="flex items-center gap-3">
              <Badge variant="secondary">{featured.category}</Badge>
              <span className="text-xs text-muted-foreground">{fmt(featured.date)}</span>
              <span className="flex items-center gap-1 text-xs text-muted-foreground">
                <Clock className="size-3" /> {featured.readMins} min read
              </span>
            </div>
            <h2 className="mt-4 text-2xl font-bold tracking-tight">{featured.title}</h2>
            <p className="mt-3 flex-1 text-muted-foreground">{featured.excerpt}</p>
            <span className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary">
              Read article <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
            </span>
          </div>
        </Link>
      </Section>

      {/* Filter + grid */}
      <Section>
        <div className="flex flex-wrap gap-2">
          {CATEGORIES.map((c) => (
            <button
              key={c}
              onClick={() => setCat(c)}
              className={
                "rounded-full border px-4 py-1.5 text-sm font-medium transition-colors " +
                (cat === c
                  ? "border-primary bg-primary text-primary-foreground"
                  : "border-border bg-card text-muted-foreground hover:bg-accent hover:text-foreground")
              }
            >
              {c}
            </button>
          ))}
        </div>

        <div className="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
          {filtered.map((a) => (
            <Link
              key={a.slug}
              to={`/resources/${a.slug}`}
              className="group flex flex-col surface p-6 transition-shadow hover:shadow-md"
            >
              <div className="flex items-center justify-between">
                <Badge variant="secondary">{a.category}</Badge>
                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                  <Clock className="size-3" /> {a.readMins} min
                </span>
              </div>
              <h2 className="mt-4 font-semibold">{a.title}</h2>
              <p className="mt-2 flex-1 text-sm text-muted-foreground">{a.excerpt}</p>
              <div className="mt-4 flex items-center justify-between">
                <span className="text-xs text-muted-foreground">{fmt(a.date)}</span>
                <span className="inline-flex items-center gap-1 text-sm font-medium text-primary">
                  Read <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                </span>
              </div>
            </Link>
          ))}
        </div>

        {filtered.length === 0 && (
          <p className="mt-8 text-sm text-muted-foreground">No articles in this category yet.</p>
        )}
      </Section>

      <CtaBand />
    </>
  )
}
