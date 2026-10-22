import { Link, useParams } from "react-router-dom"
import { ArrowLeft, Loader2 } from "lucide-react"
import { Container, CtaBand } from "@/components/public/section"
import { categoryLabel, formatPostDate, useBlogPosts } from "@/lib/resources"
import { useCompanyDetails } from "@/lib/company"
import { articleLd } from "@/lib/structured-data"
import { useSeo } from "@/hooks/use-seo"
import NotFoundPublic from "@/pages/public/NotFoundPublic"

export default function Article() {
  const { slug } = useParams()
  const { posts, loading } = useBlogPosts()
  const company = useCompanyDetails()
  const article = posts.find((p) => p.slug === slug) ?? null

  useSeo({
    title: article ? article.title : "News",
    description: article ? article.excerpt : "News and advice from RitaGreen Services.",
    path: `/news/${slug ?? ""}`,
    ogType: "article",
    jsonLd: article ? articleLd(company, article) : undefined,
  })

  if (!article) {
    if (loading) {
      return (
        <div role="status" className="flex min-h-[50vh] items-center justify-center gap-2 text-muted-foreground">
          <Loader2 aria-hidden="true" className="size-5 animate-spin" />
          Loading the article
        </div>
      )
    }
    return <NotFoundPublic />
  }

  const more = posts.filter((a) => a.slug !== article.slug).slice(0, 3)

  return (
    <>
      <article>
        <Container className="max-w-3xl py-12 md:py-16">
          <Link to="/news" className="inline-flex items-center gap-1 text-[15px] font-semibold text-primary underline-offset-4 hover:underline">
            <ArrowLeft aria-hidden="true" className="size-4" /> All news
          </Link>
          <p className="mt-6 text-sm text-muted-foreground">
            {categoryLabel(article.category)}
            {article.date && <> · {formatPostDate(article.date)}</>} · {article.readMins} min read
          </p>
          <h1 className="mt-2 text-[2rem] leading-tight md:text-[2.75rem]">{article.title}</h1>
          {article.excerpt && <p className="mt-4 text-lg text-[#3D443B]">{article.excerpt}</p>}
          {/* The cover is decorative here: the title says what the post is about. */}
          <img
            src={article.image}
            alt=""
            loading="lazy"
            className="mt-8 aspect-[16/9] w-full rounded-lg border border-border object-cover"
          />
          <div className="mt-8 space-y-5 text-[1.0625rem] leading-relaxed">
            {article.body.map((para, i) => (
              <p key={i}>{para}</p>
            ))}
          </div>
        </Container>
      </article>

      {more.length > 0 && (
        <section aria-labelledby="more-title" className="border-t border-border bg-card">
          <Container className="py-12">
            <h2 id="more-title" className="text-[1.5rem] leading-tight">
              More news
            </h2>
            <ul className="mt-6 grid gap-4 md:grid-cols-3">
              {more.map((p) => (
                <li key={p.slug} className="rounded-lg border border-border bg-background p-5">
                  <p className="text-sm text-muted-foreground">{categoryLabel(p.category)}</p>
                  <Link to={`/news/${p.slug}`} className="mt-1 block font-semibold underline-offset-4 hover:underline">
                    {p.title}
                  </Link>
                </li>
              ))}
            </ul>
          </Container>
        </section>
      )}

      <CtaBand />
    </>
  )
}
