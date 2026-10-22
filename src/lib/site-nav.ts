import { GraduationCap, HeartHandshake, ShieldCheck, SprayCan, type LucideIcon } from "lucide-react"
import type { ServiceId } from "@/lib/services"
import { SERVICE_PAGE_LINKS } from "@/lib/service-pages"

// The website's navigation, in one place, read by the header (PublicNav) and
// the footer (PublicFooter) so the two cannot drift. The office app's sidebar
// is src/lib/nav.ts; this file is the public site only.
//
// The header carries four destinations: Services opens a panel with the four
// services, and Careers, About and Contact are plain links. Seven top-level
// links, a login link and a button did not fit between 1024px and 1200px and
// read as a list rather than a choice.

export type ServiceNavItem = {
  service: ServiceId
  to: string
  label: string
  /** One line in the Services panel and the phone menu. */
  blurb: string
  icon: LucideIcon
}

// One Lucide icon per service; no sparkle icons (docs/DESIGN_SYSTEM.md, section 1).
const SERVICE_EXTRAS: Record<ServiceId, { blurb: string; icon: LucideIcon }> = {
  care: { blurb: "Carers, support workers and nurses for planned and short-notice cover.", icon: HeartHandshake },
  cleaning: { blurb: "Scheduled cleaning for properties, schools and sites.", icon: SprayCan },
  security: { blurb: "Trained, licensed officers for your premises.", icon: ShieldCheck },
  academy: { blurb: "Care Certificate, mandatory and refresher courses.", icon: GraduationCap },
}

/** The four services, in SERVICE_PAGE_LINKS order (Healthcare leads). */
export const SERVICE_NAV: readonly ServiceNavItem[] = SERVICE_PAGE_LINKS.map((s) => ({
  service: s.service,
  to: s.to,
  label: s.label,
  ...SERVICE_EXTRAS[s.service],
}))

/** The two Healthcare routes, shown under the service grid in the panel. */
export const HEALTHCARE_ROUTES = [
  { to: "/healthcare/staffing", label: "Staffing for care providers" },
  { to: "/healthcare/supported-living", label: "Supported living and home care" },
] as const

/** Plain top-level links after Services. */
export const SITE_LINKS = [
  { to: "/careers", label: "Careers" },
  { to: "/about", label: "About" },
  { to: "/contact", label: "Contact" },
] as const
