// The rota's date arithmetic and grouping, kept out of the page so it can be
// tested (src/lib/__tests__/rota.test.ts).
//
// Shift times are wall-clock strings ("2026-10-12 08:00:00"), exactly as the
// office typed them and as the worker reads them. Everything here works on the
// strings and on UTC dates, never on the browser's zone: a rota viewed from a
// laptop set to another country must still say 08:00.

import type { AssignmentCheck, CheckReason, RotaShift, ShiftState } from "@/lib/types"

/** "2026-10-12" moved by whole days. */
export function addDays(iso: string, days: number): string {
  const d = new Date(`${iso.slice(0, 10)}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

/** The Monday of the week a date falls in. */
export function weekStart(iso: string): string {
  const d = new Date(`${iso.slice(0, 10)}T00:00:00Z`)
  const dow = (d.getUTCDay() + 6) % 7 // Monday 0 .. Sunday 6
  return addDays(iso, -dow)
}

/** Seven dates from a Monday. */
export function weekDays(monday: string): string[] {
  return Array.from({ length: 7 }, (_, i) => addDays(monday, i))
}

/** Today's date where the person using the rota is. */
export function todayIso(now: Date = new Date()): string {
  const y = now.getFullYear()
  const m = String(now.getMonth() + 1).padStart(2, "0")
  const d = String(now.getDate()).padStart(2, "0")
  return `${y}-${m}-${d}`
}

/** "Mon 12" or "Monday 12 October", for a date. */
export function dayLabel(iso: string, long = false): string {
  const d = new Date(`${iso.slice(0, 10)}T00:00:00Z`)
  return d.toLocaleDateString("en-GB", long
    ? { weekday: "long", day: "numeric", month: "long", timeZone: "UTC" }
    : { weekday: "short", day: "numeric", timeZone: "UTC" })
}

/** "12 to 18 Oct 2026", or across a month "28 Sep to 4 Oct 2026". */
export function weekLabel(monday: string): string {
  const end = addDays(monday, 6)
  const a = new Date(`${monday}T00:00:00Z`)
  const b = new Date(`${end}T00:00:00Z`)
  const opts = { timeZone: "UTC" } as const
  const sameMonth = a.getUTCMonth() === b.getUTCMonth()
  const from = a.toLocaleDateString("en-GB", { day: "numeric", ...(sameMonth ? {} : { month: "short" }), ...opts })
  const to = b.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric", ...opts })
  return `${from} to ${to}`
}

/** "08:00 to 16:00", marking an end on a later day: "20:00 to 08:00 (+1)". */
export function shiftTimes(startsAt: string, endsAt: string): string {
  const start = startsAt.slice(11, 16)
  const end = endsAt.slice(11, 16)
  const days = Math.round(
    (Date.parse(`${endsAt.slice(0, 10)}T00:00:00Z`) - Date.parse(`${startsAt.slice(0, 10)}T00:00:00Z`)) / 86_400_000,
  )
  return `${start} to ${end}${days > 0 ? ` (+${days})` : ""}`
}

/** A datetime-local input's value from a wall-clock string, and back. */
export const toInput = (sql: string): string => sql.replace(" ", "T").slice(0, 16)
export const fromInput = (value: string): string => (value.length === 16 ? `${value.replace("T", " ")}:00` : value.replace("T", " "))

export type RotaRow = { key: string; label: string; sub?: string; shifts: RotaShift[] }

/**
 * Rows for the week grid: one per site (the default) or one per worker.
 *
 * By site, a shift with no site record falls back to the free-text name older
 * shifts carry, and then to "No site". By worker, open shifts gather in one row
 * at the bottom, because they are the ones still to fill.
 */
export function rotaRows(shifts: RotaShift[], by: "site" | "worker"): RotaRow[] {
  const rows = new Map<string, RotaRow>()
  for (const s of shifts) {
    let key: string
    let label: string
    let sub: string | undefined
    if (by === "site") {
      key = s.site_id !== null ? `site:${s.site_id}` : s.site_name ? `name:${s.site_name}` : "none"
      label = s.site_name ?? "No site"
      sub = s.client ?? undefined
    } else {
      key = s.worker_id !== null ? `worker:${s.worker_id}` : "open"
      label = s.worker ?? "Open shifts"
    }
    const row = rows.get(key) ?? { key, label, sub, shifts: [] }
    row.shifts.push(s)
    rows.set(key, row)
  }
  const last = by === "site" ? "none" : "open"
  return [...rows.values()].sort((a, b) => {
    if (a.key === last) return 1
    if (b.key === last) return -1
    return a.label.localeCompare(b.label, "en-GB")
  })
}

/** A row's shifts on one date, in time order. */
export function shiftsOn(shifts: RotaShift[], iso: string): RotaShift[] {
  return shifts
    .filter((s) => s.starts_at.slice(0, 10) === iso)
    .sort((a, b) => a.starts_at.localeCompare(b.starts_at))
}

export const STATE_LABEL: Record<ShiftState, string> = {
  draft: "Draft",
  open: "Open",
  offered: "Offered",
  assigned: "Published",
  completed: "Completed",
  cancelled: "Cancelled",
}

/** The words for a check reason's kind, for headings and chips. */
export const CHECK_LABEL: Record<CheckReason["code"], string> = {
  employment: "Employment",
  evidence: "Checks and training",
  clash: "Double booking",
  leave: "On leave",
  unavailable: "Marked unavailable",
  rest: "Short rest",
}

/** What happened, in words, for a shift's history. */
export const HISTORY_LABEL: Record<string, string> = {
  "shift.create": "Created",
  "shift.publish": "Published",
  "shift.edit": "Changed",
  "shift.assign": "Assigned",
  "shift.unassign": "Taken off",
  "shift.cancel": "Cancelled",
  "shift.blocked": "Assignment refused",
  "shift.warnings_acknowledged": "Warnings acknowledged",
  "shift.broadcast": "Offered to all eligible",
  "shift.offer": "Offered",
  "shift.accepted": "Offer accepted",
  "me.accept_shift": "Claimed in the app",
}
/** Pull the server's check out of a 409, when it sent one. */
export function checkFromError(data: unknown): { check: AssignmentCheck | null; needsAck: boolean } {
  if (data && typeof data === "object") {
    const d = data as { check?: AssignmentCheck; needs_acknowledgement?: boolean }
    return { check: d.check ?? null, needsAck: d.needs_acknowledgement === true }
  }
  return { check: null, needsAck: false }
}
