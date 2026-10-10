import {
  LayoutDashboard,
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
  Newspaper,
  Star,
  Mail,
  Megaphone,
  FileSpreadsheet,
  type LucideIcon,
} from "lucide-react"
import type { FeatureName } from "@/lib/types"

// The internal application's addressable pages, in one list.
//
// It was declared inside global-search.tsx, which made it reachable only by the
// palette. The browser tab needs the same labels: every /app route rendered
// under whatever <title> the last public page had set, so the tab, the history
// entry and a screen reader's page announcement all said the website's title
// no matter where you were.
//
// The sidebar keeps its own tree: it shows the eight destinations of the 2026
// plan with their secondary pages, and shortens some labels to fit ("Field
// ops"). Groups here follow the same destinations. Keywords carry the old names
// (Command centre, Scheduling, Workers, Academy, Bookings, Blog) so a search for
// what a page used to be called still finds it.

export type Destination = {
  label: string
  path: string
  icon: LucideIcon
  group: "Workforce" | "Rota" | "Finance" | "Website" | "Reports" | "Settings"
  keywords?: string
  /** Shown only while the owner has this switched on (server/features.php). */
  feature?: FeatureName
}

export const DESTINATIONS: Destination[] = [
  { label: "Today", path: "/app", icon: LayoutDashboard, group: "Workforce", keywords: "command centre dashboard home overview action" },
  { label: "Recruitment", path: "/app/ats", icon: ClipboardList, group: "Workforce", keywords: "ats applicants candidates hiring onboarding" },
  { label: "Import from spreadsheet", path: "/app/ats/import", icon: FileSpreadsheet, group: "Workforce", keywords: "csv google sheets responses bulk upload staff contact details" },
  { label: "People", path: "/app/workers", icon: Users, group: "Workforce", keywords: "workers staff carers guards cleaners workforce profiles" },
  // One person's page. /app/people itself redirects to the People list above.
  { label: "Person profile", path: "/app/people", icon: Users, group: "Workforce", keywords: "person profile record checks emergency contacts" },
  { label: "Training", path: "/app/academy", icon: GraduationCap, group: "Workforce", keywords: "academy courses learners certificates programmes" },
  { label: "Compliance", path: "/app/compliance", icon: ShieldCheck, group: "Workforce", keywords: "dbs sia expiry right to work eligibility checks" },

  { label: "Rota", path: "/app/scheduling", icon: CalendarRange, group: "Rota", keywords: "scheduling shifts cover assign calendar" },
  { label: "Timesheets", path: "/app/timesheets", icon: Clock, group: "Rota", keywords: "hours worked approve timetable time tracking" },
  { label: "Clients", path: "/app/clients", icon: Building2, group: "Rota", keywords: "accounts customers sites" },
  { label: "Requests", path: "/app/requests", icon: Inbox, group: "Rota", keywords: "cover requests enquiries bookings website owner create shifts" },
  { label: "Enquiries", path: "/app/enquiries", icon: Inbox, group: "Rota", keywords: "requests leads contact form website" },
  { label: "Cover requests", path: "/app/bookings", icon: CalendarRange, group: "Rota", keywords: "bookings requests website" },
  { label: "Jobs", path: "/app/jobs", icon: Briefcase, group: "Rota", keywords: "work orders visits cleans guard shifts courses" },
  { label: "Field operations", path: "/app/field", icon: MapPin, group: "Rota", keywords: "clock in check calls panic on site" },
  { label: "Incidents", path: "/app/incidents", icon: AlertTriangle, group: "Rota", keywords: "complaints safeguarding accidents" },

  { label: "Invoicing", path: "/app/invoicing", icon: Receipt, group: "Finance", keywords: "invoices billing payments stripe", feature: "finance" },
  { label: "Quotes", path: "/app/quotes", icon: FileText, group: "Finance", keywords: "pricing estimates quoting", feature: "finance" },
  { label: "Contracts", path: "/app/contracts", icon: FileText, group: "Finance", keywords: "agreements sla client", feature: "finance" },
  { label: "Payroll", path: "/app/payroll", icon: Banknote, group: "Finance", keywords: "pay wages payslips gross premiums holiday accrual", feature: "finance" },

  // Content Studio was retired in S9 and /app/content-studio redirects here, so
  // its old name still finds the page.
  { label: "Posts", path: "/app/blog", icon: Newspaper, group: "Website", keywords: "blog articles news website content studio writing" },
  { label: "Testimonials", path: "/app/testimonials", icon: Star, group: "Website", keywords: "reviews quotes website" },
  { label: "Vacancies", path: "/app/vacancies", icon: Briefcase, group: "Website", keywords: "jobs roles careers hiring adverts website" },
  { label: "Forms", path: "/app/website-forms", icon: ClipboardList, group: "Website", keywords: "google forms application directory intake website" },
  { label: "Company details", path: "/app/company", icon: Building2, group: "Website", keywords: "phone email hours address social links instagram facebook linkedin tiktok whatsapp" },

  // Analytics and Insights were folded into Reports in S5 and deleted in S9;
  // their routes redirect here.
  { label: "Reports", path: "/app/reports", icon: FileText, group: "Reports", keywords: "cqc regulatory packs export analytics insights pipeline hours expiring unfilled workload csv" },

  { label: "Settings", path: "/app/settings", icon: Settings, group: "Settings", keywords: "configuration users roles permissions features switches" },
  { label: "Inbox", path: "/app/inbox", icon: Mail, group: "Settings", keywords: "email mailbox", feature: "inbox" },
  { label: "Comms", path: "/app/comms", icon: Megaphone, group: "Settings", keywords: "announcements messages" },
  { label: "Files", path: "/app/files", icon: FileText, group: "Settings", keywords: "documents uploads policies rams" },
  { label: "Notifications", path: "/app/notifications", icon: Bell, group: "Settings", keywords: "alerts bell" },
  { label: "Profile", path: "/app/profile", icon: User, group: "Settings", keywords: "my account password 2fa" },
  { label: "Help", path: "/app/help", icon: LifeBuoy, group: "Settings", keywords: "support contact docs" },
]

export const GROUP_ORDER: Destination["group"][] = [
  "Workforce",
  "Rota",
  "Finance",
  "Website",
  "Reports",
  "Settings",
]

/**
 * The document title for an /app path, or null when the path is not one of the
 * pages above (a detail route such as /app/clients/12, say).
 *
 * Longest matching prefix wins, so /app/clients/12 titles as Clients rather than
 * falling back to Today.
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
