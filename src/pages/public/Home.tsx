import { Link } from "react-router-dom"
import {
  ArrowRight,
  BadgeCheck,
  Building2,
  GraduationCap,
  Home as HomeIcon,
  Hospital,
  Phone,
  School,
  ShieldCheck,
  Store,
  type LucideIcon,
} from "lucide-react"
import { CareersBand, Container, DemoLabel, Section, SectionTitle, ServiceTag, Steps } from "@/components/public/section"
import { siteButton } from "@/components/public/site-button"
import { useCompanyDetails } from "@/lib/company"
import { DEMO_POSTS, DEMO_TESTIMONIALS } from "@/lib/demo-content"
import { SITE_IMAGES, type SiteImage } from "@/lib/site-images"
import { homeLd } from "@/lib/structured-data"
import { categoryLabel, formatPostDate, useBlogPosts } from "@/lib/resources"
import { useTestimonials } from "@/lib/reviews"
import { useSeo } from "@/hooks/use-seo"
import type { ServiceId } from "@/lib/services"
import { cn } from "@/lib/utils"

// The home page, in the section order and with the draft copy of the 2026 plan
// (file 05, "Homepage"): hero, four services, how it works, why RitaGreen, a
// testimonial only when a real one is published, the careers band, latest news
// only when there is some. The footer is the layout's.
//
// The hero follows the 21st.dev "Split Hero With Image Cards" pattern: the
// serif headline and actions on the left, and on the right the two healthcare
// routes (care providers, people and families) as photograph cards, with one
// framed status card that says what the system actually enforces. The routes
// were a separate band below the hero; they are the hero's cards now.
//
// This page stays an eager import in src/routes/index.tsx (the first paint for
// most visitors), so it imports nothing heavy: no form kit, no accordion, no
// animation library. The entrance is one CSS keyframe behind `motion-safe:`.
//
// Demo content: in `npm run dev` only, the testimonial and news bands fall back
// to labelled samples from src/lib/demo-content.ts so the layout can be judged.
// Each fallback is guarded by `import.meta.env.DEV` here, which a production
// build replaces with `false`, so the samples are never shipped.

const SERVICES: { service: ServiceId; to: string; hook: string; link: string; lead?: boolean }[] = [
  { service: "care", to: "/healthcare", hook: "Cover for planned leave and last-minute gaps.", link: "Healthcare staffing", lead: true },
  { service: "cleaning", to: "/cleaning", hook: "Scheduled cleaning for properties, schools and sites.", link: "Cleaning services" },
  { service: "security", to: "/security", hook: "Trained, licensed officers for your premises.", link: "Security staffing" },
  { service: "academy", to: "/training", hook: "Mandatory and refresher courses for care teams.", link: "Training courses" },
]

const STEPS = [
  { title: "Tell us what you need", body: "Call us or send the request form with the site, dates, shifts and skills you need." },
  { title: "We match checked staff", body: "We only offer the shift to people whose checks and training are in date." },
  { title: "They arrive briefed", body: "Staff get the site notes, a contact name and the start time before they travel." },
]

// Only claims the system or the business can stand behind (file 05, "Why
// RitaGreen"). No percentages, no "24/7", no awards.
const WHY = [
  {
    title: "Checks tracked before every booking",
    body: "Right to work, DBS, licences and training are recorded for every worker, and our system will not book someone whose checks are missing or out of date.",
  },
  {
    title: "One contact for four services",
    body: "Healthcare staff, cleaning, security and training from the same office, with one number to call.",
  },
  {
    title: "Our own training team",
    body: "We run Care Certificate, mandatory and refresher training, and keep the records.",
  },
]

const TRUST: { icon: LucideIcon; label: string }[] = [
  { icon: BadgeCheck, label: "Right to work checked" },
  { icon: ShieldCheck, label: "Enhanced DBS" },
  { icon: GraduationCap, label: "Training in date" },
]

// The kinds of place the four services already describe on their own pages.
// Types of setting, not client names or logos.
const SETTINGS: { icon: LucideIcon; label: string }[] = [
  { icon: Building2, label: "Care homes" },
  { icon: Hospital, label: "Hospitals" },
  { icon: HomeIcon, label: "Supported living" },
  { icon: School, label: "Schools" },
  { icon: Store, label: "Offices and premises" },
]

/** Staggered start for the hero entrance; ignored under reduced motion. */
const rise = (delayMs: number) => ({ animationDelay: `${delayMs}ms` })
const riseClass = "motion-safe:animate-rise-in"

function HeroCard({
  to,
  image,
  eyebrow,
  title,
  cta,
  className,
  priority = false,
}: {
  to: string
  image: SiteImage
  eyebrow: string
  title: string
  cta: string
  className?: string
  priority?: boolean
}) {
  return (
    <Link
      to={to}
      className={cn(
        "group relative block overflow-hidden rounded-lg border border-border bg-[#1F3219] no-underline",
        className,
      )}
    >
      {/* Placeholder photograph: see src/lib/site-images.ts. It is not a
          RitaGreen worker or client and is never captioned as one. */}
      <img
        src={image.src}
        alt={image.alt}
        width={1200}
        height={900}
        fetchPriority={priority ? "high" : undefined}
        loading={priority ? undefined : "lazy"}
        className="absolute inset-0 size-full object-cover transition-transform duration-500 ease-out group-hover:scale-[1.03] motion-reduce:transition-none"
      />
      {/* A solid scrim under the words, not a gradient (docs/DESIGN_SYSTEM.md). */}
      <span className="absolute inset-x-0 bottom-0 flex flex-col gap-1.5 bg-[#1F3219]/85 p-4 text-white md:p-5">
        <span className="text-xs font-semibold uppercase tracking-wider text-[#C6D6BF]">{eyebrow}</span>
        <span className="font-display text-lg font-semibold leading-snug md:text-xl">{title}</span>
        <span className="mt-1 inline-flex w-max items-center gap-1.5 rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-[#1F3219] transition-colors duration-150 group-hover:bg-accent">
          {cta} <ArrowRight aria-hidden="true" className="size-4 transition-transform duration-150 group-hover:translate-x-0.5" />
        </span>
      </span>
    </Link>
  )
}

export default function Home() {
  const company = useCompanyDetails()
  useSeo({
    title: "Healthcare staffing, cleaning, security and training in London",
    description:
      "Carers, support workers and nurses for care homes, hospitals and supported living across London. Plus cleaning, security and training from the same team.",
    path: "/",
    jsonLd: homeLd(company),
  })

  const { posts, loading: postsLoading } = useBlogPosts()
  const testimonials = useTestimonials()

  // Real content first. Samples only on the dev server, and only once the real
  // request has answered with nothing, so a developer sees what visitors would.
  const demoPosts = import.meta.env.DEV && !postsLoading && posts.length === 0
  const latest = demoPosts ? DEMO_POSTS : posts.slice(0, 3)
  const demoTestimonial = import.meta.env.DEV && testimonials.length === 0
  const testimonial = demoTestimonial ? DEMO_TESTIMONIALS[0] : testimonials[0]

  return (
    <>
      {/* 1. Hero */}
      <section aria-labelledby="hero-title" className="overflow-hidden bg-background">
        <Container className="grid items-center gap-12 py-12 md:py-20 lg:grid-cols-[1fr_1.05fr] lg:gap-14">
          <div>
            <p
              className={cn(
                riseClass,
                "inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1 text-sm font-semibold text-primary",
              )}
              style={rise(0)}
            >
              <span aria-hidden="true" className="size-2 rounded-full bg-highlight" />
              Healthcare staffing across London
            </p>
            <h1
              id="hero-title"
              className={cn(riseClass, "mt-5 max-w-[16ch] text-[2.375rem] leading-[1.08] md:text-[3.5rem]")}
              style={rise(80)}
            >
              Reliable care staff when your rota can't wait.
            </h1>
            <p className={cn(riseClass, "mt-5 max-w-[52ch] text-lg leading-relaxed text-[#3D443B]")} style={rise(160)}>
              Carers, support workers and nurses for care homes, hospitals and supported living across London. Plus
              cleaning, security and training from the same team.
            </p>
            <div className={cn(riseClass, "mt-8 flex flex-wrap gap-3")} style={rise(240)}>
              <Link to="/request" className={cn(siteButton(), "group")}>
                Request staff
                <ArrowRight aria-hidden="true" className="size-4 transition-transform duration-150 group-hover:translate-x-0.5" />
              </Link>
              <a href={company.phone.href} className={siteButton({ variant: "secondary" })}>
                <Phone aria-hidden="true" className="size-4" />
                {company.phone.display}
              </a>
            </div>
            <p className={cn(riseClass, "mt-5 text-[15px] text-muted-foreground")} style={rise(300)}>
              Looking for work?{" "}
              <Link to="/careers" className="font-semibold text-primary underline underline-offset-4">
                See current roles
              </Link>
            </p>
            <ul
              className={cn(
                riseClass,
                "mt-8 flex flex-wrap gap-x-6 gap-y-3 border-t border-border pt-6 text-sm font-medium text-foreground",
              )}
              style={rise(360)}
            >
              {TRUST.map(({ icon: Icon, label }) => (
                <li key={label} className="inline-flex items-center gap-2">
                  <span aria-hidden="true" className="grid size-7 place-items-center rounded-full bg-secondary text-primary">
                    <Icon className="size-4" strokeWidth={2.25} />
                  </span>
                  {label}
                </li>
              ))}
            </ul>
          </div>

          {/* The two healthcare routes as photograph cards. */}
          <div className="relative">
            <h2 className="sr-only">Healthcare, two ways in</h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <HeroCard
                to="/healthcare/staffing"
                image={SITE_IMAGES.care}
                eyebrow="For care providers"
                title="Planned cover and short-notice gaps"
                cta="Healthcare staffing"
                priority
                className={cn(riseClass, "aspect-[4/3] sm:aspect-[3/4]")}
              />
              <HeroCard
                to="/healthcare/supported-living"
                image={SITE_IMAGES.company}
                eyebrow="For people and families"
                title="Supported living and support at home"
                cta="Talk to us"
                className={cn(riseClass, "aspect-[4/3] sm:mt-12 sm:aspect-[3/4]")}
              />
            </div>

            {/* The framed status card: the one promise the office app enforces
                (rg_deployment_check), in the status chip style of the app. */}
            <div
              className={cn(
                riseClass,
                "mt-4 flex items-center gap-3 rounded-lg border border-border bg-card p-3 pr-5 shadow-elevate sm:absolute sm:-top-7 sm:right-4 sm:mt-0",
              )}
              style={rise(420)}
            >
              <span aria-hidden="true" className="grid size-10 place-items-center rounded-md bg-success-soft text-success">
                <ShieldCheck className="size-5" strokeWidth={2.25} />
              </span>
              <span>
                <span className="block text-sm font-semibold text-foreground">Checks in date</span>
                <span className="block text-sm text-muted-foreground">Confirmed before every booking</span>
              </span>
            </div>
          </div>
        </Container>
      </section>

      {/* 2. The settings we staff, as a quiet strip. */}
      <section aria-labelledby="settings-title" className="border-t border-border bg-card">
        <Container className="flex flex-col gap-4 py-6 md:flex-row md:items-center md:gap-10">
          <h2 id="settings-title" className="shrink-0 font-sans text-sm font-semibold text-muted-foreground">
            Where our staff work
          </h2>
          <ul className="flex flex-wrap gap-x-7 gap-y-3">
            {SETTINGS.map(({ icon: Icon, label }) => (
              <li key={label} className="inline-flex items-center gap-2 text-[15px] font-medium text-foreground">
                <Icon aria-hidden="true" className="size-[18px] text-primary" />
                {label}
              </li>
            ))}
          </ul>
        </Container>
      </section>

      {/* 3. Four services. id="services" is where the retired /services lands. */}
      <Section id="services" labelledBy="services-title">
        <SectionTitle id="services-title" title="What we do" intro="Four services, one office that answers the phone." />
        <ul className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {SERVICES.map((s) => (
            <li
              key={s.to}
              className={
                s.lead
                  ? "flex flex-col gap-3 rounded-lg border border-[#9FB995] bg-accent p-5"
                  : "flex flex-col gap-3 rounded-lg border border-border bg-card p-5 transition-colors duration-150 hover:border-[#9FB995]"
              }
            >
              <ServiceTag service={s.service} className="text-lg" />
              <p className="flex-1 text-[15px] text-[#3D443B]">{s.hook}</p>
              <Link to={s.to} className="inline-flex items-center gap-1 text-[15px] font-semibold text-primary underline-offset-4 hover:underline">
                {s.link} <ArrowRight aria-hidden="true" className="size-4" />
              </Link>
            </li>
          ))}
        </ul>
      </Section>

      {/* 4. How it works */}
      <Section tone="surface" labelledBy="how-title">
        <SectionTitle id="how-title" title="How it works" />
        <Steps items={STEPS} />
      </Section>

      {/* 5. Why RitaGreen */}
      <Section labelledBy="why-title">
        <SectionTitle id="why-title" title="Why RitaGreen" />
        <ul className="mt-8 grid gap-8 md:grid-cols-3">
          {WHY.map((w) => (
            <li key={w.title} className="border-t-2 border-primary pt-4">
              <h3 className="text-lg font-semibold">{w.title}</h3>
              <p className="mt-2 text-muted-foreground">{w.body}</p>
            </li>
          ))}
        </ul>
      </Section>

      {/* 6. Testimonial: only a real, published one from the office app
          (Website > Testimonials). Nothing is shown when there is none, except
          a labelled sample on the dev server. */}
      {testimonial && (
        <Section tone="surface" labelledBy="testimonial-title">
          <h2 id="testimonial-title" className="sr-only">
            What a client says
          </h2>
          <figure className="mx-auto max-w-3xl">
            {demoTestimonial && <DemoLabel what="published testimonials replace this quote" />}
            <blockquote className="font-serif text-[1.375rem] leading-snug text-foreground md:text-[1.625rem]">
              <p>"{testimonial.text}"</p>
            </blockquote>
            <figcaption className="mt-4 text-muted-foreground">
              <span className="font-semibold text-foreground">{testimonial.author}</span>
              {testimonial.role && <span>, {testimonial.role}</span>}
            </figcaption>
          </figure>
        </Section>
      )}

      {/* 7. Careers band */}
      <CareersBand />

      {/* 8. Latest news: three posts, hidden when there are none (labelled
          samples on the dev server). */}
      {latest.length > 0 && (
        <Section labelledBy="news-title">
          {demoPosts && <DemoLabel what="published posts replace these cards" />}
          <div className="flex flex-wrap items-end justify-between gap-4">
            <SectionTitle id="news-title" title="News and advice" />
            <Link to="/news" className="font-semibold text-primary underline underline-offset-4">
              All news
            </Link>
          </div>
          <ul className="mt-8 grid gap-6 md:grid-cols-3">
            {latest.map((p) => (
              <li key={p.slug} className="group flex flex-col overflow-hidden rounded-lg border border-border bg-card">
                <div className="aspect-[16/9] overflow-hidden bg-muted">
                  <img
                    src={p.image}
                    alt=""
                    width={1200}
                    height={675}
                    loading="lazy"
                    className="size-full object-cover transition-transform duration-500 ease-out group-hover:scale-[1.03] motion-reduce:transition-none"
                  />
                </div>
                <div className="flex flex-1 flex-col p-6">
                  <p className="text-sm text-muted-foreground">
                    {categoryLabel(p.category)}
                    {p.date && <> · {formatPostDate(p.date)}</>}
                  </p>
                  <h3 className="mt-2 text-lg font-semibold">
                    {demoPosts ? (
                      p.title
                    ) : (
                      <Link to={`/news/${p.slug}`} className="underline-offset-4 hover:underline">
                        {p.title}
                      </Link>
                    )}
                  </h3>
                  <p className="mt-2 flex-1 text-[15px] text-muted-foreground">{p.excerpt}</p>
                </div>
              </li>
            ))}
          </ul>
        </Section>
      )}
    </>
  )
}
