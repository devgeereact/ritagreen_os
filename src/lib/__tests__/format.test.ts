import { describe, expect, it } from "vitest"
import { gbp, monthBounds } from "@/lib/format"

describe("gbp", () => {
  it("renders pence as pounds", () => {
    expect(gbp(1350)).toBe("£13.50")
    expect(gbp(0)).toBe("£0.00")
  })
})

describe("monthBounds", () => {
  // The defect this exists to catch. Payroll built its default period from
  // local-time dates and then sliced toISOString(), so under British Summer
  // Time both ends moved back a day: "September" ran 31 August to 29 September.
  // That paid a day belonging to the previous run twice and never paid the last
  // day of the month at all, for the seven months BST covers.
  it("does not shift under British Summer Time", () => {
    expect(monthBounds(new Date("2026-09-13T12:00:00Z"))).toEqual({
      from: "2026-09-01",
      to: "2026-09-30",
    })
    expect(monthBounds(new Date("2026-06-13T12:00:00Z"))).toEqual({
      from: "2026-06-01",
      to: "2026-06-30",
    })
  })

  it("is right outside BST too", () => {
    expect(monthBounds(new Date("2026-01-13T12:00:00Z"))).toEqual({
      from: "2026-01-01",
      to: "2026-01-31",
    })
  })

  it("handles February in a leap year", () => {
    expect(monthBounds(new Date("2028-02-10T12:00:00Z"))).toEqual({
      from: "2028-02-01",
      to: "2028-02-29",
    })
  })

  // Midnight at either edge of the month is where an off-by-one shows up.
  it("is stable at the edges of the month", () => {
    const first = monthBounds(new Date("2026-09-01T00:00:00Z"))
    const last = monthBounds(new Date("2026-09-30T23:59:59Z"))
    expect(first).toEqual(last)
    expect(first).toEqual({ from: "2026-09-01", to: "2026-09-30" })
  })

  it("rolls the year over in December", () => {
    expect(monthBounds(new Date("2026-12-15T12:00:00Z"))).toEqual({
      from: "2026-12-01",
      to: "2026-12-31",
    })
  })
})
