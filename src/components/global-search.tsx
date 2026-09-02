import * as React from "react"
import { useNavigate } from "react-router-dom"

import { Button } from "@/components/ui/button"
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from "@/components/ui/command"
import { useAuth } from "@/providers/auth-provider"
import { canAccess } from "@/lib/access"

import {
  Search,
  LayoutDashboard,
  BarChart3,
  Building2,
  Users,
  ShieldCheck,
  CalendarRange,
  ClipboardList,
  Receipt,
  FileText,
  Inbox,
  GraduationCap,
  Clock,
  Banknote,
  Briefcase,
  AlertTriangle,
  MapPin,
  Bell,
  User,
  Settings,
  LifeBuoy,
  ChevronRight,
  type LucideIcon,
} from "lucide-react"

/**
 * The ⌘K palette.
 *
 * It used to offer six fixed destinations, of which five did not exist in this
 * application at all: /analytics, /crm, /profile, /billing and /settings are not
 * routes here (the real ones live under /app), and "CRM" and "Billing" were
 * never features of RitaGreenOS -- they were left over from the dashboard
 * template this component came from. Choosing any of the five dropped the user
 * out of the CMS and onto the public 404 page; the sixth, "Dashboard", pointed
 * at "/" and took them to the marketing homepage.
 *
 * The destinations now come from the same list the sidebar is built from, and
 * are filtered through canAccess() so the palette never offers a page the user
 * would be bounced off. Keywords are here so "invoice" finds Invoicing and
 * "staff" finds Workers without the label having to contain the word.
 */
type Destination = {
  label: string
  path: string
  icon: LucideIcon
  group: "Operations" | "People" | "Clients & money" | "Insight" | "Account"
  keywords?: string
}

const DESTINATIONS: Destination[] = [
  { label: "Command centre", path: "/app", icon: LayoutDashboard, group: "Operations", keywords: "dashboard home overview" },
  { label: "Scheduling", path: "/app/scheduling", icon: CalendarRange, group: "Operations", keywords: "shifts rota cover assign" },
  { label: "Timetable", path: "/app/timetable", icon: CalendarRange, group: "Operations", keywords: "calendar week" },
  { label: "Jobs", path: "/app/jobs", icon: Briefcase, group: "Operations", keywords: "vacancies roles" },
  { label: "Field operations", path: "/app/field", icon: MapPin, group: "Operations", keywords: "clock in check calls panic on site" },
  { label: "Timesheets", path: "/app/timesheets", icon: Clock, group: "Operations", keywords: "hours worked approve" },
  { label: "Incidents", path: "/app/incidents", icon: AlertTriangle, group: "Operations", keywords: "complaints safeguarding accidents" },

  { label: "Workers", path: "/app/workers", icon: Users, group: "People", keywords: "staff carers guards cleaners workforce" },
  { label: "Compliance", path: "/app/compliance", icon: ShieldCheck, group: "People", keywords: "dbs sia expiry right to work eligibility" },
  { label: "Recruitment", path: "/app/ats", icon: ClipboardList, group: "People", keywords: "ats applicants candidates hiring onboarding" },
  { label: "Academy", path: "/app/academy", icon: GraduationCap, group: "People", keywords: "training courses learners certificates" },
  { label: "Payroll", path: "/app/payroll", icon: Banknote, group: "People", keywords: "pay wages payslips" },

  { label: "Clients", path: "/app/clients", icon: Building2, group: "Clients & money", keywords: "accounts customers sites" },
  { label: "Enquiries", path: "/app/enquiries", icon: Inbox, group: "Clients & money", keywords: "leads contact form" },
  { label: "Bookings", path: "/app/bookings", icon: CalendarRange, group: "Clients & money", keywords: "cover requests" },
  { label: "Quotes", path: "/app/quotes", icon: FileText, group: "Clients & money", keywords: "pricing estimates" },
  { label: "Contracts", path: "/app/contracts", icon: FileText, group: "Clients & money", keywords: "agreements sla" },
  { label: "Invoicing", path: "/app/invoicing", icon: Receipt, group: "Clients & money", keywords: "invoices billing payments stripe" },

  { label: "Analytics", path: "/app/analytics", icon: BarChart3, group: "Insight", keywords: "charts numbers trends" },
  { label: "Insights", path: "/app/insights", icon: BarChart3, group: "Insight", keywords: "ai summary" },
  { label: "Reports", path: "/app/reports", icon: FileText, group: "Insight", keywords: "cqc regulatory packs export" },
  { label: "Files", path: "/app/files", icon: FileText, group: "Insight", keywords: "documents uploads policies rams" },

  { label: "Notifications", path: "/app/notifications", icon: Bell, group: "Account", keywords: "alerts bell" },
  { label: "Profile", path: "/app/profile", icon: User, group: "Account", keywords: "my account password 2fa" },
  { label: "Settings", path: "/app/settings", icon: Settings, group: "Account", keywords: "configuration users roles permissions" },
  { label: "Help", path: "/app/help", icon: LifeBuoy, group: "Account", keywords: "support contact docs" },
]

const GROUP_ORDER: Destination["group"][] = [
  "Operations",
  "People",
  "Clients & money",
  "Insight",
  "Account",
]

export function GlobalSearch() {
  const [open, setOpen] = React.useState(false)
  const navigate = useNavigate()
  const { user } = useAuth()

  // Keyboard Shortcut (Ctrl + K / Cmd + K)
  React.useEffect(() => {
    const down = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "k") {
        e.preventDefault()
        setOpen((prev) => !prev)
      }
    }

    document.addEventListener("keydown", down)

    return () => {
      document.removeEventListener("keydown", down)
    }
  }, [])

  // Same gate as the sidebar and the router, so the palette cannot offer a page
  // this account would be redirected away from.
  const allowed = React.useMemo(
    () => DESTINATIONS.filter((d) => canAccess(user, d.path)),
    [user],
  )

  const runCommand = (path: string) => {
    setOpen(false)
    navigate(path)
  }

  return (
    <>
      {/* Icon Search Button */}
      <Button
        variant="ghost"
        size="icon"
        onClick={() => setOpen(true)}
        aria-label="Search pages (Command K)"
        className="rounded-full [&_svg]:size-5 h-10 w-10 p-0"
      >
        <Search />
      </Button>

      {/* Command Dialog */}
      <CommandDialog open={open} onOpenChange={setOpen}>
        <div className="border-b">
          <CommandInput placeholder="Search pages…" className="h-12" />
        </div>

        <CommandList className="max-h-[420px] overflow-y-auto">
          <CommandEmpty>
            <div className="py-10 text-center text-sm text-muted-foreground">
              No results found.
            </div>
          </CommandEmpty>

          {GROUP_ORDER.map((group, i) => {
            const items = allowed.filter((d) => d.group === group)
            if (items.length === 0) return null
            return (
              <React.Fragment key={group}>
                {i > 0 && <CommandSeparator />}
                <CommandGroup heading={group}>
                  {items.map((d) => (
                    <CommandItem
                      key={d.path}
                      value={`${d.label} ${d.keywords ?? ""}`}
                      onSelect={() => runCommand(d.path)}
                      className="group cursor-pointer"
                    >
                      <d.icon className="mr-3 h-4 w-4 text-muted-foreground" />
                      <span>{d.label}</span>
                      <ChevronRight className="ml-auto h-4 w-4 opacity-0 transition-opacity group-data-[selected=true]:opacity-100" />
                    </CommandItem>
                  ))}
                </CommandGroup>
              </React.Fragment>
            )
          })}
        </CommandList>
      </CommandDialog>
    </>
  )
}
