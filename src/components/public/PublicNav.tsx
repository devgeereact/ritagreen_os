import { useState } from "react"
import { Link, NavLink } from "react-router-dom"
import { Menu, Phone } from "lucide-react"
import { Wordmark } from "@/components/brand/Wordmark"
import { siteButton } from "@/components/public/site-button"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet"
import { useAuth } from "@/providers/auth-provider"
import { homeFor } from "@/lib/access"
import { useCompanyDetails } from "@/lib/company"
import { cn } from "@/lib/utils"

// The website header, as in the 2026 mock: the wordmark, seven links in this
// order, a "Staff login" text link and one primary button. Healthcare leads
// because it is most of the work (2026 plan, file 01).
const SITE_NAV = [
  { to: "/healthcare", label: "Healthcare" },
  { to: "/cleaning", label: "Cleaning" },
  { to: "/security", label: "Security" },
  { to: "/training", label: "Training" },
  { to: "/careers", label: "Careers" },
  { to: "/about", label: "About" },
  { to: "/contact", label: "Contact" },
] as const

function desktopLink({ isActive }: { isActive: boolean }) {
  return cn(
    "rounded-md px-1 py-2 text-[15px] font-medium underline-offset-[6px] transition-colors hover:text-primary",
    isActive ? "text-primary underline decoration-2" : "text-foreground",
  )
}

/**
 * The thin bar above the header: the office number and its hours, nothing else.
 * Hidden on phones, where the menu carries the number instead.
 */
function ContactBar() {
  const company = useCompanyDetails()
  return (
    <div className="hidden border-b border-border bg-card text-sm text-muted-foreground md:block">
      <div className="mx-auto flex max-w-6xl items-center justify-end gap-6 px-4 py-1.5 sm:px-6">
        <span>{company.officeHours}</span>
        <span>
          {company.urgentLabel}:{" "}
          <a href={company.urgentPhone.href} className="font-semibold text-foreground underline-offset-4 hover:underline">
            {company.urgentPhone.display}
          </a>
        </span>
        <a href={company.phone.href} className="inline-flex items-center gap-1.5 font-semibold text-foreground underline-offset-4 hover:underline">
          <Phone aria-hidden="true" className="size-3.5" />
          {company.phone.display}
        </a>
      </div>
    </div>
  )
}

export function PublicNav() {
  const [open, setOpen] = useState(false)
  const { user } = useAuth()
  const company = useCompanyDetails()
  // Somebody already signed in is offered their own area, not the sign-in page.
  const account = user ? { to: homeFor(user), label: "Your account" } : { to: "/login", label: "Staff login" }

  return (
    <header className="sticky top-0 z-40 bg-card">
      <ContactBar />
      <div className="border-b border-border">
        <nav
          aria-label="Main"
          className="mx-auto flex max-w-6xl items-center gap-x-4 lg:gap-x-7 px-4 py-3.5 sm:px-6"
        >
          <Link to="/" aria-label="RitaGreen Services, home" className="shrink-0 rounded-md py-1">
            <Wordmark />
          </Link>

          <ul className="hidden flex-1 items-center gap-x-5 lg:flex">
            {SITE_NAV.map((item) => (
              <li key={item.to}>
                <NavLink to={item.to} className={desktopLink}>
                  {item.label}
                </NavLink>
              </li>
            ))}
          </ul>

          <div className="ml-auto flex items-center gap-2 sm:gap-4">
            <Link
              to={account.to}
              className="hidden text-[15px] font-semibold text-primary underline-offset-4 hover:underline sm:inline"
            >
              {account.label}
            </Link>
            <Link to="/request" className={cn(siteButton({ size: "sm" }), "px-3 sm:px-3.5")}>
              Request staff
            </Link>

            <Sheet open={open} onOpenChange={setOpen}>
              <SheetTrigger asChild>
                <button
                  type="button"
                  className={cn(siteButton({ variant: "secondary", size: "sm" }), "lg:hidden")}
                  aria-label="Open the menu"
                >
                  <Menu aria-hidden="true" className="size-4" />
                  Menu
                </button>
              </SheetTrigger>
              <SheetContent side="right" className="w-[88vw] max-w-sm overflow-y-auto bg-card">
                <SheetHeader>
                  <SheetTitle className="text-left">
                    <Wordmark />
                  </SheetTitle>
                  <SheetDescription className="sr-only">Site menu</SheetDescription>
                </SheetHeader>
                <ul className="mt-6 divide-y divide-border border-y border-border">
                  {SITE_NAV.map((item) => (
                    <li key={item.to}>
                      <NavLink
                        to={item.to}
                        onClick={() => setOpen(false)}
                        className={({ isActive }) =>
                          cn("block py-3 text-lg font-medium", isActive ? "text-primary" : "text-foreground")
                        }
                      >
                        {item.label}
                      </NavLink>
                    </li>
                  ))}
                </ul>
                <div className="mt-6 grid gap-3">
                  <Link to="/request" onClick={() => setOpen(false)} className={siteButton()}>
                    Request staff
                  </Link>
                  <Link to={account.to} onClick={() => setOpen(false)} className={siteButton({ variant: "secondary" })}>
                    {account.label}
                  </Link>
                </div>
                <div className="mt-8 space-y-1 text-sm text-muted-foreground">
                  <p>
                    Office:{" "}
                    <a href={company.phone.href} className="font-semibold text-foreground">
                      {company.phone.display}
                    </a>
                  </p>
                  <p>{company.officeHours}</p>
                  <p>
                    {company.urgentLabel}:{" "}
                    <a href={company.urgentPhone.href} className="font-semibold text-foreground">
                      {company.urgentPhone.display}
                    </a>
                  </p>
                </div>
              </SheetContent>
            </Sheet>
          </div>
        </nav>
      </div>
    </header>
  )
}
