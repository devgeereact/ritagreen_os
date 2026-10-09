import { describe, expect, it } from "vitest"
import { addDays, fromInput, rotaRows, shiftTimes, shiftsOn, toInput, weekDays, weekLabel, weekStart } from "@/lib/rota"
import type { RotaShift } from "@/lib/types"

const shift = (over: Partial<RotaShift>): RotaShift => ({
  id: 1, worker_id: null, worker: null, division: "care", title: "Day carer", site_name: null,
  client_id: null, client: null, starts_at: "2026-10-12 08:00:00", ends_at: "2026-10-12 16:00:00",
  status: "open", is_draft: false, site_id: null, state: "open", live_offers: 0, conflicts: [],
  ...over,
})

describe("rota dates", () => {
  it("finds the Monday of a week", () => {
    expect(weekStart("2026-10-11")).toBe("2026-10-05") // Sunday
    expect(weekStart("2026-10-12")).toBe("2026-10-12") // Monday
  })

  it("moves by days across the clock change without drifting", () => {
    expect(addDays("2026-10-19", 7)).toBe("2026-10-26")
    expect(addDays("2026-03-23", 7)).toBe("2026-03-30")
    expect(weekDays("2026-10-26")).toHaveLength(7)
  })

  it("labels a week", () => {
    expect(weekLabel("2026-10-12")).toBe("12 to 18 Oct 2026")
    // "Sep" or "Sept" depending on the ICU build.
    expect(weekLabel("2026-09-28")).toMatch(/^28 Sept? to 4 Oct 2026$/)
  })

  it("marks a shift that ends the next day", () => {
    expect(shiftTimes("2026-10-12 08:00:00", "2026-10-12 16:00:00")).toBe("08:00 to 16:00")
    expect(shiftTimes("2026-10-12 20:00:00", "2026-10-13 08:00:00")).toBe("20:00 to 08:00 (+1)")
  })

  it("round-trips a datetime-local value", () => {
    expect(toInput("2026-10-12 08:00:00")).toBe("2026-10-12T08:00")
    expect(fromInput("2026-10-12T08:00")).toBe("2026-10-12 08:00:00")
  })
})

describe("rota rows", () => {
  const shifts = [
    shift({ id: 1, site_id: 2, site_name: "Bramley Court", worker_id: 9, worker: "Dmitri" }),
    shift({ id: 2, site_id: 1, site_name: "Oakleaf", starts_at: "2026-10-12 20:00:00" }),
    shift({ id: 3, site_id: 1, site_name: "Oakleaf", worker_id: 4, worker: "Amara" }),
    shift({ id: 4, site_name: "Old free text" }),
    shift({ id: 5 }),
  ]

  it("groups by site, alphabetically, with no site last", () => {
    expect(rotaRows(shifts, "site").map((r) => r.label)).toEqual(["Bramley Court", "Oakleaf", "Old free text", "No site"])
  })

  it("groups by worker with open shifts last", () => {
    expect(rotaRows(shifts, "worker").map((r) => r.label)).toEqual(["Amara", "Dmitri", "Open shifts"])
  })

  it("orders a day's shifts by time", () => {
    const oak = rotaRows(shifts, "site").find((r) => r.label === "Oakleaf")!
    expect(shiftsOn(oak.shifts, "2026-10-12").map((s) => s.id)).toEqual([3, 2])
  })
})
