import { Link } from "react-router-dom"
import { ArrowRight } from "lucide-react"
import { ServiceTag } from "@/components/public/section"
import type { PublicVacancy } from "@/lib/types"

/** The facts a visitor scans for: where, when, and pay if the office has given it. */
function vacancyFacts(v: Pick<PublicVacancy, "location" | "hours_text" | "pay_text">): string[] {
  return [v.location, v.hours_text, v.pay_text ?? ""].map((s) => s.trim()).filter(Boolean)
}

/** One role in a list: the whole card is the link to its role page. */
export function VacancyCard({ vacancy: v }: { vacancy: PublicVacancy }) {
  const facts = vacancyFacts(v)
  return (
    <Link
      to={`/careers/${v.slug}`}
      className="group flex h-full flex-col rounded-lg border border-border bg-background p-5 transition-colors hover:border-primary sm:p-6"
    >
      <ServiceTag service={v.service} className="text-sm" />
      <h3 className="mt-3 text-xl leading-snug group-hover:underline group-hover:underline-offset-4">{v.title}</h3>
      {facts.length > 0 && (
        <p className="mt-2 text-[15px] text-muted-foreground">{facts.join(" · ")}</p>
      )}
      <p className="mt-3 line-clamp-3 text-[15px]">{v.summary}</p>
      <span className="mt-auto inline-flex items-center gap-1.5 pt-4 font-semibold text-primary">
        View role and apply <ArrowRight aria-hidden="true" className="size-4" />
      </span>
    </Link>
  )
}
