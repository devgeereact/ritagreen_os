import { useSyncExternalStore } from "react"

// Company facts for the public site, the office app's Help page and PDFs.
//
// Two shapes live here.
//
// COMPANY is the flat object older code reads (Help, the PDF footer, the bare
// layout). Keep its keys.
//
// CompanyDetails, read through useCompanyDetails(), is what the website reads.
// Since section S7 the owner edits these facts in the office app (Website,
// Company details) and the site reads them from server/company.php. The values
// below are the fallback: the first paint uses them, and they stay on screen if
// the API cannot be reached, so the site never loses its phone number. Migration
// 039 seeded the database row from them.
//
// A fact that is not known (the registered address, the ICO reference) is empty,
// and every reader leaves it out rather than printing a placeholder.

export const COMPANY = {
  legalName: "RitaGreen Services Ltd",
  shortName: "RitaGreen",
  companyNo: "12528439",
  promise: "Healthcare staffing, cleaning, security and training in London.",
  primaryTagline: "Qualified. Checked. Ready.",
  area: "London and surrounding boroughs",
  phone: "0203 105 2218",
  phoneHref: "tel:+442031052218",
  emergencyPhone: "+44 (0)7377 563859",
  emergencyPhoneHref: "tel:+447377563859",
  email: "hello@ritagreenservices.com",
  hours: "Monday to Friday, 9am to 5pm. 24hr line for urgent cover",

  // ---- Identity shown on the legal pages -----------------------------------
  //
  // These three are the controller's identity in the privacy notice. Empty
  // means "not confirmed yet", and the notice says so in place of the value
  // rather than quietly leaving a gap. Everywhere else simply omits them.

  // OWNER TO CONFIRM: the full registered office address from the Companies
  // House record for 12528439. Do not paste an operating address here.
  registeredAddress: "",

  // OWNER TO CONFIRM: the ICO registration reference (ZA/ZB...).
  icoNumber: "",

  // Where data-protection questions go. Currently the general inbox; a
  // dedicated address would be better once somebody owns it.
  privacyEmail: "hello@ritagreenservices.com",
} as const

/**
 * Are the legal pages still awaiting a solicitor's sign-off?
 *
 * While true, the legal pages carry a short line saying so. Set it to false
 * after review: that is the whole flip, and it is deliberately one line so it
 * is not forgotten.
 */
export const LEGAL_REVIEW_PENDING = true

// Billing details rendered on invoice/quote PDFs. FILL THESE before sending real
// invoices: a UK VAT invoice must show the VAT number, and clients pay against
// the bank details. Leave a value as "" to hide that line on the PDF.
export const BILLING = {
  vatNumber: "", // e.g. "GB 123 4567 89", leave "" if not VAT registered
  bankName: "", // e.g. "Barclays"
  accountName: "RitaGreen Services Ltd",
  sortCode: "", // e.g. "20-00-00"
  accountNumber: "", // e.g. "12345678"
  paymentTermsDays: 14,
} as const

export type SocialKey = "instagram" | "facebook" | "linkedin" | "tiktok"

export type CompanyDetails = {
  legalName: string
  tradingName: string
  companyNo: string
  /** Empty until confirmed; readers omit it. */
  registeredAddress: string
  phone: { display: string; href: string }
  /** The urgent cover line. Shown with `urgentLabel`, never as "24/7". */
  urgentPhone: { display: string; href: string }
  urgentLabel: string
  email: string
  officeHours: string
  /** For structured data: the days and times the office answers. */
  openingHours: { days: string[]; opens: string; closes: string }
  area: string
  /** Profiles on social networks. Also the structured data's sameAs. */
  socials: { key: SocialKey; label: string; href: string }[]
  /** A WhatsApp click-to-chat link for enquiries, or "" for none. Never the staff community. */
  whatsappHref: string
}

// Social profiles. X (Twitter) is not run, so it is not listed (2026 plan,
// file 07). The handles are the existing accounts; the owner changes them in
// the office app.
export const SOCIALS: CompanyDetails["socials"] = [
  { key: "instagram", label: "Instagram", href: "https://instagram.com/ritagreenservices__" },
  { key: "facebook", label: "Facebook", href: "https://facebook.com/ritagreenservices__" },
  { key: "linkedin", label: "LinkedIn", href: "https://linkedin.com/company/ritagreenservices__" },
]

export const COMPANY_DETAILS: CompanyDetails = {
  legalName: COMPANY.legalName,
  tradingName: "RitaGreen Services",
  companyNo: COMPANY.companyNo,
  registeredAddress: COMPANY.registeredAddress,
  phone: { display: COMPANY.phone, href: COMPANY.phoneHref },
  urgentPhone: { display: "07377 563859", href: COMPANY.emergencyPhoneHref },
  urgentLabel: "24hr line for urgent cover",
  email: COMPANY.email,
  officeHours: "Monday to Friday, 9am to 5pm",
  openingHours: { days: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"], opens: "09:00", closes: "17:00" },
  area: COMPANY.area,
  socials: SOCIALS,
  whatsappHref: "",
}

/** The company record as server/company.php returns it: public fields only. */
export type CompanyRecord = {
  trading_name: string
  legal_name: string
  company_no: string
  phone: string
  urgent_phone: string
  email: string
  office_hours: string
  area: string
  registered_address: string
  instagram_url: string
  facebook_url: string
  linkedin_url: string
  tiktok_url: string
  whatsapp_url: string
}

/**
 * A tel: link for a UK number as people write it.
 *
 * "0203 105 2218" becomes tel:+442031052218 and "+44 (0)7377 563859" becomes
 * tel:+447377563859. Anything that is not a plausible number gives "", and the
 * reader then keeps the bundled number.
 */
export function telHref(display: string): string {
  let s = display.trim().replace(/\(0\)/g, "")
  const plus = s.startsWith("+")
  s = s.replace(/\D+/g, "")
  if (s.length < 7 || s.length > 15) return ""
  if (plus) return `tel:+${s}`
  if (s.startsWith("0")) return `tel:+44${s.slice(1)}`
  return `tel:${s}`
}

const SOCIAL_LABELS: Record<SocialKey, string> = {
  instagram: "Instagram",
  facebook: "Facebook",
  linkedin: "LinkedIn",
  tiktok: "TikTok",
}

/** Only an https link is ever shown, whatever the record holds. */
function safeHttps(url: string | undefined): string {
  const u = (url ?? "").trim()
  return /^https:\/\/[^\s"'<>]+$/.test(u) ? u : ""
}

/**
 * The record from the office app laid over the bundled facts.
 *
 * A required fact the record leaves empty, or a phone number that cannot be
 * rung, keeps the bundled value, so a half-filled record cannot blank the
 * footer. Optional facts (the registered address, each social link) come from
 * the record as they are: empty there means "do not show it".
 */
export function companyFromRecord(
  r: Partial<CompanyRecord> | null | undefined,
  base: CompanyDetails = COMPANY_DETAILS,
): CompanyDetails {
  if (!r) return base
  const text = (v: string | undefined, fallback: string) => (v && v.trim() ? v.trim() : fallback)
  const phone = (v: string | undefined, fallback: CompanyDetails["phone"]) => {
    const display = (v ?? "").trim()
    const href = display ? telHref(display) : ""
    return href ? { display, href } : fallback
  }
  const socials = (Object.keys(SOCIAL_LABELS) as SocialKey[])
    .map((key) => ({ key, label: SOCIAL_LABELS[key], href: safeHttps(r[`${key}_url`]) }))
    .filter((s) => s.href !== "")
  return {
    ...base,
    legalName: text(r.legal_name, base.legalName),
    tradingName: text(r.trading_name, base.tradingName),
    companyNo: text(r.company_no, base.companyNo),
    registeredAddress: (r.registered_address ?? base.registeredAddress).trim(),
    phone: phone(r.phone, base.phone),
    urgentPhone: phone(r.urgent_phone, base.urgentPhone),
    email: text(r.email, base.email),
    officeHours: text(r.office_hours, base.officeHours),
    area: text(r.area, base.area),
    socials,
    whatsappHref: safeHttps(r.whatsapp_url),
  }
}

// ---- The live record, loaded once per page load -----------------------------
//
// One request, shared by every component that asks, and only in the browser.
// company.php answers with a five-minute cache header, which is how a change the
// owner saves reaches visitors within five minutes.

let current: CompanyDetails = COMPANY_DETAILS
let requested = false
const listeners = new Set<() => void>()

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  if (!requested && typeof window !== "undefined" && typeof fetch === "function") {
    requested = true
    fetch("/company.php", { headers: { Accept: "application/json" } })
      .then((res) => (res.ok ? res.json() : null))
      .then((body: { company?: CompanyRecord } | null) => {
        if (!body?.company) return
        current = companyFromRecord(body.company)
        listeners.forEach((l) => l())
      })
      .catch(() => {
        /* The bundled facts stay: the site must not break because the API is down. */
      })
  }
  return () => {
    listeners.delete(listener)
  }
}

/** The company details the website shows: the office app's record, or the bundled facts until it arrives. */
export function useCompanyDetails(): CompanyDetails {
  return useSyncExternalStore(subscribe, () => current, () => COMPANY_DETAILS)
}
