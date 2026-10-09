import { useState } from "react"
import { Link } from "react-router-dom"
import { Loader2 } from "lucide-react"
import { PageHeader, Section } from "@/components/public/section"
import { CATEGORIES, categoryLabel, formatPostDate, useBlogPosts } from "@/lib/resources"
import { useSeo } from "@/hooks/use-seo"
import { cn } from "@/lib/utils"

// /news: published posts from the office app. Replaces /resources, which
// redirects here. Nothing is shown that was not published.
export default function News() {
  useSeo({
    title: "News and advice",
    description: "News and practical advice from RitaGreen Services for care providers, property managers and people who work with us.",
    path: "/news",
  })

  const { posts, loading, failed } = useBlogPosts()
  const [category, setCategory] = useState<string>("All")
  const present = new Set(posts.map((p) => p.category))
  const filters = CATEGORIES.filter((c) => c === "All" || present.has(c))
  const shown = category === "All" ? posts : posts.filter((p) => p.category === category)

  return (
    <>
      <PageHeader
        eyebrow="News"
        title="News and advice"
        intro={<p>Updates from RitaGreen and practical reading for the people who work with us.</p>}
      />
      <Section tone="surface">
        {loading ? (
          <p role="status" className="flex items-center gap-2 text-muted-foreground">
            <Loader2 aria-hidden="true" className="size-4 animate-spin" /> Loading news
          </p>
        ) : posts.length === 0 ? (
          <div className="max-w-[60ch]">
            <h2 className="text-[1.5rem] leading-tight">Nothing published yet</h2>
            <p className="mt-2 text-muted-foreground">
              {failed
                ? "We could not load the news just now. Please try again later."
                : "There are no news posts at the moment. Please check back soon."}
            </p>
          </div>
        ) : (
          <>
            {filters.length > 2 && (
              <div role="group" aria-label="Filter by topic" className="flex flex-wrap gap-2">
                {filters.map((c) => (
                  <button
                    key={c}
                    type="button"
                    aria-pressed={category === c}
                    onClick={() => setCategory(c)}
                    className={cn(
                      "min-h-9 rounded-lg border px-3.5 text-[15px] font-medium transition-colors",
                      category === c ? "border-primary bg-accent text-primary" : "border-border bg-card text-foreground hover:border-primary",
                    )}
                  >
                    {c === "All" ? "All topics" : categoryLabel(c)}
                  </button>
                ))}
              </div>
            )}
            <h2 className="sr-only">Posts</h2>
            <ul className="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
              {shown.map((p) => (
                <li key={p.slug} className="flex flex-col overflow-hidden rounded-lg border border-border bg-background">
                  <img src={p.image} alt="" loading="lazy" className="aspect-[16/10] w-full object-cover" />
                  <div className="flex flex-1 flex-col p-6">
                    <p className="text-sm text-muted-foreground">
                      {categoryLabel(p.category)}
                      {p.date && <> · {formatPostDate(p.date)}</>}
                    </p>
                    <h3 className="mt-2 text-lg font-semibold">
                      <Link to={`/news/${p.slug}`} className="underline-offset-4 hover:underline">
                        {p.title}
                      </Link>
                    </h3>
                    <p className="mt-2 flex-1 text-[15px] text-muted-foreground">{p.excerpt}</p>
                  </div>
                </li>
              ))}
            </ul>
          </>
        )}
      </Section>
    </>
  )
}
