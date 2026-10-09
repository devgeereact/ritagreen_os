// Company facts for the public site, the office app's Help page and PDFs.
//
// Two shapes live here.
//
// COMPANY is the flat object older code reads (Help, the PDF footer, the bare
// layout). Keep its keys.
//
// CompanyDetails, read through useCompanyDetails(), is what the website reads.
// It is structured the way the CMS will hold it in section S7 (company details
// and social links managed in the office app), so that swap is one change: point
// useCompanyDetails() at the API and keep COMPANY_DETAILS as the fallback. The
// components that render the header, footer, contact page and structured data
// never read COMPANY directly, so they will not need touching.
//
// Facts marked [confirm] in docs/Plans 2026/01-BUSINESS-AND-SCOPE.md (the urgent
// line, the mailbox, office hours, the area) are already published on the site and
// stay as they are until the owner confirms or changes them in S7. A fact that
// is not known (the registered address, the ICO reference) is empty, and every
// reader leaves it out rather than printing a placeholder.

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

export type SocialKey = "instagram" | "facebook" | "linkedin"

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
  socials: { key: SocialKey; label: string; href: string }[]
}

// Social profiles. X (Twitter) is not run, so it is not listed (2026 plan,
// file 07). The handles are the existing accounts; file 07 plans a rename, which
// S7 will make editable in the CMS.
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
}

/**
 * The company details the website shows.
 *
 * A hook, not a constant, so that S7 can load them from the CMS without every
 * caller changing. Today it returns the bundled facts synchronously.
 */
export function useCompanyDetails(): CompanyDetails {
  return COMPANY_DETAILS
}
