import { useState } from "react"
import { Link, NavLink } from "react-router-dom"
import { ChevronDown, Menu } from "lucide-react"
import { Logo } from "@/components/brand/Logo"
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
import { DIVISIONS } from "@/lib/divisions"
import { cn } from "@/lib/utils"

const NAV = [
  { to: "/about", label: "About" },
  { to: "/sectors", label: "Sectors" },
  { to: "/accreditations", label: "Accreditations" },
  { to: "/resources", label: "Resources" },
]

function linkClass({ isActive }: { isActive: boolean }) {
  return cn(
    "rounded-md px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
    isActive ? "text-primary" : "text-muted-foreground hover:text-foreground",
  )
}

export function PublicNav() {
  const [open, setOpen] = useState(false)

  return (
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
          <div className="group relative">
            <button className="inline-flex items-center gap-1 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring group-focus-within:text-foreground">
              Services
              <ChevronDown className="size-4 transition-transform group-hover:rotate-180 group-focus-within:rotate-180" />
            </button>
            <div className="invisible absolute left-1/2 top-full z-50 w-[680px] -translate-x-1/2 pt-2 opacity-0 transition-all group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
              <div className="grid grid-cols-2 gap-1 rounded-xl border border-border bg-popover p-3 shadow-lg">
                {DIVISIONS.map((d) => (
                  <Link
                    key={d.id}
                    to={`/services#${d.id}`}
                    className="rounded-lg p-3 transition-colors hover:bg-accent"
                  >
                    <div className="flex items-center gap-2">
                      <span
                        className="size-2.5 rounded-full"
                        style={{ backgroundColor: d.accent }}
                      />
                      <span className="text-sm font-semibold text-foreground">{d.name}</span>
                    </div>
                    <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{d.tagline}</p>
                  </Link>
                ))}
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
          <Link
            to="/login"
            className="hidden text-sm font-medium text-muted-foreground hover:text-foreground sm:inline-block"
          >
            Login
          </Link>
          <Button asChild className="hidden sm:inline-flex">
            <Link to="/contact">Enquire</Link>
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
                      {DIVISIONS.map((d) => (
                        <Link
                          key={d.id}
                          to={`/services#${d.id}`}
                          onClick={() => setOpen(false)}
                          className="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                        >
                          <span
                            className="size-2 rounded-full"
                            style={{ backgroundColor: d.accent }}
                          />
                          {d.name}
                        </Link>
                      ))}
                    </AccordionContent>
                  </AccordionItem>
                </Accordion>
                {NAV.map((n) => (
                  <MobileLink key={n.to} to={n.to} onClick={() => setOpen(false)}>
                    {n.label}
                  </MobileLink>
                ))}
                <MobileLink to="/login" onClick={() => setOpen(false)}>
                  Login
                </MobileLink>
                <Button asChild className="mt-3">
                  <Link to="/contact" onClick={() => setOpen(false)}>
                    Enquire
                  </Link>
                </Button>
              </div>
            </SheetContent>
          </Sheet>
        </div>
      </div>
    </header>
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
