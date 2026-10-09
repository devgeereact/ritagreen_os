import { Link } from "react-router-dom"
import { ArrowRight, Check } from "lucide-react"
import { CareersBand, Container, Section, SectionTitle, ServiceTag, Steps } from "@/components/public/section"
import { siteButton } from "@/components/public/site-button"
import { useCompanyDetails } from "@/lib/company"
import { SITE_IMAGES } from "@/lib/site-images"
import { homeLd } from "@/lib/structured-data"
import { categoryLabel, formatPostDate, useBlogPosts } from "@/lib/resources"
import { useTestimonials } from "@/lib/reviews"
import { useSeo } from "@/hooks/use-seo"
import type { ServiceId } from "@/lib/services"

// The home page, in the section order and with the draft copy of the 2026 plan
// (file 05, "Homepage"): hero, two healthcare routes, four services, how it
// works, why RitaGreen, a testimonial only when a real one is published, the
// careers band, latest news only when there is some. The footer is the layout's.
//
// This page stays an eager import in src/routes/index.tsx (the first paint for
// most visitors), so it imports nothing heavy: no form kit, no accordion.

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

export default function Home() {
  const company = useCompanyDetails()
  useSeo({
    title: "Healthcare staffing, cleaning, security and training in London",
    description:
      "Carers, support workers and nurses for care homes, hospitals and supported living across London. Plus cleaning, security and training from the same team.",
    path: "/",
    jsonLd: homeLd(company),
  })

  const { posts } = useBlogPosts()
  const latest = posts.slice(0, 3)
  const testimonials = useTestimonials()
  const testimonial = testimonials[0]

  return (
    <>
      {/* 1. Hero */}
      <section aria-labelledby="hero-title" className="bg-background">
        <Container className="grid items-center gap-10 py-12 md:grid-cols-[1.1fr_0.9fr] md:gap-12 md:py-20">
          <div>
            <p className="eyebrow">Healthcare staffing across London</p>
            <h1 id="hero-title" className="mt-3 max-w-[16ch] text-[2.25rem] leading-[1.1] md:text-[3.25rem]">
              Reliable care staff when your rota can't wait.
            </h1>
            <p className="mt-5 max-w-[52ch] text-lg leading-relaxed text-[#3D443B]">
              Carers, support workers and nurses for care homes, hospitals and supported living across London. Plus
              cleaning, security and training from the same team.
            </p>
            <div className="mt-7 flex flex-wrap gap-3">
              <Link to="/request" className={siteButton()}>
                Request staff
              </Link>
              <Link to="/healthcare/supported-living" className={siteButton({ variant: "secondary" })}>
                Arrange care
              </Link>
            </div>
            <p className="mt-5 text-[15px] text-muted-foreground">
              Looking for work?{" "}
              <Link to="/careers" className="font-semibold text-primary underline underline-offset-4">
                See current roles
              </Link>
            </p>
            <ul className="mt-7 flex flex-wrap gap-x-5 gap-y-2 border-t border-border pt-5 text-sm text-muted-foreground">
              {["Right to work checked", "Enhanced DBS", "Training in date"].map((c) => (
                <li key={c} className="inline-flex items-center gap-1.5">
                  <Check aria-hidden="true" className="size-4 text-primary" strokeWidth={2.5} />
                  {c}
                </li>
              ))}
            </ul>
          </div>
          {/* Placeholder photograph: see src/lib/site-images.ts. It is not a
              RitaGreen carer or client and is never captioned as one. Replace
              with the commissioned team photograph. */}
          <img
            src={SITE_IMAGES.care.src}
            alt={SITE_IMAGES.care.alt}
            width={1200}
            height={900}
            fetchPriority="high"
            className="aspect-[4/3] w-full rounded-lg border border-border object-cover"
          />
        </Container>
      </section>

      {/* 2. Two healthcare routes */}
      <Section tone="surface" labelledBy="routes-title">
        <h2 id="routes-title" className="sr-only">
          Healthcare, two ways in
        </h2>
        <ul className="grid gap-4 md:grid-cols-2">
          <li>
            <Link to="/healthcare/staffing" className="flex h-full flex-col gap-2 rounded-lg border border-border bg-background p-6 transition-colors hover:border-primary">
              <span className="eyebrow">For care providers</span>
              <span className="text-xl font-semibold text-foreground">Planned cover and short-notice gaps</span>
              <span className="text-muted-foreground">Carers, support workers and nurses for care homes, hospitals and day centres.</span>
              <span className="mt-auto inline-flex items-center gap-1 pt-2 font-semibold text-primary">
                Healthcare staffing <ArrowRight aria-hidden="true" className="size-4" />
              </span>
            </Link>
          </li>
          <li>
            <Link to="/healthcare/supported-living" className="flex h-full flex-col gap-2 rounded-lg border border-border bg-background p-6 transition-colors hover:border-primary">
              <span className="eyebrow">For people and families</span>
              <span className="text-xl font-semibold text-foreground">Supported living and support at home</span>
              <span className="text-muted-foreground">Start with a conversation about what would help.</span>
              <span className="mt-auto inline-flex items-center gap-1 pt-2 font-semibold text-primary">
                Talk to us about support <ArrowRight aria-hidden="true" className="size-4" />
              </span>
            </Link>
          </li>
        </ul>
      </Section>

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
                  : "flex flex-col gap-3 rounded-lg border border-border bg-card p-5"
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
          (Website > Testimonials). Nothing is shown when there is none. */}
      {testimonial && (
        <Section tone="surface" labelledBy="testimonial-title">
          <h2 id="testimonial-title" className="sr-only">
            What a client says
          </h2>
          <figure className="mx-auto max-w-3xl">
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

      {/* 8. Latest news: three posts, hidden when there are none. */}
      {latest.length > 0 && (
        <Section labelledBy="news-title">
          <div className="flex flex-wrap items-end justify-between gap-4">
            <SectionTitle id="news-title" title="News and advice" />
            <Link to="/news" className="font-semibold text-primary underline underline-offset-4">
              All news
            </Link>
          </div>
          <ul className="mt-8 grid gap-6 md:grid-cols-3">
            {latest.map((p) => (
              <li key={p.slug} className="flex flex-col rounded-lg border border-border bg-card p-6">
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
              </li>
            ))}
          </ul>
        </Section>
      )}
    </>
  )
}
