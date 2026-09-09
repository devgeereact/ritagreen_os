import { Link } from "react-router-dom"
import { COMPANY } from "@/lib/company"
import { useAuth } from "@/providers/auth-provider"
import { canAccess } from "@/lib/access"

// All client-side links. Privacy and Terms used to render as raw <a href>,
// which left the SPA and reloaded the whole app just to read a policy — losing
// anything unsaved on the page you were on. They are ordinary routes.
const LINKS = [
  { label: "Help", to: "/app/help" },
  { label: "Settings", to: "/app/settings" },
  { label: "Website", to: "/" },
  { label: "Privacy", to: "/privacy" },
  { label: "Terms", to: "/terms" },
  { label: "Cookies", to: "/cookies" },
]

// Compact internal footer. The brand mark lives once in the sidebar header —
// the app footer stays text-only to avoid duplicating it.
export default function Footer() {
  // Same rule as the sidebar: a visible link must lead somewhere the user may
  // go. Settings is office-only, and it was offered to every staff account.
  const { user } = useAuth()
  const links = LINKS.filter((l) => canAccess(user, l.to))

  return (
    <footer className="mt-auto border-t bg-background">
      <div className="flex flex-col gap-2 px-6 py-3 text-xs text-muted-foreground sm:h-12 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:py-0">
        <span>
          © {new Date().getFullYear()}{" "}
          <span className="font-medium text-foreground">{COMPANY.legalName}</span>
          <span className="text-muted-foreground/70"> · {COMPANY.primaryTagline}</span>
        </span>

        <nav className="flex flex-wrap items-center gap-x-4 gap-y-1">
          {links.map((l) => (
            <Link key={l.label} to={l.to} className="transition-colors hover:text-primary">{l.label}</Link>
          ))}
          <span className="text-muted-foreground/60">Company No. {COMPANY.companyNo}</span>
        </nav>
      </div>
    </footer>
  )
}
