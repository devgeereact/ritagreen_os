import { Logo } from "@/components/brand/Logo"
import { COMPANY } from "@/lib/company"

export default function Footer() {
  return (
    <footer className="border-t">
      <div className="flex flex-col gap-2 px-6 py-4 text-sm text-muted-foreground sm:h-14 sm:flex-row sm:items-center sm:justify-between sm:py-0">
        <div className="flex items-center gap-3">
          <Logo />
          <span className="hidden text-xs text-muted-foreground/80 sm:inline">{COMPANY.primaryTagline}</span>
        </div>
        <span>
          © {new Date().getFullYear()}{" "}
          <span className="font-medium text-foreground">{COMPANY.legalName}</span>. All rights reserved.
        </span>
      </div>
    </footer>
  )
}
