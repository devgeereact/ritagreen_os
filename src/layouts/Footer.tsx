import { Link } from "react-router-dom"
import { COMPANY } from "@/lib/company"

const LINKS = [
  { label: "Help", to: "/app/help", internal: true },
  { label: "Settings", to: "/app/settings", internal: true },
  { label: "Website", to: "/", internal: false },
  { label: "Privacy", to: "/privacy", internal: false },
  { label: "Terms", to: "/terms", internal: false },
]

// Compact internal footer. The brand mark lives once in the sidebar header —
// the app footer stays text-only to avoid duplicating it.
export default function Footer() {
  return (
    <footer className="mt-auto border-t bg-background">
      <div className="flex flex-col gap-2 px-6 py-3 text-xs text-muted-foreground sm:h-12 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:py-0">
        <span>
          © {new Date().getFullYear()}{" "}
          <span className="font-medium text-foreground">{COMPANY.legalName}</span>
          <span className="text-muted-foreground/70"> · {COMPANY.primaryTagline}</span>
        </span>

        <nav className="flex flex-wrap items-center gap-x-4 gap-y-1">
          {LINKS.map((l) =>
            l.internal ? (
              <Link key={l.label} to={l.to} className="transition-colors hover:text-primary">{l.label}</Link>
            ) : (
              <a key={l.label} href={l.to} className="transition-colors hover:text-primary">{l.label}</a>
            ),
          )}
          <span className="text-muted-foreground/60">No. {COMPANY.companyNo}</span>
        </nav>
      </div>
    </footer>
  )
}
