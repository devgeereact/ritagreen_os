import { describe, expect, it } from "vitest"
import { buildIcs, byDay, greeting, icsEscape, londonNow, mapLink, relativeDay } from "@/lib/portal"

describe("portal days and greetings", () => {
  it("says today and tomorrow", () => {
    expect(relativeDay("2026-10-09 08:00:00", "2026-10-09")).toBe("Today")
    expect(relativeDay("2026-10-10 07:30:00", "2026-10-09")).toBe("Tomorrow")
    expect(relativeDay("2026-10-12 07:30:00", "2026-10-09")).toBe("Monday 12 October")
  })

  it("reads now on the London clock, in summer and winter", () => {
    expect(londonNow(new Date("2026-07-01T06:45:00Z"))).toBe("2026-07-01 07:45:00")
    expect(londonNow(new Date("2026-12-01T06:45:00Z"))).toBe("2026-12-01 06:45:00")
  })

  it("greets by the hour", () => {
    expect(greeting(7)).toBe("Morning")
    expect(greeting(13)).toBe("Afternoon")
    expect(greeting(20)).toBe("Evening")
  })

  it("groups shifts by the day they start, in order", () => {
    const groups = byDay([
      { id: 2, starts_at: "2026-10-11 08:00:00" },
      { id: 1, starts_at: "2026-10-10 20:00:00" },
      { id: 3, starts_at: "2026-10-11 20:00:00" },
    ])
    expect(groups.map((g) => g.date)).toEqual(["2026-10-10", "2026-10-11"])
    expect(groups[1].shifts.map((s) => s.id)).toEqual([2, 3])
  })
})

describe("map link", () => {
  it("is a plain search link, built only from the address", () => {
    expect(mapLink("12 Park Road", "SE13 6AA")).toBe(
      "https://www.google.com/maps/search/?api=1&query=12%20Park%20Road%2C%20SE13%206AA",
    )
  })
  it("is absent with nothing to search for", () => {
    expect(mapLink(null, " ")).toBeNull()
  })
})

describe("calendar file", () => {
  const ics = buildIcs(
    [{
      id: 7, title: "Waking night", starts_at: "2026-10-24 22:00:00", ends_at: "2026-10-25 08:00:00",
      site_name: "Oakleaf, Main House", site_address: "12 Park Road", site_postcode: "SE13 6AA",
      site_notes: "Sign in at reception;\nkey safe 1234",
    }],
    new Date("2026-10-09T10:00:00Z"),
  )

  it("keeps the wall-clock times in London time, across the clock change", () => {
    expect(ics).toContain("DTSTART;TZID=Europe/London:20261024T220000")
    expect(ics).toContain("DTEND;TZID=Europe/London:20261025T080000")
    expect(ics).toContain("TZID:Europe/London")
  })

  it("uses one stable id per shift, so adding again updates", () => {
    expect(ics).toContain("UID:shift-7@ritagreenservices.com")
    expect(ics).toContain("DTSTAMP:20261009T100000Z")
  })

  it("escapes text and uses CRLF", () => {
    expect(icsEscape("a,b;c\nd")).toBe("a\\,b\\;c\\nd")
    expect(ics).toContain("SUMMARY:Waking night\\, Oakleaf\\, Main House")
    expect(ics.split("\r\n")[0]).toBe("BEGIN:VCALENDAR")
    expect(ics.endsWith("END:VCALENDAR\r\n")).toBe(true)
  })

  it("folds long lines", () => {
    const long = buildIcs([{ id: 1, title: "x".repeat(200), starts_at: "2026-10-10 08:00:00", ends_at: "2026-10-10 16:00:00" }])
    for (const line of long.split("\r\n")) expect(line.length).toBeLessThanOrEqual(75)
  })
})
