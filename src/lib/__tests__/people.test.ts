import { describe, expect, it } from "vitest"
import { actionLabel, hoursLabel, initials, isRestricted, personPath, verifierLabel, wallClock } from "@/lib/people"

describe("isRestricted", () => {
  it("recognises a section the account may not see", () => {
    expect(isRestricted({ restricted: true })).toBe(true)
  })
  it("does not mistake data for a restriction", () => {
    expect(isRestricted([])).toBe(false)
    expect(isRestricted({ restricted: false })).toBe(false)
    expect(isRestricted(null)).toBe(false)
  })
})

describe("personPath", () => {
  it("addresses the profile by person id", () => {
    expect(personPath(12)).toBe("/app/people/12")
  })
})

describe("wallClock", () => {
  // Shift times are wall-clock. Reading them through the browser's time zone
  // would move a 07:30 start to 08:30 for half the year.
  it("keeps the time as written", () => {
    expect(wallClock("2026-10-09 07:30:00")).toBe("Fri 9 Oct, 07:30")
  })
  it("says so when there is nothing", () => {
    expect(wallClock(null)).toBe("Not recorded")
  })
})

describe("small labels", () => {
  it("writes minutes as hours", () => {
    expect(hoursLabel(450)).toBe("7h 30m")
    expect(hoursLabel(480)).toBe("8h")
  })
  it("takes two initials", () => {
    expect(initials("Amara  Chen Okafor")).toBe("AC")
  })
  it("turns an audit key into words", () => {
    expect(actionLabel("person.emergency_contacts")).toBe("Person emergency contacts")
  })
})

describe("verifierLabel", () => {
  it("names the reviewer when there is one", () => {
    expect(verifierLabel("Ade Admin", "verification")).toBe("Ade Admin")
    expect(verifierLabel("Ade Admin", "certificate")).toBe("Ade Admin")
  })
  it("says a certificate row has not been verified", () => {
    expect(verifierLabel(null, "certificate")).toBe("From certificate record, not yet verified")
  })
  it("says nobody when nothing is on file", () => {
    expect(verifierLabel(null, null)).toBe("Nobody yet")
  })
})
