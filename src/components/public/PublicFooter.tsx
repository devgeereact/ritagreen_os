import { type ReactNode } from "react"
import { Link } from "react-router-dom"
import { ArrowUp, Instagram, Linkedin, Facebook, Mail, Phone } from "lucide-react"
import { Logo } from "@/components/brand/Logo"
import { Button } from "@/components/ui/button"
import { DIVISIONS } from "@/lib/divisions"
import { COMPANY, SOCIALS } from "@/lib/company"

/**
 * The two ways in, one per audience.
 *
 * This band used to be a newsletter signup. Its submit handler validated the
 * address, showed "Thanks — we'll keep you posted", cleared the field and sent
 * nothing anywhere: there is no subscribers table, no endpoint and no
 * unsubscribe mechanism in this system. Every person who used it was told they
 * had subscribed to a list that does not exist. A form that reports success
 * without storing anything is worse than no form, and a marketing list without a
 * working unsubscribe is a compliance problem on top -- so it is not something
 * to half-wire. Capturing subscribers properly is tracked as future work.
 *
 * What stands here instead are the two entry points that actually complete, and
 * that nothing on the site linked to: /request for a client who needs cover, and
 * /apply for someone who wants work. Both post to endpoints that persist -- a
 * service_requests row and an ATS applicant respectively.
 */
function FooterActions() {
  return (
    <div className="border-b border-border">
      <div className="mx-auto grid max-w-6xl gap-4 px-4 py-8 sm:grid-cols-2">
        <div className="flex flex-col items-start gap-3 rounded-2xl border border-border bg-card p-5 shadow-soft">
          <h3 className="font-display text-lg font-bold tracking-tight">Need cover?</h3>
          <p className="text-sm text-muted-foreground">
            Tell us the role, the site and the times. We confirm fast — same-day where we can.
          </p>
          <Button asChild className="mt-auto">
            <Link to="/request">Request cover</Link>
          </Button>
        </div>
        <div className="flex flex-col items-start gap-3 rounded-2xl border border-border bg-card p-5 shadow-soft">
          <h3 className="font-display text-lg font-bold tracking-tight">Looking for work?</h3>
          <p className="text-sm text-muted-foreground">
            Care, training, cleaning and security roles across London. No experience needed for the
            academy route.
          </p>
          <Button asChild variant="outline" className="mt-auto">
            <Link to="/apply">Apply to join</Link>
          </Button>
        </div>
      </div>
    </div>
  )
}

const SOCIAL_ICON: Record<string, ReactNode> = {
  instagram: <Instagram className="size-4" />,
  facebook: <Facebook className="size-4" />,
  linkedin: <Linkedin className="size-4" />,
  x: <i className="bi bi-twitter-x text-[15px] leading-none" />,
}

export function PublicFooter() {
  return (
    <footer className="mt-20 border-t border-border bg-soft-mesh">
      <FooterActions />
      <div className="mx-auto grid max-w-6xl gap-10 px-4 py-12 md:grid-cols-2 lg:grid-cols-4">
        <div>
          <Logo />
          <p className="mt-3 text-xs font-semibold uppercase tracking-[0.14em] text-primary">
            {COMPANY.primaryTagline}
          </p>
          <p className="mt-3 max-w-xs text-sm text-muted-foreground">
            Care, training, cleaning and security across London. Trained, checked and trusted staff,
            from one name.
          </p>
          {/* For any enquiry — Oxybuild's prominent footer call-to-action. */}
          <div className="mt-5 rounded-2xl border border-border bg-card p-5 shadow-soft">
            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">
              For any enquiry
            </p>
            <a
              href={COMPANY.phoneHref}
              className="mt-1 block font-display text-2xl font-bold tracking-tight text-primary hover:underline"
            >
              {COMPANY.phone}
            </a>
            <div className="mt-3 space-y-1.5 text-sm text-muted-foreground">
              <a href={COMPANY.emergencyPhoneHref} className="flex items-center gap-2 hover:text-foreground">
                <Phone className="size-4" /> {COMPANY.emergencyPhone}
                <span className="text-xs font-medium text-primary">24hr</span>
              </a>
              <a
                href={`mailto:${COMPANY.email}`}
                className="flex items-center gap-2 hover:text-foreground"
              >
                <Mail className="size-4" /> {COMPANY.email}
              </a>
            </div>
          </div>
        </div>

        <div>
          <h3 className="text-sm font-semibold text-foreground">Company</h3>
          <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
            <li><Link to="/about" className="hover:text-foreground">About</Link></li>
            <li><Link to="/sectors" className="hover:text-foreground">Sectors we serve</Link></li>
            <li><Link to="/accreditations" className="hover:text-foreground">Accreditations</Link></li>
            <li><Link to="/careers" className="hover:text-foreground">Careers</Link></li>
            <li><Link to="/resources" className="hover:text-foreground">Resources</Link></li>
            <li><Link to="/quote" className="hover:text-foreground">Request a quote</Link></li>
            <li><Link to="/request" className="hover:text-foreground">Request cover</Link></li>
            <li><Link to="/apply" className="hover:text-foreground">Apply to join us</Link></li>
            <li><Link to="/contact" className="hover:text-foreground">Contact</Link></li>
          </ul>
        </div>

        <div>
          <h3 className="text-sm font-semibold text-foreground">Services</h3>
          <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
            {DIVISIONS.map((d) => (
              <li key={d.id}>
                <Link to={`/services#${d.id}`} className="flex items-center gap-2 hover:text-foreground">
                  <span className="size-2 rounded-full" style={{ backgroundColor: d.accent }} />
                  {d.name}
                </Link>
              </li>
            ))}
          </ul>
        </div>

        <div>
          <h3 className="text-sm font-semibold text-foreground">Resources & legal</h3>
          <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
            <li><Link to="/resources" className="hover:text-foreground">Guides & articles</Link></li>
            <li><Link to="/verify" className="hover:text-foreground">Verify a certificate</Link></li>
            <li><Link to="/privacy" className="hover:text-foreground">Privacy policy</Link></li>
            <li><Link to="/terms" className="hover:text-foreground">Terms</Link></li>
            <li><Link to="/cookies" className="hover:text-foreground">Cookies</Link></li>
            <li><Link to="/login" className="hover:text-foreground">Member login</Link></li>
          </ul>
        </div>
      </div>

      <div className="border-t border-border">
        <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-5 text-xs text-muted-foreground sm:flex-row">
          <p>
            © {new Date().getFullYear()} {COMPANY.legalName}. Company No. {COMPANY.companyNo}.
          </p>
          <div className="flex items-center gap-4">
            {SOCIALS.map((s) => (
              <a
                key={s.key}
                href={s.href}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={s.label}
                className="hover:text-foreground"
              >
                {SOCIAL_ICON[s.key]}
              </a>
            ))}
            <button
              onClick={() => window.scrollTo({ top: 0, behavior: "smooth" })}
              className="flex items-center gap-1 hover:text-foreground"
            >
              Back to top <ArrowUp className="size-3.5" />
            </button>
          </div>
        </div>
      </div>
    </footer>
  )
}
