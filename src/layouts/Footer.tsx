import { Link } from "react-router-dom"
import { Logo } from "@/components/brand/Logo"
import { COMPANY } from "@/lib/company"

const LINKS = [
  { label: "Help", to: "/app/help", internal: true },
  { label: "Settings", to: "/app/settings", internal: true },
  { label: "Website", to: "/", internal: false },
  { label: "Privacy", to: "/privacy", internal: false },
  { label: "Terms", to: "/terms", internal: false },
]

export default function Footer() {
  return (
    <footer className="border-t">
      <div className="flex flex-col gap-3 px-6 py-4 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <Logo />
          <span className="hidden text-xs text-muted-foreground/80 sm:inline">{COMPANY.primaryTagline}</span>
        </div>

        <nav className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
          {LINKS.map((l) =>
            l.internal ? (
              <Link key={l.label} to={l.to} className="transition-colors hover:text-primary">{l.label}</Link>
            ) : (
              <a key={l.label} href={l.to} className="transition-colors hover:text-primary">{l.label}</a>
            ),
          )}
        </nav>

        <span className="text-xs">
          © {new Date().getFullYear()}{" "}
          <span className="font-medium text-foreground">{COMPANY.legalName}</span> · No. {COMPANY.companyNo}
        </span>
      </div>
    </footer>
  )
}
