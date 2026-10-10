// Helpers for the staff portal (/m), kept out of the screens so they can be
// tested (src/lib/__tests__/portal.test.ts).
//
// Shift times are wall-clock strings in Europe/London ("2026-10-12 08:00:00"),
// as the office typed them. Nothing here converts them through the phone's own
// time zone: a worker whose phone is still set to holiday time must still read
// 08:00 for an 08:00 shift.

import { addDays, dayLabel } from "@/lib/rota"

/** Now, as a London wall-clock string ("2026-10-09 21:47:00"), to compare with shift times. */
export function londonNow(now: Date = new Date()): string {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/London", year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23",
  }).formatToParts(now)
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? "00"
  return `${get("year")}-${get("month")}-${get("day")} ${get("hour")}:${get("minute")}:${get("second")}`
}

/** "Today", "Tomorrow" or "Friday 10 October" for a wall-clock datetime. */
export function relativeDay(datetime: string, today: string): string {
  const date = datetime.slice(0, 10)
  if (date === today) return "Today"
  if (date === addDays(today, 1)) return "Tomorrow"
  if (date === addDays(today, -1)) return "Yesterday"
  return dayLabel(date, true)
}

/** "Morning", "Afternoon" or "Evening", from the hour on the phone. */
export function greeting(hour: number): string {
  if (hour < 12) return "Morning"
  if (hour < 18) return "Afternoon"
  return "Evening"
}

/**
 * A plain map search link for an address, or null with nothing to search for.
 *
 * A link, opened only when the worker taps it: nothing is loaded from a map
 * provider while the page is showing, so the portal makes no third-party request
 * on its own (CLAUDE.md, "The public site makes no third-party requests").
 */
export function mapLink(address: string | null, postcode: string | null): string | null {
  const q = [address, postcode].map((s) => (s ?? "").trim()).filter(Boolean).join(", ")
  if (!q) return null
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`
}

/** Shifts grouped by the date they start, in time order. */
export function byDay<T extends { starts_at: string }>(shifts: T[]): { date: string; shifts: T[] }[] {
  const out: { date: string; shifts: T[] }[] = []
  for (const s of [...shifts].sort((a, b) => a.starts_at.localeCompare(b.starts_at))) {
    const date = s.starts_at.slice(0, 10)
    const last = out[out.length - 1]
    if (last && last.date === date) last.shifts.push(s)
    else out.push({ date, shifts: [s] })
  }
  return out
}

// ---------------------------------------------------------------- calendar

export type IcsShift = {
  id: number
  title: string
  starts_at: string
  ends_at: string
  site_name?: string | null
  site_address?: string | null
  site_postcode?: string | null
  site_notes?: string | null
}

/** Text escaped for an iCalendar value (RFC 5545 3.3.11). */
export function icsEscape(text: string): string {
  return text.replace(/\\/g, "\\\\").replace(/;/g, "\\;").replace(/,/g, "\\,").replace(/\r?\n/g, "\\n")
}

/** "2026-10-12 08:00:00" as "20261012T080000". */
function icsLocal(datetime: string): string {
  return datetime.slice(0, 19).replace(/[-:]/g, "").replace(" ", "T")
}

/** Lines longer than 75 characters folded as RFC 5545 3.1 asks. */
function fold(line: string): string {
  if (line.length <= 75) return line
  const parts: string[] = [line.slice(0, 75)]
  for (let i = 75; i < line.length; i += 74) parts.push(" " + line.slice(i, i + 74))
  return parts.join("\r\n")
}

// Europe/London, so a calendar on a phone set to another zone still places an
// 08:00 shift at 08:00 London time, and either side of the clock change.
const LONDON = [
  "BEGIN:VTIMEZONE",
  "TZID:Europe/London",
  "BEGIN:DAYLIGHT",
  "TZOFFSETFROM:+0000",
  "TZOFFSETTO:+0100",
  "TZNAME:BST",
  "DTSTART:19700329T010000",
  "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU",
  "END:DAYLIGHT",
  "BEGIN:STANDARD",
  "TZOFFSETFROM:+0100",
  "TZOFFSETTO:+0000",
  "TZNAME:GMT",
  "DTSTART:19701025T020000",
  "RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU",
  "END:STANDARD",
  "END:VTIMEZONE",
]

/**
 * An .ics file for the given shifts, built on the phone.
 *
 * No calendar service is involved: the file is handed to the phone, which offers
 * to add it to whichever calendar the worker uses. Each shift keeps the same UID,
 * so adding the rota again updates the entries rather than duplicating them.
 */
export function buildIcs(shifts: IcsShift[], now: Date = new Date()): string {
  const stamp = now.toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "")
  const lines = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//RitaGreen Services//Staff portal//EN", "CALSCALE:GREGORIAN", ...LONDON]
  for (const s of shifts) {
    const where = [s.site_name, s.site_address, s.site_postcode].filter(Boolean).join(", ")
    lines.push(
      "BEGIN:VEVENT",
      `UID:shift-${s.id}@ritagreenservices.com`,
      `DTSTAMP:${stamp}`,
      `DTSTART;TZID=Europe/London:${icsLocal(s.starts_at)}`,
      `DTEND;TZID=Europe/London:${icsLocal(s.ends_at)}`,
      `SUMMARY:${icsEscape(s.site_name ? `${s.title}, ${s.site_name}` : s.title)}`,
    )
    if (where) lines.push(`LOCATION:${icsEscape(where)}`)
    if (s.site_notes) lines.push(`DESCRIPTION:${icsEscape(`Site notes: ${s.site_notes}`)}`)
    lines.push("END:VEVENT")
  }
  lines.push("END:VCALENDAR")
  return lines.map(fold).join("\r\n") + "\r\n"
}

/** Hand the file to the phone as a download. */
export function downloadIcs(shifts: IcsShift[], filename = "ritagreen-shifts.ics"): void {
  const blob = new Blob([buildIcs(shifts)], { type: "text/calendar;charset=utf-8" })
  const url = URL.createObjectURL(blob)
  const a = document.createElement("a")
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
