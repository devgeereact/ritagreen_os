import { useState } from "react"
import { Link, NavLink, useLocation } from "react-router-dom"
import { ArrowRight, Menu, Phone } from "lucide-react"
import { Wordmark } from "@/components/brand/Wordmark"
import { siteButton } from "@/components/public/site-button"
import {
  NavigationMenu,
  NavigationMenuContent,
  NavigationMenuItem,
  NavigationMenuLink,
  NavigationMenuList,
  NavigationMenuTrigger,
  navigationMenuTriggerStyle,
} from "@/components/ui/navigation-menu"
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
import { SERVICE_ACCENTS } from "@/lib/services"
import { HEALTHCARE_ROUTES, SERVICE_NAV, SITE_LINKS, type ServiceNavItem } from "@/lib/site-nav"
import { cn } from "@/lib/utils"

// The website header. Four destinations (Services, Careers, About, Contact),
// one quiet sign-in link and one primary button, so nothing wraps or crowds
// between 1024px and 1440px. Services opens a panel in the pattern of the
// 21st.dev "Navigation Menu" (shadcn/Radix): a featured block on the left and
// the four services with an icon and one line each. The data is in
// src/lib/site-nav.ts, shared with the footer.

function topLink(active: boolean) {
  return cn(navigationMenuTriggerStyle, active && "bg-accent text-primary")
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

/** A service with its icon tile, used in the desktop panel and the phone menu. */
function ServiceEntry({ item, compact = false }: { item: ServiceNavItem; compact?: boolean }) {
  const Icon = item.icon
  return (
    <span className="flex items-start gap-3">
      <span
        aria-hidden="true"
        className="grid size-9 shrink-0 place-items-center rounded-md border border-border bg-background"
        style={{ color: SERVICE_ACCENTS[item.service] }}
      >
        <Icon className="size-[18px]" strokeWidth={2} />
      </span>
      <span className="min-w-0 self-center">
        <span className="block font-semibold text-foreground">{item.label}</span>
        {!compact && <span className="mt-0.5 block text-sm leading-snug text-muted-foreground">{item.blurb}</span>}
      </span>
    </span>
  )
}

function ServicesPanel() {
  return (
    <div className="grid w-[680px] grid-cols-[220px_1fr] gap-3 p-3">
      <NavigationMenuLink asChild>
        <Link
          to="/request"
          className="flex flex-col justify-end rounded-md bg-primary p-5 text-primary-foreground no-underline transition-colors duration-150 hover:bg-[#2A4325]"
        >
          <span className="text-xs font-semibold uppercase tracking-wider text-[#C6D6BF]">Need cover?</span>
          <span className="mt-2 font-display text-xl font-semibold leading-tight">Request checked staff</span>
          <span className="mt-2 text-sm leading-snug text-[#E2EBDE]">
            Tell us the site, dates and shifts. We only offer work to people whose checks are in date.
          </span>
          <span className="mt-4 inline-flex items-center gap-1 text-sm font-semibold">
            Start a request <ArrowRight aria-hidden="true" className="size-4" />
          </span>
        </Link>
      </NavigationMenuLink>

      <div>
        <ul className="grid grid-cols-2 gap-1">
          {SERVICE_NAV.map((item) => (
            <li key={item.to}>
              <NavigationMenuLink asChild>
                <Link to={item.to} className="block rounded-md p-3 no-underline transition-colors duration-150 hover:bg-accent focus:bg-accent">
                  <ServiceEntry item={item} />
                </Link>
              </NavigationMenuLink>
            </li>
          ))}
        </ul>
        <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md bg-muted px-3 py-2.5 text-sm">
          <span className="font-semibold text-foreground">Healthcare:</span>
          {HEALTHCARE_ROUTES.map((r) => (
            <NavigationMenuLink asChild key={r.to}>
              <Link to={r.to} className="text-primary underline-offset-4 hover:underline">
                {r.label}
              </Link>
            </NavigationMenuLink>
          ))}
        </div>
      </div>
    </div>
  )
}

export function PublicNav() {
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()
  const { user } = useAuth()
  const company = useCompanyDetails()
  // Somebody already signed in is offered their own area, not the sign-in page.
  const account = user ? { to: homeFor(user), label: "Your account" } : { to: "/login", label: "Staff login" }
  const inServices = SERVICE_NAV.some((s) => pathname === s.to || pathname.startsWith(`${s.to}/`))
  const close = () => setOpen(false)

  return (
    <header className="sticky top-0 z-40 bg-card">
      <ContactBar />
      <div className="border-b border-border">
        <div className="mx-auto flex max-w-6xl items-center gap-x-6 px-4 py-3 sm:px-6">
          <Link to="/" aria-label="RitaGreen Services, home" className="shrink-0 rounded-md py-1">
            <Wordmark />
          </Link>

          <NavigationMenu aria-label="Main" className="hidden lg:flex">
            <NavigationMenuList>
              <NavigationMenuItem>
                <NavigationMenuTrigger className={cn(inServices && "text-primary")}>Services</NavigationMenuTrigger>
                <NavigationMenuContent>
                  <ServicesPanel />
                </NavigationMenuContent>
              </NavigationMenuItem>
              {SITE_LINKS.map((item) => {
                // Radix's Slot joins class names as strings, so NavLink's
                // function form cannot be used here; `active` sets
                // aria-current="page" for the current section instead.
                const active = pathname === item.to || pathname.startsWith(`${item.to}/`)
                return (
                  <NavigationMenuItem key={item.to}>
                    <NavigationMenuLink asChild active={active}>
                      <Link to={item.to} className={topLink(active)}>
                        {item.label}
                      </Link>
                    </NavigationMenuLink>
                  </NavigationMenuItem>
                )
              })}
            </NavigationMenuList>
          </NavigationMenu>

          <div className="ml-auto flex items-center gap-2 sm:gap-4">
            <Link
              to={account.to}
              className="hidden text-[15px] font-semibold text-foreground underline-offset-4 hover:text-primary hover:underline sm:inline"
            >
              {account.label}
            </Link>
            <Link to="/request" className={cn(siteButton({ size: "sm" }), "px-3 sm:px-4")}>
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
                  <span className="hidden sm:inline">Menu</span>
                </button>
              </SheetTrigger>
              <SheetContent side="right" className="w-[88vw] max-w-sm overflow-y-auto bg-card">
                <SheetHeader>
                  <SheetTitle className="text-left">
                    <Wordmark />
                  </SheetTitle>
                  <SheetDescription className="sr-only">Site menu</SheetDescription>
                </SheetHeader>
                <nav aria-label="Main" className="mt-6">
                  <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Services</p>
                  <ul className="mt-2 grid gap-1">
                    {SERVICE_NAV.map((item) => (
                      <li key={item.to}>
                        <NavLink
                          to={item.to}
                          onClick={close}
                          className={({ isActive }) =>
                            cn("block rounded-md p-2 transition-colors hover:bg-accent", isActive && "bg-accent")
                          }
                        >
                          <ServiceEntry item={item} compact />
                        </NavLink>
                      </li>
                    ))}
                  </ul>
                  <ul className="mt-4 divide-y divide-border border-y border-border">
                    {SITE_LINKS.map((item) => (
                      <li key={item.to}>
                        <NavLink
                          to={item.to}
                          onClick={close}
                          className={({ isActive }) =>
                            cn("block py-3 text-lg font-medium", isActive ? "text-primary" : "text-foreground")
                          }
                        >
                          {item.label}
                        </NavLink>
                      </li>
                    ))}
                  </ul>
                </nav>
                <div className="mt-6 grid gap-3">
                  <Link to="/request" onClick={close} className={siteButton()}>
                    Request staff
                  </Link>
                  <Link to={account.to} onClick={close} className={siteButton({ variant: "secondary" })}>
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
        </div>
      </div>
    </header>
  )
}
