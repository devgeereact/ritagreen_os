import { HeartHandshake, GraduationCap, SprayCan, ShieldCheck, type LucideIcon } from "lucide-react"

// Each division gets a recognisable icon so the brand reads instantly across the
// site — nav mega-menu, home grid, service pages and footer all draw from here.
export const DIVISION_ICONS: Record<string, LucideIcon> = {
  care: HeartHandshake,
  academy: GraduationCap,
  cleaning: SprayCan,
  security: ShieldCheck,
}

export function divisionIcon(id: string): LucideIcon {
  return DIVISION_ICONS[id] ?? HeartHandshake
}

// Build a translucent tint from a division's hex accent — used for soft icon
// chips and accent washes without adding a colour to the Tailwind palette.
export function accentTint(hex: string, alpha = 0.12): string {
  const h = hex.replace("#", "")
  const r = parseInt(h.slice(0, 2), 16)
  const g = parseInt(h.slice(2, 4), 16)
  const b = parseInt(h.slice(4, 6), 16)
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}
