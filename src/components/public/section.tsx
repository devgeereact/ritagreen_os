import { type ReactNode } from "react"
import { Link } from "react-router-dom"
import { Check } from "lucide-react"
import { cn } from "@/lib/utils"
import { SERVICE_ACCENTS, SERVICE_LABELS, type ServiceId } from "@/lib/services"
import { siteButton } from "@/components/public/site-button"

// The website's building blocks, drawn from the 2026 homepage mock
// (docs/Plans 2026/design-preview.html, "Website"): warm paper, white surfaces
// with a 1px border, deep green blocks for emphasis, serif headings, 8px radius
// and no shadow on anything that is not a menu or a dialog. No gradients, grids,
// glows, floating cards or scroll animations (file 04, "What to avoid").

export function Container({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cn("mx-auto w-full max-w-6xl px-4 sm:px-6", className)}>{children}</div>
}

const TONES = {
  paper: "bg-background",
  surface: "bg-card",
  // A quiet green band: green-50, not a gradient.
  tint: "bg-accent",
} as const

/** A page band. Bands are separated by a hairline, not by colour alone. */
export function Section({
  id,
  tone = "paper",
  className,
  children,
  labelledBy,
}: {
  id?: string
  tone?: keyof typeof TONES
  className?: string
  children: ReactNode
  /** id of the band's heading, so the region has a name. */
  labelledBy?: string
}) {
  return (
    <section id={id} aria-labelledby={labelledBy} className={cn("scroll-mt-20 border-t border-border", TONES[tone])}>
      <Container className={cn("py-14 md:py-20", className)}>{children}</Container>
    </section>
  )
}

/** Kept for the pages that used the old alternating band. */
export function SectionAlt(props: { id?: string; className?: string; children: ReactNode }) {
  return <Section tone="surface" {...props} />
}

/**
 * The opening of every page below the home page: a breadcrumb, an optional
 * label, the page's single h1 and a short introduction.
 */
export function PageHeader({
  eyebrow,
  title,
  intro,
  breadcrumb,
  trail,
  children,
}: {
  eyebrow?: string
  title: string
  intro?: ReactNode
  /** Current-page label for the breadcrumb. Falls back to the eyebrow, then the title. */
  breadcrumb?: string
  /** Pages between Home and this one, e.g. Healthcare above Staffing. */
  trail?: { to: string; label: string }[]
  /** Actions under the introduction. */
  children?: ReactNode
}) {
  const here = breadcrumb ?? eyebrow ?? title
  return (
    <div className="bg-background">
      <Container className="py-12 md:py-16">
        <nav aria-label="Breadcrumb">
          <ol className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <li>
              <Link to="/" className="underline-offset-4 hover:text-foreground hover:underline">
                Home
              </Link>
            </li>
            {trail?.map((t) => (
              <li key={t.to} className="flex items-center gap-2">
                <span aria-hidden="true">/</span>
                <Link to={t.to} className="underline-offset-4 hover:text-foreground hover:underline">
                  {t.label}
                </Link>
              </li>
            ))}
            <li className="flex items-center gap-2">
              <span aria-hidden="true">/</span>
              <span aria-current="page" className="text-foreground">
                {here}
              </span>
            </li>
          </ol>
        </nav>
        {eyebrow && <p className="eyebrow mt-6">{eyebrow}</p>}
        <h1 className={cn("max-w-[22ch] text-[2rem] leading-[1.12] md:text-[2.75rem]", eyebrow ? "mt-2" : "mt-6")}>
          {title}
        </h1>
        {intro && <div className="mt-5 max-w-[60ch] text-lg leading-relaxed text-[#3D443B]">{intro}</div>}
        {children && <div className="mt-7 flex flex-wrap gap-3">{children}</div>}
      </Container>
    </div>
  )
}

/** A band heading: an h2 in the serif, with an optional line under it. */
export function SectionTitle({
  id,
  eyebrow,
  title,
  intro,
  className,
}: {
  id?: string
  eyebrow?: string
  title: string
  intro?: ReactNode
  className?: string
}) {
  return (
    <div className={cn("max-w-[62ch]", className)}>
      {eyebrow && <p className="eyebrow mb-2">{eyebrow}</p>}
      <h2 id={id} className="text-[1.625rem] leading-tight md:text-[2.125rem]">
        {title}
      </h2>
      {intro && <p className="mt-3 text-muted-foreground">{intro}</p>}
    </div>
  )
}

/** A service name with its small coloured dot. Never a filled surface. */
export function ServiceTag({ service, className }: { service: ServiceId; className?: string }) {
  return (
    <span className={cn("inline-flex items-center gap-2 font-semibold text-foreground", className)}>
      <span aria-hidden="true" className="size-2 shrink-0 rounded-full" style={{ backgroundColor: SERVICE_ACCENTS[service] }} />
      {SERVICE_LABELS[service]}
    </span>
  )
}

/** A plain list with a green tick. The tick is decoration; the words carry the meaning. */
export function CheckList({ items, className }: { items: readonly string[]; className?: string }) {
  return (
    <ul className={cn("grid gap-3", className)}>
      {items.map((item) => (
        <li key={item} className="flex items-start gap-3">
          <Check aria-hidden="true" className="mt-1 size-4 shrink-0 text-primary" strokeWidth={2.5} />
          <span>{item}</span>
        </li>
      ))}
    </ul>
  )
}

/** Three numbered steps, as in the mock's "How it works". */
export function Steps({ items, className }: { items: readonly { title: string; body: string }[]; className?: string }) {
  return (
    <ol className={cn("mt-8 grid gap-8 md:grid-cols-3", className)}>
      {items.map((s, i) => (
        <li key={s.title} className="grid grid-cols-[2.5rem_1fr] gap-x-4 gap-y-1">
          <span
            aria-hidden="true"
            className="row-span-2 grid size-9 place-items-center rounded-full border border-primary font-bold text-primary"
          >
            {i + 1}
          </span>
          <span className="text-lg font-semibold">{s.title}</span>
          <span className="text-muted-foreground">{s.body}</span>
        </li>
      ))}
    </ol>
  )
}

/** The careers band on green-900, from the mock. */
export function CareersBand({
  title = "Join a team that looks after its people",
  body = "Shifts in healthcare, cleaning and security, with training and an office that knows your name.",
}: {
  title?: string
  body?: string
}) {
  return (
    <section aria-labelledby="careers-band-title" className="bg-[#1F3219] text-white">
      <Container className="flex flex-wrap items-center justify-between gap-x-10 gap-y-6 py-12 md:py-14">
        <div>
          <h2 id="careers-band-title" className="max-w-[22ch] text-[1.625rem] leading-tight md:text-[2rem]">
            {title}
          </h2>
          <p className="mt-2 max-w-[56ch] text-[#D9E3D4]">{body}</p>
        </div>
        <Link to="/careers" className={siteButton({ variant: "light" })}>
          See current roles
        </Link>
      </Container>
    </section>
  )
}

/** The closing prompt on content pages: request staff, or call. */
export function CtaBand({
  title = "Need staff, cover or a quote?",
  body = "Tell us what you need and the right person in the office will pick it up.",
  ctaTo = "/request",
  ctaLabel = "Request staff",
}: {
  title?: string
  body?: string
  ctaTo?: string
  ctaLabel?: string
}) {
  return (
    <Section tone="surface">
      <div className="flex flex-col items-start justify-between gap-6 rounded-lg border border-border bg-background p-6 md:flex-row md:items-center md:p-10">
        <div>
          <h2 className="text-[1.5rem] leading-tight md:text-[1.75rem]">{title}</h2>
          <p className="mt-2 max-w-[56ch] text-muted-foreground">{body}</p>
        </div>
        <div className="flex flex-wrap gap-3">
          <Link to={ctaTo} className={siteButton()}>
            {ctaLabel}
          </Link>
          <Link to="/contact" className={siteButton({ variant: "secondary" })}>
            Contact us
          </Link>
        </div>
      </div>
    </Section>
  )
}
