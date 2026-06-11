import { Link } from "react-router-dom"
import {
  ArrowRight,
  BadgeCheck,
  ShieldCheck,
  Clock,
  Phone,
  Check,
  Quote,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Section, CtaBand } from "@/components/public/section"
import { Faqs } from "@/components/public/Faqs"
import { DIVISIONS } from "@/lib/divisions"
import { COMPANY, WHY, HOW_WE_WORK, STATS, FAQS, ASSURANCE } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"

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

  return (
    <>
      {/* Hero */}
      <section className="relative overflow-hidden border-b border-border bg-secondary/30">
        <div className="mx-auto grid max-w-6xl gap-10 px-4 py-16 md:grid-cols-2 md:py-24">
          <div className="flex flex-col justify-center">
            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-primary">
              {COMPANY.legalName} · {COMPANY.area}
            </p>
            <h1 className="mt-3 text-4xl font-bold leading-tight tracking-tight md:text-5xl">
              People you can count on, <span className="text-primary">every part of the day</span>.
            </h1>
            <p className="mt-5 max-w-lg text-lg text-muted-foreground">
              {COMPANY.shortName} is one trusted London name for care staff, training, cleaning and
              security. Every worker we send is qualified, checked and ready — so you call one number
              and the whole day is covered.
            </p>
            <div className="mt-7 flex flex-wrap gap-3">
              <Button asChild size="lg">
                <Link to="/contact">
                  Get cover today <ArrowRight className="ml-1 size-4" />
                </Link>
              </Button>
              <Button asChild size="lg" variant="outline">
                <Link to="/services">Our services</Link>
              </Button>
            </div>
            <div className="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
              <span className="flex items-center gap-2"><BadgeCheck className="size-4 text-primary" /> DBS-checked staff</span>
              <span className="flex items-center gap-2"><ShieldCheck className="size-4 text-primary" /> SIA-licensed guards</span>
              <span className="flex items-center gap-2"><Clock className="size-4 text-primary" /> Same-day cover</span>
            </div>
          </div>

          <div className="relative hidden md:block">
            <div className="grid h-full grid-cols-2 gap-4">
              {DIVISIONS.map((d) => (
                <Link
                  key={d.id}
                  to={`/services#${d.id}`}
                  className="group flex flex-col justify-between rounded-2xl border border-border bg-card p-5 shadow-sm transition-shadow hover:shadow-md"
                >
                  <span
                    className="grid size-10 place-items-center rounded-lg text-sm font-bold text-white"
                    style={{ backgroundColor: d.accent }}
                  >
                    {d.descriptor.charAt(0)}
                  </span>
                  <div>
                    <p className="mt-4 text-sm font-semibold">{d.name}</p>
                    <p className="mt-1 text-xs text-muted-foreground">{d.tagline}</p>
                  </div>
                </Link>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* The 6am problem */}
      <Section className="!py-12">
        <div className="rounded-2xl border border-border bg-card p-6 md:p-10">
          <div className="grid items-center gap-8 md:grid-cols-2">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.14em] text-primary">
                The problem we solve
              </p>
              <h2 className="mt-3 text-2xl font-bold tracking-tight md:text-3xl">
                Most homes juggle four or five suppliers
              </h2>
              <p className="mt-3 text-muted-foreground">
                One agency for carers, another for training, a cleaning contractor, a security firm.
                Different invoices, different standards, different levels of trust. {COMPANY.shortName}{" "}
                exists to be the one name you can call instead.
              </p>
            </div>
            <div className="rounded-xl bg-secondary/50 p-6">
              <p className="font-serif text-lg italic leading-relaxed text-foreground">
                "If you've ever had a carer call in sick at 6am, you know the scramble that follows.
                RitaGreen covers it — a safe pair of hands the same day, not a stranger."
              </p>
              <Button asChild variant="link" className="mt-2 h-auto p-0 text-primary">
                <Link to="/contact">
                  Short-staffed this week? Talk to us <ArrowRight className="ml-1 size-4" />
                </Link>
              </Button>
            </div>
          </div>
        </div>
      </Section>

      {/* Four divisions */}
      <Section className="!pt-4">
        <div className="max-w-2xl">
          <p className="text-xs font-semibold uppercase tracking-[0.14em] text-primary">
            Four divisions, one standard
          </p>
          <h2 className="mt-3 text-2xl font-bold tracking-tight md:text-3xl">
            One purpose, four divisions
          </h2>
          <p className="mt-3 text-muted-foreground">
            {COMPANY.shortName} looks after people through every part of their day. Each division
            stands on its own, and together they let one client call a single trusted name for staff,
            training, cleaning or security.
          </p>
        </div>
        <div className="mt-10 grid gap-5 md:grid-cols-2">
          {DIVISIONS.map((d) => (
            <div key={d.id} className="flex flex-col rounded-xl border border-border bg-card p-6">
              <div className="flex items-center gap-2">
                <span className="size-3 rounded-full" style={{ backgroundColor: d.accent }} />
                <h3 className="font-semibold">{d.name}</h3>
              </div>
              <p className="mt-2 flex-1 text-sm text-muted-foreground">{d.blurb}</p>
              <Link
                to={`/services#${d.id}`}
                className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
              >
                Learn more <ArrowRight className="size-3.5" />
              </Link>
            </div>
          ))}
        </div>
      </Section>

      {/* Why RitaGreen */}
      <section className="border-y border-border bg-secondary/40">
        <div className="mx-auto max-w-6xl px-4 py-14 md:py-20">
          <h2 className="text-2xl font-bold tracking-tight md:text-3xl">Why RitaGreen</h2>
          <div className="mt-8 grid gap-5 md:grid-cols-3">
            {WHY.map((w) => (
              <div key={w.title} className="rounded-xl border border-border bg-card p-6">
                <Check className="size-6 text-primary" />
                <h3 className="mt-3 font-semibold">{w.title}</h3>
                <p className="mt-2 text-sm text-muted-foreground">{w.body}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Stats */}
      <section className="border-b border-border">
        <div className="mx-auto grid max-w-6xl gap-6 px-4 py-12 sm:grid-cols-2 lg:grid-cols-4">
          {STATS.map((s) => (
            <div key={s.l} className="text-center">
              <div className="text-3xl font-bold text-primary md:text-4xl">{s.n}</div>
              <div className="mt-1 text-sm text-muted-foreground">{s.l}</div>
            </div>
          ))}
        </div>
      </section>

      {/* How we work */}
      <Section>
        <div className="max-w-2xl">
          <p className="text-xs font-semibold uppercase tracking-[0.14em] text-primary">How we work</p>
          <h2 className="mt-3 text-2xl font-bold tracking-tight md:text-3xl">
            One enquiry, then a team that stays with you
          </h2>
          <p className="mt-3 text-muted-foreground">
            Every engagement starts with a short conversation and ends with steady, reliable cover and
            a named contact who knows your account.
          </p>
        </div>
        <div className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {HOW_WE_WORK.map((s) => (
            <div key={s.step} className="rounded-xl border border-border bg-card p-6">
              <span className="font-mono text-sm font-semibold text-primary">{s.step}</span>
              <h3 className="mt-3 font-semibold">{s.title}</h3>
              <p className="mt-2 text-sm text-muted-foreground">{s.body}</p>
            </div>
          ))}
        </div>
      </Section>

      {/* Assurance strip */}
      <section className="border-y border-border bg-secondary/40">
        <div className="mx-auto max-w-6xl px-4 py-14 md:py-20">
          <div className="flex flex-col items-start justify-between gap-4 md:flex-row md:items-end">
            <div className="max-w-2xl">
              <p className="text-xs font-semibold uppercase tracking-[0.14em] text-primary">
                The standards behind every shift
              </p>
              <h2 className="mt-3 text-2xl font-bold tracking-tight md:text-3xl">
                Compliance, checked before staff are dispatched
              </h2>
            </div>
            <Button asChild variant="outline">
              <Link to="/accreditations">See our accreditations</Link>
            </Button>
          </div>
          <div className="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            {ASSURANCE.map((a) => (
              <div key={a.title} className="rounded-xl border border-border bg-card p-6">
                <h3 className="font-semibold text-foreground">{a.title}</h3>
                <p className="mt-2 text-sm text-muted-foreground">{a.body}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Testimonial / voice */}
      <Section>
        <figure className="mx-auto max-w-3xl text-center">
          <Quote className="mx-auto size-8 text-primary" />
          <blockquote className="mt-4 font-serif text-2xl font-medium leading-relaxed text-foreground md:text-3xl">
            Four jobs, one name. Care at dawn, a spotless building by noon, a calm guard overnight —
            all from people we've checked and trained ourselves.
          </blockquote>
          <figcaption className="mt-4 text-sm text-muted-foreground">
            — {COMPANY.legalName}
          </figcaption>
        </figure>
      </Section>

      {/* FAQ */}
      <section className="border-t border-border bg-secondary/30">
        <div className="mx-auto max-w-3xl px-4 py-14 md:py-20">
          <h2 className="text-2xl font-bold tracking-tight md:text-3xl">Common questions</h2>
          <p className="mt-3 text-muted-foreground">
            The things care managers, landlords and event organisers ask us most.
          </p>
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
        </div>
      </section>

      <CtaBand />
    </>
  )
}
