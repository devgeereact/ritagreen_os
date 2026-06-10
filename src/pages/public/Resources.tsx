import { Link } from "react-router-dom"
import { ArrowRight } from "lucide-react"
import { PageHeader, Section } from "@/components/public/section"
import { Badge } from "@/components/ui/badge"
import { ARTICLES } from "@/lib/resources"

function fmt(date: string) {
  return new Date(date).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" })
}

export default function Resources() {
  return (
    <>
      <PageHeader
        eyebrow="Resources"
        title="Guides and articles"
        intro="Practical reading on care, training, cleaning and security, written for the people who hire us."
      />
      <Section>
        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
          {ARTICLES.map((a) => (
            <Link
              key={a.slug}
              to={`/resources/${a.slug}`}
              className="group flex flex-col rounded-xl border border-border bg-card p-6 transition-shadow hover:shadow-md"
            >
              <div className="flex items-center justify-between">
                <Badge variant="secondary">{a.category}</Badge>
                <span className="text-xs text-muted-foreground">{fmt(a.date)}</span>
              </div>
              <h2 className="mt-4 font-semibold">{a.title}</h2>
              <p className="mt-2 flex-1 text-sm text-muted-foreground">{a.excerpt}</p>
              <span className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary">
                Read <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
              </span>
            </Link>
          ))}
        </div>
      </Section>
    </>
  )
}
