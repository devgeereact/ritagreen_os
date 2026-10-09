import { describe, expect, it } from "vitest"
import { googleFormEmbedSrc, googleFormOpenUrl, isGoogleFormId } from "@/lib/google-forms"

const ID = "1FAIpQLSe_example-Form_Id_0123456789abc"

describe("the Google Form address builder", () => {
  it("builds the embed address from a stored id", () => {
    expect(googleFormEmbedSrc(ID)).toBe(`https://docs.google.com/forms/d/e/${ID}/viewform?embedded=true`)
  })

  it("builds the new-tab address from the same id", () => {
    expect(googleFormOpenUrl(ID)).toBe(`https://docs.google.com/forms/d/e/${ID}/viewform`)
  })

  it("always points at docs.google.com over https", () => {
    const src = new URL(googleFormEmbedSrc(ID) as string)
    expect(src.protocol).toBe("https:")
    expect(src.host).toBe("docs.google.com")
  })

  it.each([
    ["a full address instead of an id", `https://docs.google.com/forms/d/e/${ID}/viewform`],
    ["javascript:", "javascript:alert(1)"],
    ["a path escape", `${ID}/../../evil`],
    ["a query string", `${ID}?x=1`],
    ["a quote that could break out of an attribute", `${ID}"onload="x`],
    ["something too short to be a form", "short"],
    ["an empty string", ""],
    ["null", null],
    ["a number", 42],
  ])("builds nothing from %s", (_what, value) => {
    expect(isGoogleFormId(value)).toBe(false)
    expect(googleFormEmbedSrc(value)).toBeNull()
    expect(googleFormOpenUrl(value)).toBeNull()
  })
})
