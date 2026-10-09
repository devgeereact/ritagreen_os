import type { LucideIcon } from "lucide-react"
import {
  Gauge,
  UserPlus,
  Users,
  ShieldCheck,
  CalendarRange,
  Banknote,
  Globe,
  FileSpreadsheet,
  Settings2,
} from "lucide-react"
import type { FeatureName } from "@/lib/types"

// The office app's sidebar, as data. Kept out of the component so the rules
// (who sees which destination, which switch hides what) can be unit tested in
// src/lib/__tests__/nav.test.ts.

export type NavLink = {
  title: string
  url: string
  badge?: number
}

/** One destination in the rail: a page, and the secondary pages that live under it. */
export type NavDestination = NavLink & {
  icon: LucideIcon
  items?: NavLink[]
}

export type NavItem = NavLink & { feature?: FeatureName }
export type NavEntry = Omit<NavDestination, "items"> & { feature?: FeatureName; items?: NavItem[] }

// The eight destinations of the 2026 plan (file 02), plus Finance, which is
// shown only while the owner has it switched on. Each destination is one page;
// the pages that belong to it sit underneath as secondary links.
//
// Every page still has its route. Content Studio, Analytics and Insights are no
// longer in the navigation and are retired in section S9; Notifications live in
// the header bell. All of them stay reachable from the ⌘K palette until then.
export const NAV: NavEntry[] = [
  { title: "Today", url: "/app", icon: Gauge },
  { title: "Recruitment", url: "/app/ats", icon: UserPlus },
  {
    title: "People",
    url: "/app/workers",
    icon: Users,
    items: [{ title: "Training", url: "/app/academy" }],
  },
  { title: "Compliance", url: "/app/compliance", icon: ShieldCheck },
  {
    title: "Rota",
    url: "/app/scheduling",
    icon: CalendarRange,
    items: [
      { title: "Timesheets", url: "/app/timesheets" },
      // Cover requests and enquiries, together. /app/enquiries and /app/bookings
      // still open on their own and are in the palette.
      { title: "Requests", url: "/app/requests" },
      { title: "Clients", url: "/app/clients" },
      { title: "Jobs", url: "/app/jobs" },
      { title: "Field ops", url: "/app/field" },
      { title: "Incidents", url: "/app/incidents" },
    ],
  },
  {
    title: "Finance",
    url: "/app/invoicing",
    icon: Banknote,
    feature: "finance",
    items: [
      { title: "Quotes", url: "/app/quotes" },
      { title: "Contracts", url: "/app/contracts" },
      { title: "Payroll", url: "/app/payroll" },
    ],
  },
  {
    title: "Website",
    url: "/app/blog",
    icon: Globe,
    items: [
      { title: "Vacancies", url: "/app/vacancies" },
      { title: "Forms", url: "/app/website-forms" },
      { title: "Testimonials", url: "/app/testimonials" },
      { title: "Company details", url: "/app/company" },
    ],
  },
  { title: "Reports", url: "/app/reports", icon: FileSpreadsheet },
  {
    title: "Settings",
    url: "/app/settings",
    icon: Settings2,
    items: [
      { title: "Inbox", url: "/app/inbox", feature: "inbox" },
      { title: "Comms", url: "/app/comms" },
      { title: "Files", url: "/app/files" },
      { title: "Help", url: "/app/help" },
    ],
  },
]

/**
 * What this account sees: only pages it may open (the route guard reads the
 * same map, so a visible link always leads somewhere the user is allowed to be),
 * only areas the owner has switched on. A destination whose own page is closed
 * to the account leads to its first open secondary page instead, and disappears
 * when it has none.
 */
export function visibleDestinations(
  allowed: (url: string) => boolean,
  isOn: (name: FeatureName) => boolean,
  badges: Record<string, number>,
): NavDestination[] {
  const shown = (l: NavItem) => allowed(l.url) && (!l.feature || isOn(l.feature))
  const out: NavDestination[] = []
  for (const d of NAV) {
    if (d.feature && !isOn(d.feature)) continue
    const items = (d.items ?? []).filter(shown).map((l) => ({ title: l.title, url: l.url, badge: badges[l.url] }))
    if (allowed(d.url)) {
      out.push({ title: d.title, url: d.url, icon: d.icon, items })
    } else if (items.length > 0) {
      const [first, ...rest] = items
      out.push({ title: d.title, url: first.url, icon: d.icon, items: rest })
    }
  }
  return out
}
