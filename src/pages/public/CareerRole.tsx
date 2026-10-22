import { useEffect, useState } from "react"
import { Link, useParams } from "react-router-dom"
import { CheckList, Container, PageHeader, Section, SectionTitle, ServiceTag } from "@/components/public/section"
import { siteButton } from "@/components/public/site-button"
import { ApplicationForm } from "@/components/public/ApplicationForm"
import { GoogleFormEmbed } from "@/components/public/GoogleFormEmbed"
import { VacancyCard } from "@/components/public/VacancyCard"
import { useApi } from "@/hooks/use-api"
import { useSeo } from "@/hooks/use-seo"
import { api, ApiError } from "@/lib/api"
import { useCompanyDetails, type CompanyDetails } from "@/lib/company"
import { SERVICE_LABELS } from "@/lib/services"
import { jobPostingLd } from "@/lib/structured-data"
import type { PublicVacancy, PublicVacancyDetail } from "@/lib/types"

// /careers/:slug: one role, from the Website area (section S7, file 06).
//
// What the job involves, what you need, the checks we will do, then one way to
// apply: the linked Google Form (embedded after a click, or as a link), or, for
// a role with no form, the short native form. Never both. A closed or unknown
// role gets a friendly page with the other open roles, not an error.

type State =
  | { kind: "loading" }
  | { kind: "ok"; vacancy: PublicVacancyDetail }
  | { kind: "closed" }
  | { kind: "missing" }
  | { kind: "error"; message: string }

/** Lines of free text as list items, dropping blanks and leading bullets people type. */
function lines(text: string | null): string[] {
  return (text ?? "")
    .split(/\r?\n/)
    .map((l) => l.replace(/^\s*[-*•]\s*/, "").trim())
    .filter(Boolean)
}

export default function CareerRole() {
  const { slug = "" } = useParams()
  // Keyed by slug, so moving between roles starts each one afresh.
  return <RolePage key={slug} slug={slug} />
}

function RolePage({ slug }: { slug: string }) {
  const company = useCompanyDetails()
  const [state, setState] = useState<State>({ kind: "loading" })

  useEffect(() => {
    let live = true
    api<{ vacancy: PublicVacancyDetail }>(`vacancies.php?slug=${encodeURIComponent(slug)}`, { auth: false })
      .then((res) => live && setState({ kind: "ok", vacancy: res.vacancy }))
      .catch((err) => {
        if (!live) return
        if (err instanceof ApiError && err.status === 410) setState({ kind: "closed" })
        else if (err instanceof ApiError && err.status === 404) setState({ kind: "missing" })
        else setState({ kind: "error", message: err instanceof ApiError ? err.message : "Could not load this role." })
      })
    return () => {
      live = false
    }
  }, [slug])

  const v = state.kind === "ok" ? state.vacancy : null
  useSeo(
    v
      ? {
          title: v.location && !v.title.includes(v.location) ? `${v.title}, ${v.location}` : v.title,
          description: v.summary.slice(0, 155),
          path: `/careers/${v.slug}`,
          jsonLd: jobPostingLd(company, v),
        }
      : {
          title: state.kind === "closed" ? "This role has closed" : "Role not found",
          description: "See the current roles at RitaGreen Services.",
          noindex: state.kind !== "loading",
        },
  )

  if (state.kind === "loading") {
    return (
      <Container className="py-20">
        <p className="text-muted-foreground" aria-live="polite">Loading the role.</p>
      </Container>
    )
  }
  if (state.kind !== "ok" || !v) return <NoRole state={state} company={company} />

  const requirements = lines(v.requirements)
  const checks = lines(v.checks)
  const facts: [string, string][] = [
    ["Service", SERVICE_LABELS[v.service]],
    ["Location", v.location],
    ["Hours", v.hours_text],
    ["Pay", v.pay_text ?? ""],
  ].filter(([, value]) => value.trim() !== "") as [string, string][]

  return (
    <>
      <PageHeader
        eyebrow={SERVICE_LABELS[v.service]}
        title={v.title}
        breadcrumb={v.title}
        trail={[{ to: "/careers", label: "Careers" }]}
        intro={<p>{v.summary}</p>}
      >
        {v.apply !== "closed" && (
          <a href="#apply" className={siteButton()}>
            Apply for this role
          </a>
        )}
      </PageHeader>

      <Section tone="surface" labelledBy="role-title">
        <div className="grid gap-10 md:grid-cols-[1fr_1.4fr]">
          <div>
            <h2 id="role-title" className="text-[1.625rem] leading-tight">The role</h2>
            <dl className="mt-5 grid gap-3">
              {facts.map(([label, value]) => (
                <div key={label} className="grid grid-cols-[6rem_1fr] gap-3">
                  <dt className="text-muted-foreground">{label}</dt>
                  <dd className="font-semibold">{label === "Service" ? <ServiceTag service={v.service} /> : value}</dd>
                </div>
              ))}
            </dl>
          </div>
          <div className="grid gap-8">
            {requirements.length > 0 && (
              <div>
                <h3 className="text-xl">What you need</h3>
                <CheckList items={requirements} className="mt-4" />
              </div>
            )}
            {checks.length > 0 && (
              <div>
                <h3 className="text-xl">The checks we will do</h3>
                <p className="mt-2 text-muted-foreground">
                  Nothing to send now. We complete these with you after you apply, before your first shift.
                </p>
                <CheckList items={checks} className="mt-4" />
              </div>
            )}
          </div>
        </div>
      </Section>

      <Section id="apply" labelledBy="apply-title">
        <div className="grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
          {v.apply === "form" && v.form ? (
            <>
              <SectionTitle
                id="apply-title"
                title="Apply"
                intro="A short form: your contact details, right to work, experience and when you can work. Google shows its own confirmation when you send it."
              />
              <GoogleFormEmbed formId={v.form.id} display={v.form.display} roleTitle={v.title} company={company} />
            </>
          ) : v.apply === "native" ? (
            <>
              <SectionTitle
                id="apply-title"
                title="Send us your details"
                intro={`No account needed. The office will call you about the role. Questions first? Call ${company.phone.display}.`}
              />
              <div className="rounded-lg border border-border bg-card p-5 sm:p-8">
                <ApplicationForm fixedRole={{ title: v.title, service: v.service }} />
              </div>
            </>
          ) : (
            <>
              <SectionTitle id="apply-title" title="Applications for this role are closed" />
              <div className="grid gap-4">
                <p>
                  See the other current roles, or call the office on{" "}
                  <a href={company.phone.href} className="font-semibold underline underline-offset-4">
                    {company.phone.display}
                  </a>
                  .
                </p>
                <p>
                  <Link to="/careers" className={siteButton({ variant: "secondary" })}>
                    See current roles
                  </Link>
                </p>
              </div>
            </>
          )}
        </div>
      </Section>
    </>
  )
}

function NoRole({ state, company }: { state: State; company: CompanyDetails }) {
  const others = useApi<{ vacancies: PublicVacancy[] }>("vacancies.php")
  const list = (others.data?.vacancies ?? []).slice(0, 4)
  const title =
    state.kind === "closed" ? "This role has closed" : state.kind === "error" ? "We could not load this role" : "We cannot find that role"
  return (
    <>
      <PageHeader
        eyebrow="Careers"
        title={title}
        breadcrumb={title}
        trail={[{ to: "/careers", label: "Careers" }]}
        intro={
          <p>
            {state.kind === "closed"
              ? "Thank you for your interest. This role is no longer taking applications."
              : state.kind === "error"
                ? "Something went wrong loading it."
                : "It may have been filled, or the link may be mistyped."}{" "}
            Call the office on{" "}
            <a href={company.phone.href} className="font-semibold underline underline-offset-4">
              {company.phone.display}
            </a>{" "}
            or see the roles we have open now.
          </p>
        }
      >
        <Link to="/careers" className={siteButton()}>
          See current roles
        </Link>
      </PageHeader>
      {list.length > 0 && (
        <Section tone="surface" labelledBy="other-roles">
          <SectionTitle id="other-roles" title="Other current roles" />
          <ul className="mt-8 grid gap-4 md:grid-cols-2">
            {list.map((r) => (
              <li key={r.slug}>
                <VacancyCard vacancy={r} />
              </li>
            ))}
          </ul>
        </Section>
      )}
    </>
  )
}
