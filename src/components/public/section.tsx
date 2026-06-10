import { Link } from "react-router-dom"
import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"

export function Section({
  className,
  children,
  id,
}: {
  className?: string
  children: React.ReactNode
  id?: string
}) {
  return (
    <section id={id} className={cn("mx-auto max-w-6xl px-4 py-14 md:py-20", className)}>
      {children}
    </section>
  )
}

export function PageHeader({
  eyebrow,
  title,
  intro,
}: {
  eyebrow?: string
  title: string
  intro?: string
}) {
  return (
    <div className="border-b border-border bg-secondary/40">
      <div className="mx-auto max-w-6xl px-4 py-14 md:py-20">
        {eyebrow && (
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-primary">{eyebrow}</p>
        )}
        <h1 className="mt-2 max-w-3xl text-3xl font-bold tracking-tight md:text-4xl">{title}</h1>
        {intro && <p className="mt-4 max-w-2xl text-lg text-muted-foreground">{intro}</p>}
      </div>
    </div>
  )
}

export function CtaBand({
  title = "Need staff, training or a quote?",
  body = "Tell us what you need and we will route it to the right team. One enquiry, one trusted name.",
}: {
  title?: string
  body?: string
}) {
  return (
    <Section>
      <div className="rounded-2xl bg-primary px-6 py-10 text-primary-foreground md:px-12 md:py-14">
        <div className="flex flex-col items-start justify-between gap-6 md:flex-row md:items-center">
          <div>
            <h2 className="text-2xl font-bold md:text-3xl">{title}</h2>
            <p className="mt-2 max-w-xl text-primary-foreground/80">{body}</p>
          </div>
          <Button asChild size="lg" variant="secondary" className="shrink-0">
            <Link to="/contact">Enquire now</Link>
          </Button>
        </div>
      </div>
    </Section>
  )
}
