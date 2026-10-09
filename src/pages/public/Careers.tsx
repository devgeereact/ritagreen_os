import { useMemo, useState } from "react"
import { CheckList, PageHeader, Section, SectionTitle, Steps } from "@/components/public/section"
import { Faqs } from "@/components/public/Faqs"
import { siteButton } from "@/components/public/site-button"
import { ApplicationForm } from "@/components/public/ApplicationForm"
import { VacancyCard } from "@/components/public/VacancyCard"
import { useApi } from "@/hooks/use-api"
import { useCompanyDetails } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"
import { SERVICE_IDS, SERVICE_LABELS, type ServiceId } from "@/lib/services"
import { cn } from "@/lib/utils"
import type { PublicVacancy } from "@/lib/types"

// /careers: the roles the office has published in the Website area (section
// S7), filterable by service, each linking to its role page. Below them, a
// general "register your interest" form for somebody whose role is not listed;
// it posts to ats.php, the applicant tracker, as the careers page always has.

const STEPS = [
  { title: "Apply", body: "Choose a role and apply. It takes a few minutes on a phone." },
  { title: "We talk", body: "Someone from the office calls you about the role and what happens next." },
  { title: "Checks, then shifts", body: "We complete your checks with you. Once they are done and you are approved, you can be booked." },
]

const WE_CHECK = [
  "Your right to work in the UK",
  "An enhanced DBS, where the role needs one",
  "References from previous employers",
  "Your qualifications: the Care Certificate, an NMC registration, an SIA licence or COSHH training, depending on the role",
]

const FAQS = [
  {
    q: "Do I need experience?",
    a: "For most healthcare, cleaning and security roles, yes, or the right qualification. If you are new to care, register your interest and choose the training option, and we will talk you through how to start.",
  },
  {
    q: "What happens after I apply?",
    a: "Someone from the office will contact you about the role. If it is a match, we arrange your checks together. You can be booked once they are complete and you have been approved.",
  },
  {
    q: "What documents will I need?",
    a: "None to apply. Later we will need proof of your right to work, your qualifications and licences for the role, and details of people who can give a reference. We will tell you exactly what we need when we call.",
  },
  {
    q: "Is there a fee to apply or to work with you?",
    a: "No. We never charge you to apply.",
  },
]

type Filter = "all" | ServiceId

export default function Careers() {
  const company = useCompanyDetails()
  useSeo({
    title: "Careers: healthcare, cleaning and security jobs in London",
    description:
      "Current roles at RitaGreen Services: care assistants, support workers, nurses, cleaners and security officers across London. Apply in a few minutes.",
    path: "/careers",
  })

  const roles = useApi<{ vacancies: PublicVacancy[] }>("vacancies.php")
  const [filter, setFilter] = useState<Filter>("all")
  const all = useMemo(() => roles.data?.vacancies ?? [], [roles.data])
  const servicesShown = SERVICE_IDS.filter((s) => all.some((v) => v.service === s))
  const shown = filter === "all" ? all : all.filter((v) => v.service === filter)

  return (
    <>
      <PageHeader
        eyebrow="Careers"
        title="Join a team that looks after its people"
        intro={
          <p>
            We are looking for care assistants, support workers, nurses, cleaners and security officers across London.
            Choose a role below, or tell us what you do and the office will be in touch.
          </p>
        }
      >
        <a href="#roles" className={siteButton()}>
          See current roles
        </a>
        <a href="#interest" className={siteButton({ variant: "secondary" })}>
          Register your interest
        </a>
      </PageHeader>

      <Section tone="surface" id="roles" labelledBy="roles-title">
        <SectionTitle id="roles-title" title="Current roles" />

        {servicesShown.length > 1 && (
          <div role="group" aria-label="Show roles in" className="mt-6 flex flex-wrap gap-2">
            {(["all", ...servicesShown] as Filter[]).map((f) => (
              <button
                key={f}
                type="button"
                aria-pressed={filter === f}
                onClick={() => setFilter(f)}
                className={cn(
                  "min-h-11 rounded-full border px-4 text-[15px] font-semibold transition-colors",
                  filter === f ? "border-primary bg-primary text-primary-foreground" : "border-border bg-background hover:border-primary",
                )}
              >
                {f === "all" ? "All roles" : SERVICE_LABELS[f]}
              </button>
            ))}
          </div>
        )}

        <div className="mt-8" aria-live="polite">
          {roles.loading && <p className="text-muted-foreground">Loading the current roles.</p>}
          {roles.error && (
            <p className="max-w-[60ch]">
              We could not load the current roles just now. Call the office on{" "}
              <a href={company.phone.href} className="font-semibold underline underline-offset-4">
                {company.phone.display}
              </a>{" "}
              or register your interest below.
            </p>
          )}
          {roles.data && all.length === 0 && (
            <div className="max-w-[62ch] rounded-lg border border-border bg-background p-5 sm:p-8">
              <h3 className="text-lg font-semibold">No roles are listed right now</h3>
              <p className="mt-2 text-muted-foreground">
                We still want to hear from carers, support workers, nurses, cleaners and security officers. Register your
                interest below, or call the office on{" "}
                <a href={company.phone.href} className="font-semibold text-foreground underline underline-offset-4">
                  {company.phone.display}
                </a>
                .
              </p>
              <a href="#interest" className={`${siteButton({ variant: "secondary" })} mt-5`}>
                Register your interest
              </a>
            </div>
          )}
          {shown.length > 0 && (
            <ul className="grid gap-4 md:grid-cols-2">
              {shown.map((v) => (
                <li key={v.slug}>
                  <VacancyCard vacancy={v} />
                </li>
              ))}
            </ul>
          )}
        </div>
      </Section>

      <Section labelledBy="how-title">
        <SectionTitle id="how-title" title="How it works" />
        <Steps items={STEPS} />
      </Section>

      <Section tone="surface" labelledBy="check-title">
        <div className="grid gap-10 md:grid-cols-2">
          <div>
            <SectionTitle
              id="check-title"
              title="What we check"
              intro="The same checks for everyone, so the people you work beside have been checked too:"
            />
            <CheckList items={WE_CHECK} className="mt-6" />
          </div>
          <div>
            <h2 id="faq-title" className="text-[1.625rem] leading-tight md:text-[2.125rem]">
              Questions
            </h2>
            <Faqs items={FAQS} className="mt-6" />
          </div>
        </div>
      </Section>

      <Section id="interest" labelledBy="interest-title">
        <div className="grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
          <SectionTitle
            id="interest-title"
            title="Not seeing your role? Register your interest"
            intro={`Tell us the work you do and we will contact you when something fits. Questions first? Call the office on ${company.phone.display} (${company.officeHours}).`}
          />
          <div className="rounded-lg border border-border bg-card p-5 sm:p-8">
            <ApplicationForm submitLabel="Send my details" />
          </div>
        </div>
      </Section>
    </>
  )
}
