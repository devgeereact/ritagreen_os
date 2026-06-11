import { Link } from "react-router-dom"
import { ArrowRight } from "lucide-react"
import { PageHeader, Section, CtaBand } from "@/components/public/section"
import { DIVISIONS } from "@/lib/divisions"
import { COMPANY, PILLARS, HOW_WE_WORK, ASSURANCE } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"

export default function About() {
  useSeo({
    title: "About RitaGreen — one trusted name, four divisions",
    description:
      "RitaGreen Services is a London company that looks after people through every part of their day — care, training, cleaning and security under one name, one standard and one promise.",
    path: "/about",
  })

  return (
    <>
      <PageHeader
        eyebrow="About RitaGreen"
        title="Looking after people through every part of their day"
        intro={`${COMPANY.legalName} grew into four businesses that share one purpose: bringing trained, checked and trusted staff to the people, buildings and events that need them — to one standard, under one name.`}
      />

      {/* Story + mission/vision */}
      <Section>
        <div className="grid gap-12 md:grid-cols-3">
          <div className="space-y-4 text-muted-foreground md:col-span-2">
            <h2 className="text-2xl font-bold tracking-tight text-foreground">Our story</h2>
            <p>
              We started in care, and we still run care every day. Over time we saw the same need
              across our clients: not just carers, but the training that makes good carers, the
              cleaning that keeps care settings safe, and the security that protects the places people
              live and work.
            </p>
            <p>
              So we built one company that does all four. A care home shouldn't need one agency for
              carers, another for training, a third for cleaning and a fourth for security — four
              contracts, four invoices, four different levels of trust. With {COMPANY.shortName}, they
              call one name and know every worker is qualified, checked and ready.
            </p>
            <p>
              We work with care homes, the NHS, landlords, managing agents and event organisers across
              {" "}London. We're a local business: we answer the phone, we cover short notice, and we
              stand behind the people we send.
            </p>
          </div>
          <div className="space-y-6">
            <div className="rounded-xl border border-border bg-card p-5">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-primary">Our promise</h2>
              <p className="mt-2 text-sm text-muted-foreground">{COMPANY.promise}</p>
            </div>
            <div className="rounded-xl border border-border bg-card p-5">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-primary">Our vision</h2>
              <p className="mt-2 text-sm text-muted-foreground">
                A London where a care home, a landlord or an event organiser can call one trusted name
                for staff, training, cleaning or security, and know every worker is qualified, checked
                and ready.
              </p>
            </div>
          </div>
        </div>
      </Section>

      {/* Brand pillars */}
      <section className="border-y border-border bg-secondary/40">
        <div className="mx-auto max-w-6xl px-4 py-14 md:py-20">
          <p className="text-xs font-semibold uppercase tracking-[0.14em] text-primary">
            What we stand on
          </p>
          <h2 className="mt-3 text-2xl font-bold tracking-tight md:text-3xl">Our four pillars</h2>
          <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {PILLARS.map((p) => (
              <div key={p.key} className="rounded-xl border border-border bg-card p-6">
                <h3 className="font-semibold text-primary">{p.title}</h3>
                <p className="mt-2 text-sm text-muted-foreground">{p.body}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* The four divisions */}
      <Section>
        <h2 className="text-2xl font-bold tracking-tight">The four divisions</h2>
        <p className="mt-3 max-w-2xl text-muted-foreground">
          Each division uses the same name, colours and standard. The strength is that they all carry
          the {COMPANY.shortName} name — sell trust once, use it four ways.
        </p>
        <div className="mt-8 grid gap-4 sm:grid-cols-2">
          {DIVISIONS.map((d) => (
            <Link
              key={d.id}
              to={`/services#${d.id}`}
              className="group flex flex-col rounded-xl border border-border bg-card p-6 transition-shadow hover:shadow-md"
            >
              <div className="flex items-center gap-2">
                <span className="size-3 rounded-full" style={{ backgroundColor: d.accent }} />
                <h3 className="font-semibold">{d.name}</h3>
              </div>
              <p className="mt-2 flex-1 text-sm text-muted-foreground">{d.blurb}</p>
              <span className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary">
                Explore {d.descriptor}{" "}
                <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
              </span>
            </Link>
          ))}
        </div>
      </Section>

      {/* How we work */}
      <section className="border-y border-border bg-secondary/40">
        <div className="mx-auto max-w-6xl px-4 py-14 md:py-20">
          <h2 className="text-2xl font-bold tracking-tight md:text-3xl">How we work</h2>
          <p className="mt-3 max-w-2xl text-muted-foreground">
            From the first call to steady cover, the process is simple and accountable.
          </p>
          <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {HOW_WE_WORK.map((s) => (
              <div key={s.step} className="rounded-xl border border-border bg-card p-6">
                <span className="font-mono text-sm font-semibold text-primary">{s.step}</span>
                <h3 className="mt-3 font-semibold">{s.title}</h3>
                <p className="mt-2 text-sm text-muted-foreground">{s.body}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Standards & assurance */}
      <Section>
        <h2 className="text-2xl font-bold tracking-tight">Standards &amp; assurance</h2>
        <p className="mt-3 max-w-2xl text-muted-foreground">
          The brand promise is only as strong as the systems behind it. We keep these current and
          audit-ready at all times.
        </p>
        <div className="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
          {ASSURANCE.map((a) => (
            <div key={a.title} className="rounded-xl border border-border bg-card p-6">
              <h3 className="font-semibold text-foreground">{a.title}</h3>
              <p className="mt-2 text-sm text-muted-foreground">{a.body}</p>
            </div>
          ))}
        </div>
        <div className="mt-8">
          <Link
            to="/accreditations"
            className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
          >
            More on our accreditations &amp; compliance <ArrowRight className="size-3.5" />
          </Link>
        </div>
      </Section>

      {/* The one idea */}
      <Section className="!pt-0">
        <div className="rounded-2xl border border-border bg-secondary/50 p-8 md:p-12">
          <p className="text-xs font-semibold uppercase tracking-[0.14em] text-primary">
            The one thing to remember
          </p>
          <p className="mt-3 max-w-3xl font-serif text-xl font-medium leading-relaxed text-foreground md:text-2xl">
            {COMPANY.shortName} isn't four small businesses competing on price. It's one trusted
            relationship, sold once and used four ways.
          </p>
        </div>
      </Section>

      <CtaBand
        title="Work with RitaGreen"
        body="Whether you need staff, training, cleaning or security, start with one conversation."
      />
    </>
  )
}
