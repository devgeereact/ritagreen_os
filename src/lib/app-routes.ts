import {
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
  type LucideIcon,
} from "lucide-react"

// The internal application's addressable pages, in one list.
//
// It was declared inside global-search.tsx, which made it reachable only by the
// palette. The browser tab needs the same labels: every /app route rendered
// under whatever <title> the last public page had set, so the tab, the history
// entry and a screen reader's page announcement all said "RitaGreen Services —
// Care, Training, Cleaning & Security in London" no matter where you were.
//
// The sidebar keeps its own tree: it groups differently and shortens some labels
// to fit ("Field ops", "Help & support"), and forcing those to match would
// change the navigation to suit a page title.

export type Destination = {
  label: string
  path: string
  icon: LucideIcon
  group: "Operations" | "People" | "Clients & money" | "Insight" | "Account"
  keywords?: string
}

export const DESTINATIONS: Destination[] = [
  { label: "Command centre", path: "/app", icon: LayoutDashboard, group: "Operations", keywords: "dashboard home overview" },
  { label: "Scheduling", path: "/app/scheduling", icon: CalendarRange, group: "Operations", keywords: "shifts rota cover assign" },
  { label: "Jobs", path: "/app/jobs", icon: Briefcase, group: "Operations", keywords: "work orders visits cleans guard shifts courses" },
  { label: "Field operations", path: "/app/field", icon: MapPin, group: "Operations", keywords: "clock in check calls panic on site" },
  { label: "Timesheets", path: "/app/timesheets", icon: Clock, group: "Operations", keywords: "hours worked approve timetable time tracking" },
  { label: "Payroll", path: "/app/payroll", icon: Banknote, group: "Operations", keywords: "pay wages payslips gross premiums holiday accrual" },
  { label: "Incidents", path: "/app/incidents", icon: AlertTriangle, group: "Operations", keywords: "complaints safeguarding accidents" },

  { label: "Workers", path: "/app/workers", icon: Users, group: "People", keywords: "staff carers guards cleaners workforce" },
  { label: "Compliance", path: "/app/compliance", icon: ShieldCheck, group: "People", keywords: "dbs sia expiry right to work eligibility" },
  { label: "Recruitment", path: "/app/ats", icon: ClipboardList, group: "People", keywords: "ats applicants candidates hiring onboarding" },
  { label: "Academy", path: "/app/academy", icon: GraduationCap, group: "People", keywords: "training courses learners certificates" },

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

export const GROUP_ORDER: Destination["group"][] = [
  "Operations",
  "People",
  "Clients & money",
  "Insight",
  "Account",
]

/**
 * The document title for an /app path, or null when the path is not one of the
 * pages above (a detail route such as /app/clients/12, say).
 *
 * Longest matching prefix wins, so /app/clients/12 titles as Clients rather than
 * falling back to the Command centre.
 */
export function titleFor(pathname: string): string | null {
  let best: Destination | null = null
  for (const d of DESTINATIONS) {
    if (pathname === d.path || pathname.startsWith(d.path + "/")) {
      if (!best || d.path.length > best.path.length) best = d
    }
  }
  return best ? best.label : null
}
