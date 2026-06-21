import { useEffect, useRef, useState } from "react"
import { Link, NavLink, useNavigate } from "react-router-dom"
import { ChevronDown, Menu, LayoutDashboard, LogOut, Phone, Clock, ShieldAlert, ArrowRight } from "lucide-react"
import { Logo } from "@/components/brand/Logo"
import { COMPANY } from "@/lib/company"
import { divisionIcon, accentTint } from "@/lib/division-icons"
import { Button } from "@/components/ui/button"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet"
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { useAuth } from "@/providers/auth-provider"
import { DIVISIONS } from "@/lib/divisions"
import { cn } from "@/lib/utils"

const NAV = [
  { to: "/about", label: "About" },
  { to: "/sectors", label: "Sectors" },
  { to: "/accreditations", label: "Accreditations" },
  { to: "/careers", label: "Careers" },
  { to: "/resources", label: "Resources" },
  { to: "/contact", label: "Contact" },
]

function initials(name: string) {
  return name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase()
}

function linkClass({ isActive }: { isActive: boolean }) {
  return cn(
    "rounded-md px-3 py-2 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
    isActive
      ? "font-semibold text-primary underline decoration-2 underline-offset-8"
      : "font-medium text-muted-foreground hover:text-foreground",
  )
}

export function PublicNav() {
  const [open, setOpen] = useState(false)
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  // Services disclosure: controlled so it works on touch + keyboard, not just hover.
  const [servicesOpen, setServicesOpen] = useState(false)
  const servicesRef = useRef<HTMLDivElement>(null)

  function signOut() {
    logout()
    setOpen(false)
    navigate("/")
  }

  // Close the mega-menu on outside click and on Escape.
  useEffect(() => {
    if (!servicesOpen) return
    const onDown = (e: MouseEvent) => {
      if (servicesRef.current && !servicesRef.current.contains(e.target as Node)) setServicesOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setServicesOpen(false)
    }
    document.addEventListener("mousedown", onDown)
    document.addEventListener("keydown", onKey)
    return () => {
      document.removeEventListener("mousedown", onDown)
      document.removeEventListener("keydown", onKey)
    }
  }, [servicesOpen])

  return (
    <>
      {/* Utility top bar — Oxybuild's two-row header. Scrolls away; the nav below
          stays sticky so /services' `top-16` offsets remain accurate. */}
      <div className="hidden border-b border-primary-foreground/10 bg-primary text-primary-foreground md:block">
        <div className="mx-auto flex h-10 max-w-6xl items-center justify-between gap-4 px-4 text-xs font-medium">
          <div className="flex items-center gap-5 text-primary-foreground/85">
            <a href={COMPANY.phoneHref} className="flex items-center gap-1.5 hover:text-primary-foreground">
              <Phone className="size-3.5" /> {COMPANY.phone}
            </a>
            <span className="hidden items-center gap-1.5 lg:flex">
              <Clock className="size-3.5" /> {COMPANY.hours}
            </span>
          </div>
          <div className="flex items-center gap-5">
            <a
              href={COMPANY.emergencyPhoneHref}
              className="flex items-center gap-1.5 text-primary-foreground/85 hover:text-primary-foreground"
            >
              <ShieldAlert className="size-3.5 text-accent" /> 24hr cover {COMPANY.emergencyPhone}
            </a>
            <Link to="/login" className="text-primary-foreground/85 hover:text-primary-foreground">
              Member login
            </Link>
          </div>
        </div>
      </div>

      <header className="sticky top-0 z-50 w-full border-b border-border bg-background/85 backdrop-blur supports-[backdrop-filter]:bg-background/70">
        <div className="mx-auto flex h-16 max-w-6xl items-center gap-2 px-4">
        <Link to="/" className="mr-2 shrink-0" aria-label="RitaGreen home">
          <Logo />
        </Link>

        {/* Desktop nav */}
        <nav className="hidden items-center gap-1 lg:flex">
          <NavLink to="/" end className={linkClass}>
            Home
          </NavLink>

          {/* Programmes / Services mega-menu */}
          <div
            ref={servicesRef}
            className="relative"
            onMouseEnter={() => setServicesOpen(true)}
            onMouseLeave={() => setServicesOpen(false)}
          >
            <button
              type="button"
              aria-expanded={servicesOpen}
              aria-controls="services-menu"
              aria-haspopup="true"
              onClick={() => setServicesOpen((v) => !v)}
              className={cn(
                "inline-flex items-center gap-1 rounded-md px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                servicesOpen ? "text-foreground" : "text-muted-foreground hover:text-foreground",
              )}
            >
              Services
              <ChevronDown className={cn("size-4 transition-transform", servicesOpen && "rotate-180")} />
            </button>
            <div
              id="services-menu"
              className={cn(
                "absolute left-0 top-full z-50 w-[34rem] pt-3 transition-[opacity,transform] duration-200 ease-out",
                servicesOpen
                  ? "visible translate-y-0 opacity-100"
                  : "pointer-events-none invisible -translate-y-1 opacity-0",
              )}
            >
              <div className="overflow-hidden rounded-2xl border border-border bg-popover shadow-lift">
                <div className="grid grid-cols-2 gap-1 p-2">
                  {DIVISIONS.map((d) => {
                    const Icon = divisionIcon(d.id)
                    return (
                      <Link
                        key={d.id}
                        to={`/services#${d.id}`}
                        onClick={() => setServicesOpen(false)}
                        className="group/item flex items-start gap-3 rounded-xl p-3 transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                      >
                        <span
                          className="grid size-10 shrink-0 place-items-center rounded-lg ring-1 ring-inset ring-black/5"
                          style={{ backgroundColor: accentTint(d.accent, 0.14), color: d.accent }}
                        >
                          <Icon className="size-5" strokeWidth={1.75} />
                        </span>
                        <span className="min-w-0">
                          <span className="flex items-center gap-1 text-sm font-semibold text-foreground">
                            {d.name}
                            <ArrowRight className="size-3.5 -translate-x-1 text-primary opacity-0 transition-all group-hover/item:translate-x-0 group-hover/item:opacity-100" />
                          </span>
                          <span className="mt-0.5 line-clamp-2 block text-xs leading-relaxed text-muted-foreground">
                            {d.tagline}
                          </span>
                        </span>
                      </Link>
                    )
                  })}
                </div>
                <div className="flex items-center justify-between gap-3 border-t border-border bg-soft-mesh px-4 py-3">
                  <p className="text-xs text-muted-foreground">
                    One supplier for the whole day — care, training, cleaning &amp; security.
                  </p>
                  <Link
                    to="/services"
                    onClick={() => setServicesOpen(false)}
                    className="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-primary hover:underline"
                  >
                    All services <ArrowRight className="size-3.5" />
                  </Link>
                </div>
              </div>
            </div>
          </div>

          {NAV.map((n) => (
            <NavLink key={n.to} to={n.to} className={linkClass}>
              {n.label}
            </NavLink>
          ))}
        </nav>

        <div className="ml-auto flex items-center gap-2">
          {user ? (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <button
                  className="hidden items-center gap-2 rounded-full border border-border bg-card py-1 pl-1 pr-2.5 text-sm font-medium shadow-soft transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:inline-flex"
                  aria-label="Account menu"
                >
                  <span className="grid size-7 place-items-center rounded-full bg-primary text-xs font-bold text-primary-foreground">
                    {initials(user.name)}
                  </span>
                  <span className="max-w-[8rem] truncate text-foreground">{user.name.split(" ")[0]}</span>
                  <ChevronDown className="size-4 text-muted-foreground" />
                </button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel className="font-normal">
                  <p className="text-sm font-medium">{user.name}</p>
                  <p className="truncate text-xs text-muted-foreground">{user.email}</p>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={signOut} className="text-destructive focus:text-destructive">
                  <LogOut className="mr-2 size-4" /> Sign out
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          ) : (
            <Button asChild variant="ghost" className="hidden text-foreground sm:inline-flex">
              <Link to="/login">Login</Link>
            </Button>
          )}
          <Button asChild className="hidden sm:inline-flex">
            <Link to={user ? "/app" : "/quote"}>{user ? "Dashboard" : "Get a quote"}</Link>
          </Button>

          {/* Mobile menu */}
          <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
              <Button variant="ghost" size="icon" className="lg:hidden" aria-label="Open menu">
                <Menu className="size-5" />
              </Button>
            </SheetTrigger>
            <SheetContent side="right" className="w-[88vw] max-w-sm overflow-y-auto">
              <SheetHeader>
                <SheetTitle className="text-left">
                  <Logo />
                </SheetTitle>
              </SheetHeader>
              <div className="mt-4 flex flex-col gap-1">
                <MobileLink to="/" onClick={() => setOpen(false)}>
                  Home
                </MobileLink>
                <Accordion type="single" collapsible>
                  <AccordionItem value="services" className="border-b-0">
                    <AccordionTrigger className="px-3 py-2 text-sm font-medium hover:no-underline">
                      Services
                    </AccordionTrigger>
                    <AccordionContent className="pb-1">
                      {DIVISIONS.map((d) => {
                        const Icon = divisionIcon(d.id)
                        return (
                          <Link
                            key={d.id}
                            to={`/services#${d.id}`}
                            onClick={() => setOpen(false)}
                            className="flex items-center gap-3 rounded-md px-3 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                          >
                            <span
                              className="grid size-8 shrink-0 place-items-center rounded-lg ring-1 ring-inset ring-black/5"
                              style={{ backgroundColor: accentTint(d.accent, 0.14), color: d.accent }}
                            >
                              <Icon className="size-4" strokeWidth={1.75} />
                            </span>
                            {d.name}
                          </Link>
                        )
                      })}
                      <Link
                        to="/services"
                        onClick={() => setOpen(false)}
                        className="mt-1 flex items-center gap-1 rounded-md px-3 py-2 text-sm font-semibold text-primary hover:bg-accent"
                      >
                        All services <ArrowRight className="size-3.5" />
                      </Link>
                    </AccordionContent>
                  </AccordionItem>
                </Accordion>
                {NAV.map((n) => (
                  <MobileLink key={n.to} to={n.to} onClick={() => setOpen(false)}>
                    {n.label}
                  </MobileLink>
                ))}
                {user ? (
                  <>
                    <div className="mt-2 flex items-center gap-2 rounded-lg bg-accent/60 px-3 py-2">
                      <span className="grid size-8 place-items-center rounded-full bg-primary text-xs font-bold text-primary-foreground">
                        {initials(user.name)}
                      </span>
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{user.name}</p>
                        <p className="truncate text-xs text-muted-foreground">{user.email}</p>
                      </div>
                    </div>
                    <Button asChild className="mt-3">
                      <Link to="/app" onClick={() => setOpen(false)}>
                        <LayoutDashboard className="mr-2 size-4" /> Open dashboard
                      </Link>
                    </Button>
                    <Button variant="outline" className="mt-2 text-destructive" onClick={signOut}>
                      <LogOut className="mr-2 size-4" /> Sign out
                    </Button>
                  </>
                ) : (
                  <>
                    <MobileLink to="/login" onClick={() => setOpen(false)}>
                      Login
                    </MobileLink>
                    <Button asChild className="mt-3">
                      <Link to="/quote" onClick={() => setOpen(false)}>
                        Get a quote
                      </Link>
                    </Button>
                  </>
                )}
              </div>
            </SheetContent>
          </Sheet>
        </div>
      </div>
      </header>
    </>
  )
}

function MobileLink({
  to,
  children,
  onClick,
}: {
  to: string
  children: React.ReactNode
  onClick: () => void
}) {
  return (
    <NavLink
      to={to}
      end={to === "/"}
      onClick={onClick}
      className={({ isActive }) =>
        cn(
          "rounded-md px-3 py-2 text-sm font-medium",
          isActive ? "bg-accent text-foreground" : "text-muted-foreground hover:bg-accent hover:text-foreground",
        )
      }
    >
      {children}
    </NavLink>
  )
}
