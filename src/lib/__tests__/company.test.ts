import { describe, expect, it } from "vitest"
import { COMPANY_DETAILS, companyFromRecord, telHref, type CompanyRecord } from "@/lib/company"

const RECORD: CompanyRecord = {
  trading_name: "RitaGreen Services",
  legal_name: "RitaGreen Services Ltd",
  company_no: "12528439",
  phone: "020 7946 0018",
  urgent_phone: "07377 563859",
  email: "office@example.test",
  office_hours: "Monday to Saturday, 8am to 6pm",
  area: "Greater London",
  registered_address: "",
  instagram_url: "https://instagram.com/ritagreenservices",
  facebook_url: "",
  linkedin_url: "https://www.linkedin.com/company/ritagreen-services",
  tiktok_url: "https://www.tiktok.com/@ritagreenservices",
  whatsapp_url: "https://wa.me/442079460018",
}

describe("telHref", () => {
  it("turns a UK number into an international tel: link", () => {
    expect(telHref("0203 105 2218")).toBe("tel:+442031052218")
    expect(telHref("+44 (0)7377 563859")).toBe("tel:+447377563859")
  })
  it("gives nothing for text that cannot be rung", () => {
    expect(telHref("call us")).toBe("")
    expect(telHref("123")).toBe("")
  })
})

describe("companyFromRecord", () => {
  it("uses the office app's values", () => {
    const c = companyFromRecord(RECORD)
    expect(c.phone).toEqual({ display: "020 7946 0018", href: "tel:+442079460018" })
    expect(c.email).toBe("office@example.test")
    expect(c.officeHours).toBe("Monday to Saturday, 8am to 6pm")
  })

  it("shows only the social links that are set, and never X", () => {
    const c = companyFromRecord(RECORD)
    expect(c.socials.map((s) => s.key)).toEqual(["instagram", "linkedin", "tiktok"])
    expect(c.whatsappHref).toBe("https://wa.me/442079460018")
  })

  it("keeps the bundled facts when the API gives nothing", () => {
    expect(companyFromRecord(null)).toBe(COMPANY_DETAILS)
    expect(companyFromRecord(undefined)).toBe(COMPANY_DETAILS)
  })

  it("keeps a bundled required fact when the record leaves it empty or unusable", () => {
    const c = companyFromRecord({ ...RECORD, phone: "not a number", email: "  " })
    expect(c.phone).toEqual(COMPANY_DETAILS.phone)
    expect(c.email).toBe(COMPANY_DETAILS.email)
  })

  it("never shows a link that is not https", () => {
    const c = companyFromRecord({ ...RECORD, instagram_url: "javascript:alert(1)", whatsapp_url: "http://wa.me/1" })
    expect(c.socials.some((s) => s.key === "instagram")).toBe(false)
    expect(c.whatsappHref).toBe("")
  })
})
