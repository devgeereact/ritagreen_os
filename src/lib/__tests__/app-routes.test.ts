import { describe, it, expect } from "vitest"
import { DESTINATIONS, titleFor } from "@/lib/app-routes"
import { rolesFor } from "@/lib/access"

// The page registry behind the ⌘K palette and the browser tab title.

describe("titleFor", () => {
  it("names an exact page", () => {
    expect(titleFor("/app/invoicing")).toBe("Invoicing")
    expect(titleFor("/app")).toBe("Command centre")
  })

  it("takes the longest matching prefix, so a detail page keeps its section", () => {
    expect(titleFor("/app/clients/12")).toBe("Clients")
    expect(titleFor("/app/academy/3")).toBe("Academy")
  })

  it("does not match on a shared word-prefix", () => {
    // "/app/academy-archive" is not under "/app/academy".
    expect(titleFor("/app/academy-archive")).toBe("Command centre")
  })

  it("returns null for a path outside the app", () => {
    expect(titleFor("/about")).toBeNull()
    expect(titleFor("/portal")).toBeNull()
  })
})

describe("DESTINATIONS", () => {
  it("lists no page twice", () => {
    const paths = DESTINATIONS.map((d) => d.path)
    expect(new Set(paths).size).toBe(paths.length)
  })

  it("points only at /app routes the access map governs", () => {
    // A destination the palette offers must be a page the router knows the
    // roles for, or canAccess() would wave everyone through to a dead end.
    for (const d of DESTINATIONS) {
      expect(d.path.startsWith("/app")).toBe(true)
      expect(rolesFor(d.path)).not.toBeNull()
    }
  })
})
