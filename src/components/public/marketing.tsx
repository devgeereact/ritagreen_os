import { type ReactNode } from "react"
import { Link } from "react-router-dom"
import { ArrowRight, Star } from "lucide-react"
import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { useCountUp } from "@/hooks/use-count-up"
import { useTestimonials, REVIEW_SUMMARY } from "@/lib/reviews"

/* ------------------------------------------------------------------ *
 * Oxybuild-style marketing primitives, rendered in the RitaGreen
 * navy + mint palette. Layout language borrowed from the template;
 * colours, content and tone are RitaGreen's own.
 * ------------------------------------------------------------------ */

// Eyebrow + title (+ optional intro). The recurring Oxybuild section header.
export function SectionHeading({
  eyebrow,
  title,
  intro,
  align = "left",
  className,
  children,
}: {
  eyebrow?: string
  title: ReactNode
  intro?: ReactNode
  align?: "left" | "center"
  className?: string
  children?: ReactNode
}) {
  return (
    <div
      className={cn(
        "max-w-2xl",
        align === "center" && "mx-auto max-w-3xl text-center",
        className,
      )}
    >
      {eyebrow && (
        <p className={cn("eyebrow", align === "center" && "justify-center")}>
          <span className="inline-block size-1.5 rounded-full bg-primary" />
          {eyebrow}
        </p>
      )}
      <h2 className="mt-3 font-display text-2xl font-bold tracking-tight md:text-[2rem] md:leading-[1.12]">
        {title}
      </h2>
      {intro && (
        <p className="mt-4 text-base leading-relaxed text-muted-foreground md:text-lg">{intro}</p>
      )}
      {children}
    </div>
  )
}

// Oxybuild "banner one" — a row of bold cards directly under the hero.
export function BannerCards({
  items,
}: {
  items: { title: string; body: string; to?: string; icon?: ReactNode }[]
}) {
  return (
    <div className="grid gap-px overflow-hidden rounded-2xl border border-border bg-border shadow-elevate md:grid-cols-3">
      {items.map((it, i) => {
        const Wrapper: typeof Link | "div" = it.to ? Link : "div"
        return (
          <Wrapper
            key={it.title}
            // @ts-expect-error — `to` only valid when Wrapper is Link
            to={it.to}
            className={cn(
              "group relative flex flex-col gap-3 bg-card p-7 transition-colors",
              it.to && "hover:bg-soft-mesh",
              i === 0 && "bg-primary text-primary-foreground hover:bg-primary",
            )}
          >
            {it.icon && (
              <span
                className={cn(
                  "grid size-11 place-items-center rounded-xl",
                  i === 0 ? "bg-primary-foreground/15 text-primary-foreground" : "bg-primary/10 text-primary",
                )}
              >
                {it.icon}
              </span>
            )}
            <h3 className={cn("text-lg font-semibold", i === 0 ? "text-primary-foreground" : "text-foreground")}>
              {it.title}
            </h3>
            <p className={cn("text-sm leading-relaxed", i === 0 ? "text-primary-foreground/85" : "text-muted-foreground")}>
              {it.body}
            </p>
            {it.to && (
              <span
                className={cn(
                  "mt-auto inline-flex items-center gap-1 pt-2 text-sm font-medium",
                  i === 0 ? "text-primary-foreground" : "text-primary",
                )}
              >
                Learn more
                <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-1" />
              </span>
            )}
          </Wrapper>
        )
      })}
    </div>
  )
}

// Animated stats band (Oxybuild "counter"). Splits each stat into a numeric
// part that counts up + an optional prefix/suffix that stays put.
type Stat = { n: string; l: string }

function parseStat(n: string) {
  const match = n.match(/^(\D*)(\d+)(.*)$/)
  if (!match) return { prefix: n, num: 0, suffix: "", animate: false }
  return { prefix: match[1], num: parseInt(match[2], 10), suffix: match[3], animate: true }
}

function CounterValue({ stat }: { stat: Stat }) {
  const { prefix, num, suffix, animate } = parseStat(stat.n)
  const { ref, value } = useCountUp(num)
  return (
    <span ref={ref} className="tabular">
      {animate ? (
        <>
          {prefix}
          {value}
          {suffix}
        </>
      ) : (
        stat.n
      )}
    </span>
  )
}

export function CounterBand({
  stats,
  trust,
  className,
}: {
  stats: readonly Stat[]
  /** Optional trust/accreditation pills shown above the stats, on the same band. */
  trust?: readonly string[]
  className?: string
}) {
  return (
    <section className={cn("relative overflow-hidden bg-primary text-primary-foreground", className)}>
      <div className="bg-grid-faint pointer-events-none absolute inset-0 opacity-50" />
      <div className="relative mx-auto max-w-6xl px-4 py-14 md:py-16">
        {trust && (
          <div className="mb-12 flex flex-wrap items-center justify-center gap-x-3 gap-y-3 border-b border-primary-foreground/15 pb-12">
            {trust.map((t) => (
              <span
                key={t}
                className="inline-flex items-center gap-2 rounded-full border border-primary-foreground/20 bg-primary-foreground/10 px-4 py-1.5 text-sm font-medium text-primary-foreground"
              >
                <span className="size-1.5 rounded-full bg-accent" />
                {t}
              </span>
            ))}
          </div>
        )}
        <div className="grid gap-y-10 sm:grid-cols-2 lg:grid-cols-4">
          {stats.map((s) => (
            <div key={s.l} className="px-4 text-center">
              <div className="font-display text-4xl font-bold md:text-5xl">
                <CounterValue stat={s} />
              </div>
              <div className="mt-2 text-sm font-medium text-primary-foreground/80">{s.l}</div>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

// 50/50 feature split (Oxybuild "banner two"): a visual panel beside content,
// with an optional floating "sticker" badge. The visual is a branded mesh
// panel with an icon — no stock photography.
export function FeatureSplit({
  eyebrow,
  title,
  body,
  points,
  cta,
  visual,
  image,
  sticker,
  reverse = false,
}: {
  eyebrow?: string
  title: ReactNode
  body?: ReactNode
  points?: string[]
  cta?: { to: string; label: string }
  visual?: ReactNode
  image?: { src: string; alt: string }
  sticker?: { value: string; label: string }
  reverse?: boolean
}) {
  return (
    <div className="grid items-center gap-10 md:grid-cols-2 md:gap-14">
      <div className={cn("relative", reverse && "md:order-2")}>
        <div className="bg-hero-pro relative aspect-[4/3] overflow-hidden rounded-3xl border border-border shadow-elevate">
          {image ? (
            <img
              src={image.src}
              alt={image.alt}
              loading="lazy"
              className="absolute inset-0 size-full object-cover"
            />
          ) : (
            <div className="absolute inset-0 grid place-items-center text-primary/70">{visual}</div>
          )}
        </div>
        {sticker && (
          <div className="absolute -bottom-5 -right-3 rounded-2xl bg-primary px-5 py-4 text-center text-primary-foreground shadow-lift md:-right-5">
            <div className="font-display text-2xl font-bold tabular">{sticker.value}</div>
            <div className="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-primary-foreground/80">
              {sticker.label}
            </div>
          </div>
        )}
      </div>
      <div className={cn(reverse && "md:order-1")}>
        <SectionHeading eyebrow={eyebrow} title={title} intro={body} />
        {points && (
          <ul className="mt-6 grid gap-3 sm:grid-cols-2">
            {points.map((p) => (
              <li key={p} className="flex items-start gap-2 text-sm text-muted-foreground">
                <span className="mt-1 grid size-4 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                  <svg viewBox="0 0 12 12" className="size-2.5" aria-hidden="true">
                    <path d="M2 6.5 4.5 9 10 3" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </span>
                {p}
              </li>
            ))}
          </ul>
        )}
        {cta && (
          <Button asChild size="lg" className="mt-7 shadow-lift">
            <Link to={cta.to}>
              {cta.label} <ArrowRight className="ml-1 size-4" />
            </Link>
          </Button>
        )}
      </div>
    </div>
  )
}

// Trust / accreditation strip — stands in for Oxybuild's client-logo carousel,
// but uses RitaGreen's real compliance marks rather than invented logos.
export function TrustStrip({ items }: { items: string[] }) {
  return (
    <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-3">
      {items.map((label) => (
        <span
          key={label}
          className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground shadow-soft"
        >
          <span className="size-1.5 rounded-full bg-accent" />
          {label}
        </span>
      ))}
    </div>
  )
}

// Five-star row; fills to the (rounded) rating in brand amber.
function Stars({ rating, className }: { rating: number; className?: string }) {
  return (
    <div className={cn("flex items-center gap-0.5", className)} aria-label={`${rating} out of 5`}>
      {[1, 2, 3, 4, 5].map((i) => (
        <Star
          key={i}
          className={cn(
            "size-4",
            i <= Math.round(rating) ? "fill-amber-400 text-amber-400" : "fill-muted text-muted",
          )}
        />
      ))}
    </div>
  )
}

// Google "G" mark — simple four-colour glyph for the reviews attribution.
const AVATAR_BG = ["bg-primary", "bg-accent", "bg-chart-3", "bg-chart-5"]

// Curated client testimonials. Data lives in src/lib/reviews.ts (managed in the
// CMS once the testimonials module is live). Not tied to any review platform.
export function Testimonials() {
  const items = useTestimonials()
  return (
    <div>
      <div className="flex flex-col items-center gap-3 text-center">
        <div className="flex items-center gap-3">
          <span className="font-display text-3xl font-bold tabular">{REVIEW_SUMMARY.rating.toFixed(1)}</span>
          <span className="text-left">
            <Stars rating={REVIEW_SUMMARY.rating} />
            <span className="mt-0.5 block text-xs text-muted-foreground">
              Average across {REVIEW_SUMMARY.count} client reviews
            </span>
          </span>
        </div>
      </div>

      <div className="mt-10 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
        {items.map((r, i) => (
          <figure key={`${r.author}-${i}`} className="surface flex h-full flex-col p-6">
            <Stars rating={r.rating} />
            <blockquote className="mt-4 flex-1 text-sm leading-relaxed text-muted-foreground">
              "{r.text}"
            </blockquote>
            <figcaption className="mt-5 flex items-center gap-3 border-t border-border/60 pt-4">
              <span
                className={cn(
                  "grid size-9 shrink-0 place-items-center rounded-full text-sm font-bold text-white",
                  AVATAR_BG[i % AVATAR_BG.length],
                )}
              >
                {r.author.charAt(0)}
              </span>
              <span className="min-w-0">
                <span className="block truncate text-sm font-semibold text-foreground">{r.author}</span>
                {r.role && <span className="block truncate text-xs text-muted-foreground">{r.role}</span>}
              </span>
            </figcaption>
          </figure>
        ))}
      </div>
    </div>
  )
}

