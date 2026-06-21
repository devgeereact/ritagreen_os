import { Link } from "react-router-dom"
import { ArrowRight, BadgeCheck } from "lucide-react"
import { PageHeader, Section, SectionAlt, CtaBand } from "@/components/public/section"
import { SectionHeading, CounterBand, FeatureSplit } from "@/components/public/marketing"
import { COMPANY, PILLARS, ASSURANCE, STATS } from "@/lib/company"
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

      {/* Story split */}
      <Section>
        <FeatureSplit
          eyebrow="Our story"
          title="One company that does all four"
          body={`We started in care, and we still run care every day. Over time we saw the same need across our clients — not just carers, but the training that makes good carers, the cleaning that keeps care settings safe, and the security that protects the places people live and work. So we built one company that does all four.`}
          points={[
            "Care homes, the NHS & supported living",
            "Landlords & managing agents",
            "Offices & commercial premises",
            "Events & venues across London",
          ]}
          cta={{ to: "/sectors", label: "Sectors we serve" }}
          sticker={{ value: "1 name", label: "Four divisions" }}
          image={{ src: "/images/divisions/company.jpg", alt: "The RitaGreen team in a planning meeting" }}
        />
        <div className="mt-12 grid gap-5 md:grid-cols-2">
          <div className="surface border-l-2 border-primary bg-soft-mesh p-6">
            <h2 className="eyebrow">Our promise</h2>
            <p className="mt-3 text-sm leading-relaxed text-foreground">{COMPANY.promise}</p>
          </div>
          <div className="surface border-l-2 border-primary p-6">
            <h2 className="eyebrow">Our vision</h2>
            <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
              A London where a care home, a landlord or an event organiser can call one trusted name
              for staff, training, cleaning or security, and know every worker is qualified, checked
              and ready.
            </p>
          </div>
        </div>
      </Section>

      {/* Brand pillars */}
      <SectionAlt>
        <SectionHeading eyebrow="What we stand on" title="Our four pillars" />
        <div className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {PILLARS.map((p, i) => (
            <div key={p.key} className="surface p-7">
              <span className="grid size-10 place-items-center rounded-xl bg-primary/10 font-mono text-sm font-bold text-primary">
                {String(i + 1).padStart(2, "0")}
              </span>
              <h3 className="mt-4 font-semibold text-foreground">{p.title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{p.body}</p>
            </div>
          ))}
        </div>
      </SectionAlt>

      {/* Counter band */}
      <CounterBand stats={STATS} />

      {/* Standards & assurance */}
      <SectionAlt>
        <SectionHeading
          title="Standards & assurance"
          intro="The brand promise is only as strong as the systems behind it. We keep these current and audit-ready at all times."
        />
        <div className="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
          {ASSURANCE.map((a) => (
            <div key={a.title} className="surface flex gap-4 p-6">
              <BadgeCheck className="mt-0.5 size-5 shrink-0 text-primary" />
              <div>
                <h3 className="font-semibold text-foreground">{a.title}</h3>
                <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">{a.body}</p>
              </div>
            </div>
          ))}
        </div>
        <div className="mt-8">
          <Link
            to="/accreditations"
            className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
          >
            More on our accreditations & compliance <ArrowRight className="size-3.5" />
          </Link>
        </div>
      </SectionAlt>

      {/* The one idea */}
      <Section>
        <div className="surface border-l-2 border-primary bg-soft-mesh p-8 shadow-elevate md:p-12">
          <p className="eyebrow">The one thing to remember</p>
          <p className="mt-4 max-w-3xl font-serif text-xl font-medium leading-relaxed text-foreground md:text-2xl">
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
