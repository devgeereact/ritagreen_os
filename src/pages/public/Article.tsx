import { Link, useParams } from "react-router-dom"
import { ArrowLeft } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Section, CtaBand } from "@/components/public/section"
import { findArticle } from "@/lib/resources"
import NotFoundPublic from "@/pages/public/NotFoundPublic"

function fmt(date: string) {
  return new Date(date).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" })
}

export default function Article() {
  const { slug } = useParams()
  const article = slug ? findArticle(slug) : null
  if (!article) return <NotFoundPublic />

  return (
    <>
      <div className="border-b border-border bg-secondary/40">
        <div className="mx-auto max-w-3xl px-4 py-12 md:py-16">
          <Link to="/resources" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <ArrowLeft className="size-4" /> All resources
          </Link>
          <div className="mt-4 flex items-center gap-3">
            <Badge variant="secondary">{article.category}</Badge>
            <span className="text-xs text-muted-foreground">{fmt(article.date)}</span>
          </div>
          <h1 className="mt-3 text-3xl font-bold tracking-tight md:text-4xl">{article.title}</h1>
        </div>
      </div>

      <Section className="max-w-3xl">
        <div className="space-y-5 text-lg leading-relaxed text-muted-foreground">
          {article.body.map((p, i) => (
            <p key={i}>{p}</p>
          ))}
        </div>
      </Section>

      <CtaBand />
    </>
  )
}
