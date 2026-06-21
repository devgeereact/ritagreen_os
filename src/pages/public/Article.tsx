import { Link, useParams } from "react-router-dom"
import { ArrowLeft, ArrowRight, Clock, Loader2 } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Section, CtaBand } from "@/components/public/section"
import { useBlogPosts } from "@/lib/resources"
import { useSeo } from "@/hooks/use-seo"
import NotFoundPublic from "@/pages/public/NotFoundPublic"

function fmt(date: string) {
  return new Date(date).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" })
}

export default function Article() {
  const { slug } = useParams()
  const { posts, loading } = useBlogPosts()
  const article = posts.find((p) => p.slug === slug) ?? null

  useSeo({
    title: article ? article.title : "Article",
    description: article ? article.excerpt : "Reading from RitaGreen.",
    path: `/resources/${slug ?? ""}`,
    ogType: "article",
    jsonLd: article
      ? {
          "@context": "https://schema.org",
          "@type": "Article",
          headline: article.title,
          description: article.excerpt,
          datePublished: article.date,
          articleSection: article.category,
          author: { "@type": "Organization", name: "RitaGreen Services" },
        }
      : undefined,
  })

  if (!article) {
    if (loading) {
      return (
        <div className="flex min-h-[60vh] items-center justify-center">
          <Loader2 className="size-6 animate-spin text-muted-foreground" />
        </div>
      )
    }
    return <NotFoundPublic />
  }

  const related = posts
    .filter((a) => a.slug !== article.slug && a.category === article.category)
    .slice(0, 3)
  const fallback = posts.filter((a) => a.slug !== article.slug).slice(0, 3)
  const more = related.length ? related : fallback

  return (
    <>
      <div className="bg-hero-pro relative overflow-hidden border-b border-border">
        <div aria-hidden className="bg-hero-grid pointer-events-none absolute inset-0" />
        <div className="relative mx-auto max-w-3xl px-4 py-14 md:py-20">
          <Link to="/resources" className="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground">
            <ArrowLeft className="size-4" /> All resources
          </Link>
          <div className="mt-6 flex flex-wrap items-center gap-3 animate-fade-up">
            <Badge variant="secondary">{article.category}</Badge>
            <span className="text-xs text-muted-foreground">{fmt(article.date)}</span>
            <span className="flex items-center gap-1 text-xs text-muted-foreground">
              <Clock className="size-3" /> {article.readMins} min read
            </span>
          </div>
          <h1 className="mt-3 font-display text-[1.9rem] font-bold leading-tight tracking-tight md:text-[2.7rem] animate-fade-up animation-delay-100">{article.title}</h1>
          <p className="mt-4 text-lg leading-relaxed text-muted-foreground animate-fade-up animation-delay-200">{article.excerpt}</p>
        </div>
      </div>

      {/* Cover */}
      {article.image && (
        <Section className="max-w-3xl !pb-0">
          <img
            src={article.image}
            alt=""
            className="aspect-[16/9] w-full rounded-2xl border border-border object-cover shadow-elevate"
          />
        </Section>
      )}

      <Section className="max-w-3xl">
        <div className="space-y-5 text-lg leading-relaxed text-muted-foreground">
          {article.body.map((p, i) => (
            <p key={i} className={i === 0 ? "text-xl font-medium leading-relaxed text-foreground" : undefined}>
              {p}
            </p>
          ))}
        </div>
      </Section>

      {/* Related */}
      {more.length > 0 && (
        <Section className="max-w-3xl !pt-0">
          <h2 className="text-xl font-semibold">More reading</h2>
          <div className="mt-5 grid gap-4 sm:grid-cols-3">
            {more.map((a) => (
              <Link
                key={a.slug}
                to={`/resources/${a.slug}`}
                className="group surface-interactive flex flex-col overflow-hidden"
              >
                <div className="relative aspect-[16/10] overflow-hidden">
                  <img
                    src={a.image}
                    alt=""
                    loading="lazy"
                    className="absolute inset-0 size-full object-cover transition-transform duration-500 group-hover:scale-105"
                  />
                </div>
                <div className="flex flex-1 flex-col p-5">
                  <Badge variant="secondary" className="self-start">{a.category}</Badge>
                  <h3 className="mt-3 text-sm font-semibold">{a.title}</h3>
                  <span className="mt-3 inline-flex items-center gap-1 text-xs font-medium text-primary">
                    Read <ArrowRight className="size-3 transition-transform group-hover:translate-x-1" />
                  </span>
                </div>
              </Link>
            ))}
          </div>
        </Section>
      )}

      <CtaBand />
    </>
  )
}
