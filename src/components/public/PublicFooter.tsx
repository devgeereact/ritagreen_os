import { Link } from "react-router-dom"
import { Wordmark } from "@/components/brand/Wordmark"
import { useCompanyDetails } from "@/lib/company"
import { SERVICE_PAGE_LINKS } from "@/lib/service-pages"
import { openStorageNotice } from "@/lib/storage-notice"

// The website footer, from the 2026 mock: who we are, the four services, the
// company links, how to reach us, then the legal line. Every fact comes from
// useCompanyDetails(), which S7 points at the CMS; a fact that is not confirmed
// (the registered address today) is left out rather than shown as a placeholder.
//
// There is no newsletter box. An earlier footer had one that stored nothing and
// told people they had subscribed; capturing subscribers properly needs a table,
// a consent record and a working unsubscribe, and is future work.

const linkClass = "underline-offset-4 hover:text-foreground hover:underline"

export function PublicFooter() {
  const company = useCompanyDetails()
  const year = new Date().getFullYear()

  return (
    <footer className="border-t border-border bg-card text-[15px] text-muted-foreground">
      <div className="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:grid-cols-[1.4fr_1fr_1fr_1.2fr]">
        <div>
          <Link to="/" aria-label="RitaGreen Services, home" className="inline-block rounded-md">
            <Wordmark />
          </Link>
          <p className="mt-3 max-w-xs">
            Healthcare staffing, cleaning, security and training across London, from one office.
          </p>
          {company.registeredAddress && <p className="mt-3 max-w-xs">{company.registeredAddress}</p>}
        </div>

        <nav aria-labelledby="footer-services">
          <h2 id="footer-services" className="font-sans text-sm font-semibold text-foreground">
            Services
          </h2>
          <ul className="mt-3 grid gap-2">
            {SERVICE_PAGE_LINKS.map((s) => (
              <li key={s.to}>
                <Link to={s.to} className={linkClass}>
                  {s.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>

        <nav aria-labelledby="footer-company">
          <h2 id="footer-company" className="font-sans text-sm font-semibold text-foreground">
            Company
          </h2>
          <ul className="mt-3 grid gap-2">
            <li><Link to="/about" className={linkClass}>About</Link></li>
            <li><Link to="/careers" className={linkClass}>Careers</Link></li>
            <li><Link to="/news" className={linkClass}>News and advice</Link></li>
            <li><Link to="/request" className={linkClass}>Request staff</Link></li>
            <li><Link to="/verify" className={linkClass}>Check a certificate</Link></li>
            <li><Link to="/login" className={linkClass}>Staff login</Link></li>
          </ul>
        </nav>

        <div>
          <h2 className="font-sans text-sm font-semibold text-foreground">Get in touch</h2>
          <ul className="mt-3 grid gap-2">
            <li>
              Office:{" "}
              <a href={company.phone.href} className={`${linkClass} font-semibold text-foreground`}>
                {company.phone.display}
              </a>
            </li>
            <li>{company.officeHours}</li>
            <li>
              {company.urgentLabel}:{" "}
              <a href={company.urgentPhone.href} className={`${linkClass} font-semibold text-foreground`}>
                {company.urgentPhone.display}
              </a>
            </li>
            <li>
              <a href={`mailto:${company.email}`} className={`${linkClass} break-all`}>
                {company.email}
              </a>
            </li>
            <li><Link to="/contact" className={linkClass}>Send a message</Link></li>
          </ul>
          {company.socials.length > 0 && (
            <ul aria-label="RitaGreen on social media" className="mt-4 flex flex-wrap gap-x-4 gap-y-1">
              {company.socials.map((s) => (
                <li key={s.key}>
                  <a href={s.href} target="_blank" rel="noopener noreferrer" className={linkClass}>
                    {s.label}
                    <span className="sr-only"> (opens in a new tab)</span>
                  </a>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <div className="border-t border-border">
        <div className="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-5 text-sm sm:px-6 md:flex-row md:items-center md:justify-between">
          <p>
            © {year} {company.legalName}. Registered in England and Wales, company number {company.companyNo}.
          </p>
          <ul aria-label="Legal" className="flex flex-wrap gap-x-5 gap-y-1">
            <li><Link to="/privacy" className={linkClass}>Privacy</Link></li>
            <li><Link to="/terms" className={linkClass}>Terms</Link></li>
            <li><Link to="/cookies" className={linkClass}>Cookies</Link></li>
            <li><Link to="/data-request" className={linkClass}>Your data</Link></li>
            <li>
              {/* The storage notice can be reopened at any time: a preference you
                  cannot revisit is not a preference. */}
              <button type="button" onClick={openStorageNotice} className={`${linkClass} rounded-sm text-left`}>
                Storage notice
              </button>
            </li>
          </ul>
        </div>
      </div>
    </footer>
  )
}
