import { Link } from "react-router-dom"
import { Clock, Facebook, Globe, Instagram, Linkedin, Mail, MessageCircle, Phone, type LucideIcon } from "lucide-react"
import { Wordmark } from "@/components/brand/Wordmark"
import { useCompanyDetails, type SocialKey } from "@/lib/company"
import { SERVICE_NAV } from "@/lib/site-nav"
import { openStorageNotice } from "@/lib/storage-notice"

// The website footer, on green-900 as the design system sets for the footer
// and the careers band. Four columns and a legal line, nothing else: who we are
// and how to reach us, the four services, the company pages, and help. Every
// fact comes from useCompanyDetails(), which reads the CMS record (S7); a fact
// that is not confirmed (the registered address today) is left out rather than
// shown as a placeholder.
//
// There is no newsletter box. An earlier footer had one that stored nothing and
// told people they had subscribed; capturing subscribers properly needs a table,
// a consent record and a working unsubscribe, and is future work.

// Lucide has no TikTok mark; the globe stands in, and every icon link carries
// its name for screen readers and as a tooltip.
const SOCIAL_ICONS: Record<SocialKey, LucideIcon> = {
  instagram: Instagram,
  facebook: Facebook,
  linkedin: Linkedin,
  tiktok: Globe,
}

const COMPANY_LINKS = [
  { to: "/about", label: "About us" },
  { to: "/careers", label: "Careers" },
  { to: "/news", label: "News and advice" },
  { to: "/verify", label: "Check a certificate" },
] as const

const HELP_LINKS = [
  { to: "/request", label: "Request staff" },
  { to: "/contact", label: "Send a message" },
  { to: "/data-request", label: "Your data" },
] as const

// Green-200 on green-900 is about 9:1; white for headings and hover.
const linkClass = "text-[#C6D6BF] underline-offset-4 transition-colors duration-150 hover:text-white hover:underline"
const headingClass = "font-sans text-sm font-semibold uppercase tracking-wider text-white"
const iconLinkClass =
  "grid size-10 place-items-center rounded-md border border-white/15 text-[#C6D6BF] transition-colors duration-150 hover:border-white/40 hover:text-white"

export function PublicFooter() {
  const company = useCompanyDetails()
  const year = new Date().getFullYear()

  return (
    <footer className="bg-[#1F3219] text-[15px] text-[#C6D6BF]">
      <div className="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:grid-cols-2 sm:px-6 lg:grid-cols-[1.5fr_1fr_1fr_1fr]">
        <div>
          <Link to="/" aria-label="RitaGreen Services, home" className="inline-block rounded-md">
            <Wordmark tone="light" />
          </Link>
          <p className="mt-3 max-w-xs leading-relaxed">
            Healthcare staffing, cleaning, security and training across London, from one office.
          </p>
          {company.registeredAddress && <p className="mt-3 max-w-xs">{company.registeredAddress}</p>}

          <ul className="mt-5 grid gap-2.5">
            <li className="flex items-center gap-2.5">
              <Phone aria-hidden="true" className="size-4 shrink-0 text-[#9FB995]" />
              <a href={company.phone.href} className={`${linkClass} font-semibold text-white`}>
                {company.phone.display}
              </a>
            </li>
            <li className="flex items-center gap-2.5">
              <Mail aria-hidden="true" className="size-4 shrink-0 text-[#9FB995]" />
              <a href={`mailto:${company.email}`} className={`${linkClass} break-all`}>
                {company.email}
              </a>
            </li>
            <li className="flex items-center gap-2.5">
              <Clock aria-hidden="true" className="size-4 shrink-0 text-[#9FB995]" />
              <span>{company.officeHours}</span>
            </li>
          </ul>

          {(company.socials.length > 0 || company.whatsappHref) && (
            <ul aria-label="RitaGreen on social media" className="mt-6 flex flex-wrap gap-2">
              {company.socials.map((s) => {
                const Icon = SOCIAL_ICONS[s.key]
                return (
                  <li key={s.key}>
                    <a href={s.href} target="_blank" rel="noopener noreferrer" title={s.label} className={iconLinkClass}>
                      <Icon aria-hidden="true" className="size-[18px]" />
                      <span className="sr-only">{s.label} (opens in a new tab)</span>
                    </a>
                  </li>
                )
              })}
              {company.whatsappHref && (
                <li>
                  <a href={company.whatsappHref} target="_blank" rel="noopener noreferrer" title="WhatsApp" className={iconLinkClass}>
                    <MessageCircle aria-hidden="true" className="size-[18px]" />
                    <span className="sr-only">WhatsApp (opens WhatsApp)</span>
                  </a>
                </li>
              )}
            </ul>
          )}
        </div>

        <nav aria-labelledby="footer-services">
          <h2 id="footer-services" className={headingClass}>
            Services
          </h2>
          <ul className="mt-4 grid gap-2.5">
            {SERVICE_NAV.map((s) => (
              <li key={s.to}>
                <Link to={s.to} className={linkClass}>
                  {s.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>

        <nav aria-labelledby="footer-company">
          <h2 id="footer-company" className={headingClass}>
            Company
          </h2>
          <ul className="mt-4 grid gap-2.5">
            {COMPANY_LINKS.map((l) => (
              <li key={l.to}>
                <Link to={l.to} className={linkClass}>
                  {l.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>

        <nav aria-labelledby="footer-help">
          <h2 id="footer-help" className={headingClass}>
            Help
          </h2>
          <ul className="mt-4 grid gap-2.5">
            {HELP_LINKS.map((l) => (
              <li key={l.to}>
                <Link to={l.to} className={linkClass}>
                  {l.label}
                </Link>
              </li>
            ))}
            <li>
              {company.urgentLabel}:{" "}
              <a href={company.urgentPhone.href} className={`${linkClass} font-semibold text-white whitespace-nowrap`}>
                {company.urgentPhone.display}
              </a>
            </li>
          </ul>
        </nav>
      </div>

      <div className="border-t border-white/10">
        <div className="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-5 text-sm sm:px-6 md:flex-row md:items-center md:justify-between">
          <p>
            © {year} {company.legalName}. Registered in England and Wales, company number {company.companyNo}.
          </p>
          <ul aria-label="Legal" className="flex flex-wrap gap-x-5 gap-y-1">
            <li><Link to="/privacy" className={linkClass}>Privacy</Link></li>
            <li><Link to="/terms" className={linkClass}>Terms</Link></li>
            <li><Link to="/cookies" className={linkClass}>Cookies</Link></li>
            <li>
              {/* The storage notice can be reopened at any time: a preference you
                  cannot revisit is not a preference. */}
              <button type="button" onClick={openStorageNotice} className={`${linkClass} rounded-sm text-left`}>
                Storage notice
              </button>
            </li>
            <li><Link to="/login" className={linkClass}>Staff login</Link></li>
          </ul>
        </div>
      </div>
    </footer>
  )
}
