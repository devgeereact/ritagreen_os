import { Link } from "react-router-dom"
import { ArrowRight } from "lucide-react"
import { CheckList, Container, PageHeader, Section, SectionTitle, Steps } from "@/components/public/section"
import { siteButton } from "@/components/public/site-button"
import { Faqs } from "@/components/public/Faqs"
import { EnquiryForm } from "@/components/public/forms/EnquiryForm"
import { TrainingForms } from "@/components/public/TrainingForms"
import { SERVICE_PAGES } from "@/lib/service-pages"
import { useCompanyDetails } from "@/lib/company"
import { serviceLd } from "@/lib/structured-data"
import { useSeo } from "@/hooks/use-seo"

/**
 * One service page. Every service uses the same order so a visitor learns it
 * once: opening, what we provide, how it works, checks, who we work with,
 * questions, enquiry (2026 plan, file 05). The words are in src/lib/service-pages.ts.
 */
export default function ServicePage({ pageKey }: { pageKey: keyof typeof SERVICE_PAGES }) {
  const page = SERVICE_PAGES[pageKey]
  const company = useCompanyDetails()

  useSeo({
    title: page.seoTitle,
    description: page.seoDescription,
    path: page.path,
    jsonLd: serviceLd(company, page),
  })

  return (
    <>
      <PageHeader
        eyebrow={page.eyebrow}
        title={page.title}
        breadcrumb={page.breadcrumb}
        trail={page.parent ? [page.parent] : undefined}
        intro={<p>{page.opening}</p>}
      >
        <a href="#enquiry" className={siteButton()}>
          {page.enquiry.title}
        </a>
        <a href={company.phone.href} className={siteButton({ variant: "secondary" })}>
          Call {company.phone.display}
        </a>
      </PageHeader>

      {page.routes && (
        <Container className="pb-12">
          <ul className="grid gap-4 md:grid-cols-2">
            {page.routes.map((r) => (
              <li key={r.to}>
                <Link
                  to={r.to}
                  className="group flex h-full flex-col gap-2 rounded-lg border border-border bg-card p-6 transition-colors hover:border-primary"
                >
                  <span className="text-xl font-semibold text-foreground">{r.title}</span>
                  <span className="text-muted-foreground">{r.body}</span>
                  <span className="mt-auto inline-flex items-center gap-1 pt-2 font-semibold text-primary">
                    Find out more <ArrowRight aria-hidden="true" className="size-4" />
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </Container>
      )}

      <Section tone="surface" labelledBy="provide-title">
        <div className="grid items-start gap-10 md:grid-cols-[1.1fr_0.9fr]">
          <div>
            <SectionTitle id="provide-title" title={page.provideTitle} />
            <CheckList items={page.provide} className="mt-6" />
          </div>
          {/* Placeholder photograph: see src/lib/site-images.ts. Not RitaGreen staff or clients. */}
          <img
            src={page.image.src}
            alt={page.image.alt}
            loading="lazy"
            width={1200}
            height={900}
            className="aspect-[4/3] w-full rounded-lg border border-border object-cover"
          />
        </div>
      </Section>

      <Section labelledBy="how-title">
        <SectionTitle id="how-title" title="How it works" />
        <Steps items={page.steps} />
      </Section>

      <Section tone="surface" labelledBy="checks-title">
        <div className="grid gap-10 md:grid-cols-2">
          <div>
            <SectionTitle id="checks-title" title={page.checksTitle} intro={page.checksIntro} />
            <CheckList items={page.checks} className="mt-6" />
          </div>
          <div>
            <h2 id="sectors-title" className="text-[1.625rem] leading-tight md:text-[2.125rem]">
              Who we work with
            </h2>
            <ul aria-labelledby="sectors-title" className="mt-6 divide-y divide-border rounded-lg border border-border bg-background">
              {page.sectors.map((s) => (
                <li key={s} className="px-5 py-3">
                  {s}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </Section>

      {page.service === "academy" && (
        <Section tone="tint" labelledBy="verify-title" id="verify">
          <div className="flex flex-col items-start justify-between gap-5 md:flex-row md:items-center">
            <SectionTitle
              id="verify-title"
              title="Check a certificate"
              intro="Employers can confirm a certificate we issued is genuine, using the ID printed on it."
            />
            <Link to="/verify" className={siteButton({ variant: "secondary" })}>
              Check a certificate
            </Link>
          </div>
        </Section>
      )}

      {page.service === "academy" && <TrainingForms company={company} />}

      <Section labelledBy="faq-title">
        <div className="grid gap-10 md:grid-cols-[0.8fr_1.2fr]">
          <SectionTitle id="faq-title" title="Questions" intro={`Anything else, call us on ${company.phone.display}.`} />
          <Faqs items={page.faqs} />
        </div>
      </Section>

      <Section tone="surface" id="enquiry" labelledBy="enquiry-title">
        <div className="grid gap-10 md:grid-cols-[0.8fr_1.2fr]">
          <div>
            <SectionTitle id="enquiry-title" title={page.enquiry.title} intro={page.enquiry.intro} />
            <div className="mt-6 space-y-1 text-muted-foreground">
              <p>
                Prefer to talk?{" "}
                <a href={company.phone.href} className="font-semibold text-foreground underline underline-offset-4">
                  {company.phone.display}
                </a>
              </p>
              <p>{company.officeHours}</p>
            </div>
          </div>
          <div className="rounded-lg border border-border bg-background p-5 sm:p-8">
            <EnquiryForm kind={page.enquiry.kind} service={page.service} />
          </div>
        </div>
      </Section>
    </>
  )
}
