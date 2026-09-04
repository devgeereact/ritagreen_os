import { Outlet, Link } from "react-router-dom"
import { CookieConsent } from "@/components/public/CookieConsent"
import { COMPANY } from "@/lib/company"

/**
 * The unauthenticated pages that sit outside the marketing site.
 *
 * Sign-in, registration, password reset and the shift-offer link render on
 * their own, so they had no footer, no legal links and no storage notice —
 * including /register, which collects a name, an email address and a phone
 * number, and /login, which is where the session token gets written in the
 * first place. Somebody arriving straight at one of those pages from an email
 * had no route to the privacy notice at all.
 *
 * A single strip, not a full site footer: these pages are a task, and the task
 * is what the person came to do.
 */
export default function BareLayout() {
  return (
    <div className="flex min-h-svh flex-col">
      <div className="flex-1">
        <Outlet />
      </div>
      <footer className="border-t bg-background px-4 py-4">
        <nav
          aria-label="Legal"
          className="mx-auto flex max-w-3xl flex-wrap items-center justify-center gap-x-4 gap-y-2 text-xs text-muted-foreground"
        >
          <span>
            © {new Date().getFullYear()} {COMPANY.legalName}. Company No. {COMPANY.companyNo}.
          </span>
          <Link to="/privacy" className="hover:text-primary">Privacy</Link>
          <Link to="/terms" className="hover:text-primary">Terms</Link>
          <Link to="/cookies" className="hover:text-primary">Cookies</Link>
          <Link to="/data-request" className="hover:text-primary">Your data</Link>
        </nav>
      </footer>
      <CookieConsent />
    </div>
  )
}
