import { Link } from "react-router-dom"
import {
  ArrowRight,
  BadgeCheck,
  ShieldCheck,
  Clock,
  Phone,
  Zap,
  Layers,
  GraduationCap,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Section, SectionAlt, CtaBand } from "@/components/public/section"
import { Faqs } from "@/components/public/Faqs"
import {
  SectionHeading,
  BannerCards,
  CounterBand,
  FeatureSplit,
  Testimonials,
} from "@/components/public/marketing"
import { DIVISIONS } from "@/lib/divisions"
import { divisionIcon, accentTint } from "@/lib/division-icons"
import { COMPANY, HOW_WE_WORK, STATS, FAQS } from "@/lib/company"
import { useBlogPosts } from "@/lib/resources"
import { useSeo } from "@/hooks/use-seo"

const TRUST = [
  "CQC-aligned care",
  "SIA-licensed officers",
  "Enhanced DBS checked",
  "COSHH-controlled cleaning",
  "£5m insurance",
  "UK GDPR compliant",
]

function fmt(date: string) {
  return new Date(date).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" })
}

export default function Home() {
  useSeo({
    title: "Care, training, cleaning & security staff in London",
    description:
      "RitaGreen is one trusted London name for care staff, training, cleaning and security. Every worker is DBS-checked, qualified and ready — call one number and the whole day is covered.",
    path: "/",
    jsonLd: {
      "@context": "https://schema.org",
      "@type": "Organization",
      name: COMPANY.legalName,
      url: "https://ritagreenservices.com",
      email: COMPANY.email,
      telephone: COMPANY.phone,
      areaServed: "London, United Kingdom",
      slogan: COMPANY.primaryTagline,
      description: COMPANY.promise,
      knowsAbout: [
        "Care staffing",
        "Care Certificate training",
        "Commercial cleaning",
        "SIA security",
      ],
    },
  })

  const { posts } = useBlogPosts()
  const latest = [...posts].sort((a, b) => (a.date < b.date ? 1 : -1)).slice(0, 3)

  return (
    <>
      {/* Hero */}
      <section className="bg-hero-pro relative overflow-hidden border-b border-border">
        {/* Crisp engineering grid, masked so it fades behind the copy. */}
        <div aria-hidden className="bg-hero-grid pointer-events-none absolute inset-0" />
        <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 md:grid-cols-2 md:py-28">
          <div className="flex flex-col justify-center">
            <p className="eyebrow animate-fade-up">
              <span className="inline-block size-1.5 rounded-full bg-primary" />
              {COMPANY.legalName} · {COMPANY.area}
            </p>
            <h1 className="mt-4 animate-fade-up animation-delay-100 font-display text-[2.4rem] font-bold leading-[1.06] tracking-tight md:text-[3.4rem]">
              People you can count on, <span className="text-primary">every part of the day</span>.
            </h1>
            <p className="mt-6 max-w-lg animate-fade-up animation-delay-200 text-lg leading-relaxed text-muted-foreground">
              {COMPANY.shortName} is one trusted London name for care staff, training, cleaning and
              security. Every worker we send is qualified, checked and ready — so you call one number
              and the whole day is covered.
            </p>
            <div className="mt-8 flex flex-wrap gap-3 animate-fade-up animation-delay-300">
              <Button asChild size="lg" className="shadow-lift">
                <Link to="/quote">
                  Get a quote <ArrowRight className="ml-1 size-4" />
                </Link>
              </Button>
              <Button asChild size="lg" variant="outline" className="border-border/80 bg-background/60 backdrop-blur">
                <Link to="/services">Our services</Link>
              </Button>
            </div>
            <div className="mt-10 flex flex-wrap gap-x-6 gap-y-2 text-sm font-medium text-muted-foreground animate-fade-in animation-delay-300">
              <span className="flex items-center gap-2"><BadgeCheck className="size-4 text-primary" /> DBS-checked staff</span>
              <span className="flex items-center gap-2"><ShieldCheck className="size-4 text-primary" /> SIA-licensed guards</span>
              <span className="flex items-center gap-2"><Clock className="size-4 text-primary" /> Same-day cover</span>
            </div>
          </div>

          <div className="relative hidden md:block">
            {/* Soft, slowly pulsing brand glow behind the cards. */}
            <div aria-hidden className="pointer-events-none absolute inset-0">
              <div className="animate-glow absolute -right-8 top-0 size-60 rounded-full bg-primary/25 blur-3xl" />
              <div
                className="animate-glow absolute -bottom-8 left-0 size-52 rounded-full bg-accent/30 blur-3xl"
                style={{ animationDelay: "2.5s" }}
              />
            </div>

            <div className="relative grid grid-cols-2 gap-4">
              {DIVISIONS.map((d, i) => {
                const Icon = divisionIcon(d.id)
                return (
                  <div
                    key={d.id}
                    style={{ animationDelay: `${0.2 + i * 0.1}s` }}
                    className="animate-fade-up [&:nth-child(even)]:mt-8"
                  >
                    <Link
                      to={`/services#${d.id}`}
                      style={{ animationDelay: `${i * 0.7}s`, animationDuration: `${6.5 + i * 0.6}s` }}
                      className="animate-float group relative flex h-full flex-col justify-between overflow-hidden rounded-2xl border border-border bg-card/80 p-6 shadow-soft backdrop-blur-sm transition-[box-shadow,border-color] duration-300 hover:border-primary/30 hover:shadow-lift"
                    >
                      {/* Accent wash fades in on hover, tinted to the division. */}
                      <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-300 group-hover:opacity-100"
                        style={{
                          background: `radial-gradient(120% 80% at 100% 0%, ${accentTint(d.accent, 0.16)}, transparent 70%)`,
                        }}
                      />
                      <span
                        className="relative grid size-12 place-items-center rounded-xl ring-1 ring-inset ring-black/5 transition-transform duration-300 group-hover:scale-110"
                        style={{ backgroundColor: accentTint(d.accent, 0.14), color: d.accent }}
                      >
                        <Icon className="size-6" strokeWidth={1.75} />
                      </span>
                      <div className="relative mt-6">
                        <p className="font-semibold text-foreground">{d.name}</p>
                        <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">{d.tagline}</p>
                        <span className="mt-3 inline-flex -translate-x-1 items-center gap-1 text-sm font-medium text-primary opacity-0 transition-all duration-300 group-hover:translate-x-0 group-hover:opacity-100">
                          Learn more <ArrowRight className="size-3.5" />
                        </span>
                      </div>
                    </Link>
                  </div>
                )
              })}
            </div>
          </div>
        </div>
      </section>

      {/* Banner cards — three promises directly under the hero */}
      <Section className="!py-12">
        <BannerCards
          items={[
            {
              title: "Same-day cover",
              body: "A carer calls in sick at 6am? We can often confirm a checked replacement the same day — not a stranger.",
              icon: <Zap className="size-5" />,
            },
            {
              title: "One trusted name",
              body: "Care, training, cleaning and security from one contact, one invoice and one standard — instead of four suppliers.",
              icon: <Layers className="size-5" />,
              to: "/about",
            },
            {
              title: "We train our own",
              body: "Our academy trains the carers we place, so we know exactly who we send and how they were taught.",
              icon: <GraduationCap className="size-5" />,
              to: "/careers",
            },
          ]}
        />
      </Section>

      {/* About split — the problem we solve, with an experience sticker */}
      <Section className="!pt-4">
        <FeatureSplit
          eyebrow="The problem we solve"
          title="Most homes juggle four or five suppliers"
          body={`One agency for carers, another for training, a cleaning contractor, a security firm — different invoices, different standards, different levels of trust. ${COMPANY.shortName} exists to be the one name you can call instead.`}
          points={[
            "One contact across the whole day",
            "One invoice, one standard",
            "DBS-checked, qualified people",
            "A named manager who stays with you",
          ]}
          cta={{ to: "/about", label: "How we work" }}
          sticker={{ value: "4-in-1", label: "Divisions, one name" }}
          image={{ src: "/images/divisions/care.jpg", alt: "A RitaGreen carer supporting two older people with an activity at home" }}
        />
      </Section>

      {/* Four divisions */}
      <Section>
        <SectionHeading
          eyebrow="Four divisions, one standard"
          title="One purpose, four divisions"
          intro={`${COMPANY.shortName} looks after people through every part of their day. Each division stands on its own, and together they let one client call a single trusted name for staff, training, cleaning or security.`}
        />
        <div className="mt-10 grid gap-5 md:grid-cols-2">
          {DIVISIONS.map((d) => {
            const Icon = divisionIcon(d.id)
            return (
              <Link
                key={d.id}
                to={`/services#${d.id}`}
                className="group surface-interactive flex flex-col p-7"
              >
                <div className="flex items-center gap-3">
                  <span
                    className="grid size-11 place-items-center rounded-xl ring-1 ring-inset ring-black/5"
                    style={{ backgroundColor: accentTint(d.accent, 0.14), color: d.accent }}
                  >
                    <Icon className="size-5" strokeWidth={1.75} />
                  </span>
                  <h3 className="text-lg font-semibold">{d.name}</h3>
                </div>
                <p className="mt-4 flex-1 text-sm leading-relaxed text-muted-foreground">{d.blurb}</p>
                <span className="mt-5 inline-flex items-center gap-1 text-sm font-medium text-primary">
                  Learn more <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-1" />
                </span>
              </Link>
            )
          })}
        </div>
      </Section>

      {/* Proof band — trust marks + animated stats, one combined section */}
      <CounterBand stats={STATS} trust={TRUST} />

      {/* Academy feature split (reversed) */}
      <Section>
        <FeatureSplit
          reverse
          eyebrow="RitaGreen Academy"
          title="We don't just hire carers — we make them"
          body="Our academy runs the Care Certificate and mandatory training, then gives strong learners a guaranteed interview with RitaGreen Care. Real training, a real job at the end."
          points={[
            "All 16 Care Certificate standards",
            "Moving & handling, first aid, safeguarding",
            "Guaranteed interview for strong learners",
            "Verifiable certificates employers can check",
          ]}
          cta={{ to: "/careers", label: "Train with us" }}
          sticker={{ value: "12 wk", label: "Care Certificate" }}
          image={{ src: "/images/divisions/training.jpg", alt: "Adult learners in a bright classroom during RitaGreen Academy training" }}
        />
      </Section>

      {/* How we work */}
      <SectionAlt>
        <SectionHeading
          eyebrow="How we work"
          title="One enquiry, then a team that stays with you"
          intro="Every engagement starts with a short conversation and ends with steady, reliable cover and a named contact who knows your account."
        />
        <div className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {HOW_WE_WORK.map((s) => (
            <div key={s.step} className="surface relative p-7">
              <span className="grid size-10 place-items-center rounded-full bg-primary text-sm font-bold text-primary-foreground shadow-soft">
                {s.step}
              </span>
              <h3 className="mt-4 font-semibold">{s.title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{s.body}</p>
            </div>
          ))}
        </div>
      </SectionAlt>

      {/* Client testimonials */}
      <Section>
        <SectionHeading
          align="center"
          eyebrow="What clients say"
          title="Trusted by the people who rely on us"
          className="mb-10"
        />
        <Testimonials />
      </Section>

      {/* Latest resources */}
      <SectionAlt>
        <div className="flex flex-col items-start justify-between gap-4 md:flex-row md:items-end">
          <SectionHeading
            eyebrow="From the blog"
            title="Guides & articles"
            intro="Practical reading for the people who hire us — care managers, landlords, agents and event organisers."
          />
          <Button asChild variant="outline" className="bg-background/60 backdrop-blur">
            <Link to="/resources">View all</Link>
          </Button>
        </div>
        <div className="mt-10 grid gap-6 md:grid-cols-3">
          {latest.map((a) => (
            <Link
              key={a.slug}
              to={`/resources/${a.slug}`}
              className="group surface-interactive flex flex-col overflow-hidden bg-card"
            >
              <div className="relative aspect-[16/10] overflow-hidden">
                <img
                  src={a.image}
                  alt=""
                  loading="lazy"
                  className="absolute inset-0 size-full object-cover transition-transform duration-500 group-hover:scale-105"
                />
                <Badge variant="secondary" className="absolute left-3 top-3 shadow-soft">
                  {a.category}
                </Badge>
              </div>
              <div className="flex flex-1 flex-col p-6">
                <div className="flex items-center gap-3 text-xs text-muted-foreground">
                  <span>{fmt(a.date)}</span>
                  <span className="flex items-center gap-1">
                    <Clock className="size-3" /> {a.readMins} min
                  </span>
                </div>
                <h3 className="mt-3 font-semibold">{a.title}</h3>
                <p className="mt-2 flex-1 text-sm leading-relaxed text-muted-foreground">{a.excerpt}</p>
                <span className="mt-5 inline-flex items-center gap-1 text-sm font-medium text-primary">
                  Read <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-1" />
                </span>
              </div>
            </Link>
          ))}
        </div>
      </SectionAlt>

      {/* FAQ */}
      <Section>
        <SectionHeading
          eyebrow="FAQ"
          title="Common questions"
          intro="The things care managers, landlords and event organisers ask us most."
        />
        <Faqs items={FAQS} className="mt-8" />
        <div className="mt-8 flex flex-wrap items-center gap-3">
          <Button asChild>
            <Link to="/contact">Ask us anything</Link>
          </Button>
          <a
            href={COMPANY.phoneHref}
            className="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"
          >
            <Phone className="size-4" /> {COMPANY.phone}
          </a>
        </div>
      </Section>

      <CtaBand />
    </>
  )
}
