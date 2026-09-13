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

/**
 * The first and last day of the month a date falls in, as YYYY-MM-DD.
 *
 * Built and read in UTC throughout, deliberately. Payroll used the local-time
 * form of this -- `new Date(y, m, 1)` then `toISOString()` -- and during British
 * Summer Time midnight local is 23:00 the previous day in UTC, so slicing the
 * ISO string moved both ends back a day: "September" defaulted to 31 August
 * through 29 September. That silently paid a day belonging to the previous run
 * twice and never paid the last day of the month at all, for the seven months
 * of the year that BST covers. The server side is UTC (schema.sql sets
 * time_zone = '+00:00'), so this agrees with it rather than with the browser.
 */
export function monthBounds(on: Date = new Date()): { from: string; to: string } {
  const y = on.getUTCFullYear()
  const m = on.getUTCMonth()
  const iso = (d: Date) => d.toISOString().slice(0, 10)
  return {
    from: iso(new Date(Date.UTC(y, m, 1))),
    to: iso(new Date(Date.UTC(y, m + 1, 0))),
  }
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
