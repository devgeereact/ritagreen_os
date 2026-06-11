import { Link, useParams } from "react-router-dom"
import { ArrowLeft, ArrowRight, Clock } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Section, CtaBand } from "@/components/public/section"
import { ARTICLES, findArticle } from "@/lib/resources"
import NotFoundPublic from "@/pages/public/NotFoundPublic"

function fmt(date: string) {
  return new Date(date).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" })
}

export default function Article() {
  const { slug } = useParams()
  const article = slug ? findArticle(slug) : null
  if (!article) return <NotFoundPublic />

  const related = ARTICLES.filter(
    (a) => a.slug !== article.slug && a.category === article.category,
  ).slice(0, 3)
  const fallback = ARTICLES.filter((a) => a.slug !== article.slug).slice(0, 3)
  const more = related.length ? related : fallback

  return (
    <>
      <div className="border-b border-border bg-secondary/40">
        <div className="mx-auto max-w-3xl px-4 py-12 md:py-16">
          <Link to="/resources" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <ArrowLeft className="size-4" /> All resources
          </Link>
          <div className="mt-4 flex flex-wrap items-center gap-3">
            <Badge variant="secondary">{article.category}</Badge>
            <span className="text-xs text-muted-foreground">{fmt(article.date)}</span>
            <span className="flex items-center gap-1 text-xs text-muted-foreground">
              <Clock className="size-3" /> {article.readMins} min read
            </span>
          </div>
          <h1 className="mt-3 text-3xl font-bold tracking-tight md:text-4xl">{article.title}</h1>
          <p className="mt-3 text-lg text-muted-foreground">{article.excerpt}</p>
        </div>
      </div>

      <Section className="max-w-3xl">
        <div className="space-y-5 text-lg leading-relaxed text-muted-foreground">
          {article.body.map((p, i) => (
            <p key={i} className={i === 0 ? "text-foreground" : undefined}>
              {p}
            </p>
          ))}
        </div>
      </Section>

      {/* Related */}
      <Section className="max-w-3xl !pt-0">
        <h2 className="text-xl font-semibold">More reading</h2>
        <div className="mt-5 grid gap-4 sm:grid-cols-3">
          {more.map((a) => (
            <Link
              key={a.slug}
              to={`/resources/${a.slug}`}
              className="group rounded-xl border border-border bg-card p-5 transition-shadow hover:shadow-md"
            >
              <Badge variant="secondary">{a.category}</Badge>
              <h3 className="mt-3 text-sm font-semibold">{a.title}</h3>
              <span className="mt-3 inline-flex items-center gap-1 text-xs font-medium text-primary">
                Read <ArrowRight className="size-3 transition-transform group-hover:translate-x-0.5" />
              </span>
            </Link>
          ))}
        </div>
      </Section>

      <CtaBand />
    </>
  )
}
