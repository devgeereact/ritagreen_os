// Money is stored in pence. Format to GBP for display.
export function gbp(pence: number): string {
  return new Intl.NumberFormat("en-GB", { style: "currency", currency: "GBP" }).format(pence / 100)
}

export function shortDate(iso: string | null): string {
  if (!iso) return "—"
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" })
}

export const DIVISION_LABELS: Record<string, string> = {
  care: "Healthcare & Care",
  academy: "Academy & Training",
  cleaning: "Cleaning Services",
  security: "Security Services",
}

// Division accents for data viz — brand-derived categorical palette
// (navy / mint / amber / dark), restrained per design system v2.
export const DIVISION_ACCENTS: Record<string, string> = {
  care: "#0C447C",
  academy: "#B7791F",
  cleaning: "#5DCAA5",
  security: "#1B2A3C",
}
