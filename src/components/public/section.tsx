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
    <div className="relative overflow-hidden border-b border-border bg-hero-mesh">
      <div className="mx-auto max-w-6xl px-4 py-16 md:py-24">
        {eyebrow && (
          <p className="eyebrow animate-fade-up">
            <span className="inline-block size-1.5 rounded-full bg-primary" />
            {eyebrow}
          </p>
        )}
        <h1 className="mt-3 max-w-3xl animate-fade-up animation-delay-100 font-display text-[2rem] font-bold leading-[1.08] tracking-tight md:text-[2.9rem]">
          {title}
        </h1>
        {intro && (
          <p className="mt-5 max-w-2xl animate-fade-up animation-delay-200 text-lg leading-relaxed text-muted-foreground">
            {intro}
          </p>
        )}
      </div>
    </div>
  )
}

export function CtaBand({
  title = "Need staff, training or a quote?",
  body = "Tell us what you need and we will route it to the right team. One enquiry, one trusted name.",
  ctaTo = "/quote",
  ctaLabel = "Get a quote",
}: {
  title?: string
  body?: string
  ctaTo?: string
  ctaLabel?: string
}) {
  return (
    <Section>
      <div className="relative overflow-hidden rounded-3xl bg-primary px-6 py-12 text-primary-foreground shadow-lift md:px-12 md:py-16">
        <div className="bg-grid-faint pointer-events-none absolute inset-0 opacity-60" />
        <div
          className="pointer-events-none absolute -right-16 -top-16 size-64 rounded-full opacity-40"
          style={{ background: "radial-gradient(circle, rgba(255,255,255,0.25), transparent 70%)" }}
        />
        <div className="relative flex flex-col items-start justify-between gap-6 md:flex-row md:items-center">
          <div>
            <h2 className="text-2xl font-bold md:text-3xl">{title}</h2>
            <p className="mt-3 max-w-xl text-primary-foreground/85">{body}</p>
          </div>
          <div className="flex shrink-0 flex-wrap gap-3">
            <Button asChild size="lg" variant="secondary">
              <Link to={ctaTo}>{ctaLabel}</Link>
            </Button>
            <Button
              asChild
              size="lg"
              variant="outline"
              className="border-primary-foreground/40 bg-transparent text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground"
            >
              <Link to="/contact">Talk to us</Link>
            </Button>
          </div>
        </div>
      </div>
    </Section>
  )
}
