import { Link } from "react-router-dom"
import { ArrowRight, ExternalLink } from "lucide-react"
import { Container } from "@/components/public/section"
import { useCompanyDetails } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"

// /links: the one link the social profiles carry ("link in bio", 2026 plan,
// file 07). Five routes into the site, then the social profiles and the WhatsApp
// enquiry link from the company record the owner edits in the office app.
// Text and links only: no images, no third-party embeds, so it is fast on a
// phone signal, which is where it is opened.

const ROUTES = [
  { to: "/request?type=staff", label: "Request staff", hint: "Healthcare, cleaning or security staff for your site" },
  { to: "/request?type=care", label: "Arrange care", hint: "Supported living or support at home" },
  { to: "/careers", label: "Current roles", hint: "Work with us: care, cleaning and security" },
  { to: "/training", label: "Training", hint: "Care Certificate, mandatory and refresher courses" },
  { to: "/contact", label: "Contact", hint: "Phone, email and office hours" },
]

const rowClass =
  "flex min-h-14 items-center justify-between gap-4 rounded-lg border border-border bg-card px-5 py-3 transition-colors hover:border-primary"

export default function Links() {
  const company = useCompanyDetails()
  useSeo({
    title: "Links",
    description: "Request staff, arrange care, see current roles, book training or contact RitaGreen Services.",
    path: "/links",
  })

  return (
    <Container className="max-w-xl py-10 md:py-14">
      <h1 className="text-[1.75rem] leading-tight md:text-[2.25rem]">{company.tradingName}</h1>
      <p className="mt-2 text-muted-foreground">Healthcare staffing, cleaning, security and training in London.</p>

      <nav aria-label="Where to go" className="mt-7">
        <ul className="grid gap-3">
          {ROUTES.map((r) => (
            <li key={r.to}>
              <Link to={r.to} className={rowClass}>
                <span>
                  <span className="block text-lg font-semibold">{r.label}</span>
                  <span className="block text-[15px] text-muted-foreground">{r.hint}</span>
                </span>
                <ArrowRight aria-hidden="true" className="size-5 shrink-0 text-primary" />
              </Link>
            </li>
          ))}
        </ul>
      </nav>

      <p className="mt-7">
        Call the office:{" "}
        <a href={company.phone.href} className="font-semibold underline underline-offset-4">
          {company.phone.display}
        </a>
        <span className="block text-[15px] text-muted-foreground">{company.officeHours}</span>
      </p>

      {(company.socials.length > 0 || company.whatsappHref) && (
        <nav aria-label="RitaGreen elsewhere" className="mt-7">
          <h2 className="font-sans text-sm font-semibold text-muted-foreground">Follow us</h2>
          <ul className="mt-3 grid gap-2 sm:grid-cols-2">
            {company.socials.map((s) => (
              <li key={s.key}>
                <a href={s.href} target="_blank" rel="noopener noreferrer" className={rowClass}>
                  <span className="font-semibold">{s.label}</span>
                  <ExternalLink aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                  <span className="sr-only"> (opens in a new tab)</span>
                </a>
              </li>
            ))}
            {company.whatsappHref && (
              <li>
                <a href={company.whatsappHref} target="_blank" rel="noopener noreferrer" className={rowClass}>
                  <span className="font-semibold">Message us on WhatsApp</span>
                  <ExternalLink aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                  <span className="sr-only"> (opens WhatsApp)</span>
                </a>
              </li>
            )}
          </ul>
        </nav>
      )}
    </Container>
  )
}
