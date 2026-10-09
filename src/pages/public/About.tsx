import { Link } from "react-router-dom"
import { ArrowRight } from "lucide-react"
import { CheckList, CtaBand, PageHeader, Section, SectionTitle, ServiceTag } from "@/components/public/section"
import { useCompanyDetails } from "@/lib/company"
import { SITE_IMAGES } from "@/lib/site-images"
import { useSeo } from "@/hooks/use-seo"

// /about: who we are, how we check and train staff, and the standards we hold
// ourselves to. /sectors and /accreditations redirect here; their content is
// folded in as "Who we work with" and "Standards", keeping only what can be
// shown to be true (2026 plan, file 01). Insurance figures, accreditation
// schemes and regulator alignment statements are not repeated until confirmed.

const WHO = [
  { service: "care", to: "/healthcare", body: "Care homes, nursing homes, hospitals, day centres and supported living services." },
  { service: "cleaning", to: "/cleaning", body: "Landlords and letting agents, schools, construction sites, offices and care settings." },
  { service: "security", to: "/security", body: "Companies, construction and void sites, events and residential blocks." },
  { service: "academy", to: "/training", body: "Care providers and their teams, and people starting a career in care." },
] as const

const CHECKS = [
  "Right to work in the UK, checked and recorded for everyone",
  "Enhanced DBS at the level each role requires",
  "References from previous employers",
  "Role-specific evidence: the Care Certificate and mandatory training for care, NMC registration for nurses, an SIA licence of the right type for security, COSHH awareness for cleaning",
  "Expiry dates tracked, so a check that lapses is caught before the next booking",
]

const STANDARDS = [
  "Compliance is worked out from the evidence on file. Nobody can mark a worker as compliant by hand.",
  "Our system refuses to book someone whose mandatory checks are missing or out of date, and records why.",
  "Every worker is approved by a named person in the office before their first shift.",
  "Personal information is handled under UK GDPR and the Data Protection Act 2018. You can ask what we hold about you at any time.",
]

export default function About() {
  const company = useCompanyDetails()
  useSeo({
    title: "About us",
    description:
      "RitaGreen Services is a London company supplying healthcare staff, cleaning, security and training, with every worker's checks tracked before they are booked.",
    path: "/about",
  })

  return (
    <>
      <PageHeader
        eyebrow="About RitaGreen"
        title="One office for healthcare staff, cleaning, security and training"
        intro={
          <p>
            {company.legalName} supplies checked, trained people to care providers, property managers, sites and
            events across London. Healthcare staffing is most of what we do, and the same office runs cleaning,
            security and training.
          </p>
        }
      />

      <Section tone="surface" labelledBy="who-title">
        <div className="grid items-start gap-10 md:grid-cols-[1.1fr_0.9fr]">
          <div>
            <SectionTitle id="who-title" title="Who we work with" />
            <ul className="mt-6 grid gap-5">
              {WHO.map((w) => (
                <li key={w.to} className="border-t border-border pt-4">
                  <Link to={w.to} className="inline-flex items-center gap-2 underline-offset-4 hover:underline">
                    <ServiceTag service={w.service} className="text-lg" />
                    <ArrowRight aria-hidden="true" className="size-4 text-primary" />
                  </Link>
                  <p className="mt-1 text-muted-foreground">{w.body}</p>
                </li>
              ))}
            </ul>
          </div>
          {/* Placeholder photograph: see src/lib/site-images.ts. Not RitaGreen staff. */}
          <img
            src={SITE_IMAGES.company.src}
            alt={SITE_IMAGES.company.alt}
            loading="lazy"
            width={1200}
            height={900}
            className="aspect-[4/3] w-full rounded-lg border border-border object-cover"
          />
        </div>
      </Section>

      <Section labelledBy="checks-title">
        <div className="grid gap-10 md:grid-cols-2">
          <div>
            <SectionTitle
              id="checks-title"
              title="How we check and train staff"
              intro="Before anyone works a shift for us, our office system holds a current record of:"
            />
            <CheckList items={CHECKS} className="mt-6" />
          </div>
          <div>
            <h2 id="training-title" className="text-[1.625rem] leading-tight md:text-[2.125rem]">
              Our own training team
            </h2>
            <p className="mt-3 text-muted-foreground">
              We run Care Certificate, mandatory and refresher training, for our own staff and for care providers.
              Attendance and certificates are recorded, renewal dates are tracked, and anyone can check a certificate
              we issued online.
            </p>
            <p className="mt-4">
              <Link to="/training" className="font-semibold text-primary underline underline-offset-4">
                About our training
              </Link>
            </p>
          </div>
        </div>
      </Section>

      <Section tone="surface" labelledBy="standards-title">
        <SectionTitle id="standards-title" title="Standards" intro="The rules our own system holds us to:" />
        <CheckList items={STANDARDS} className="mt-6 max-w-[70ch]" />
        <p className="mt-6 text-muted-foreground">
          {company.legalName} is registered in England and Wales, company number {company.companyNo}.
        </p>
      </Section>

      <CtaBand title="Work with RitaGreen" body="Tell us what you need, or call the office to talk it through." />
    </>
  )
}
