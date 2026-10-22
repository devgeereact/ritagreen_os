// The four services' public names and tag colours, in one small module.
//
// Small on purpose: format.ts (every office page) and divisions.ts (the public
// site's long copy) both need these, and neither should pull the other in.
//
// Names follow owner decision D01 (2026 plan): Healthcare, Cleaning, Security,
// Training. The keys are the internal ids stored in the database and used in
// routes, and they do not change: `care` is Healthcare, `academy` is Training.
//
// Accents are for the service tag only: a small dot beside the word, a chart
// series, a calendar event edge. Never a page background, a button or a filled
// surface (docs/DESIGN_SYSTEM.md).

export const SERVICE_IDS = ["care", "cleaning", "security", "academy"] as const
export type ServiceId = (typeof SERVICE_IDS)[number]

export const SERVICE_LABELS: Record<ServiceId, string> = {
  care: "Healthcare",
  cleaning: "Cleaning",
  security: "Security",
  academy: "Training",
}

export const SERVICE_ACCENTS: Record<ServiceId, string> = {
  care: "#33512D",
  cleaning: "#2C6E7F",
  security: "#3B4452",
  academy: "#B7791F",
}

/** Select options for a service picker, in the public order. */
export const SERVICE_OPTIONS: { value: ServiceId; label: string }[] = SERVICE_IDS.map((id) => ({
  value: id,
  label: SERVICE_LABELS[id],
}))

/** The public name for a stored service id; unknown ids are shown as stored. */
export function serviceLabel(id: string | null | undefined): string {
  if (!id) return ""
  return (SERVICE_LABELS as Record<string, string>)[id] ?? id
}
