// Single source of truth for RitaGreen company facts and brand copy used across
// the public corporate site. Keep wording in brand voice: warm, clear, plain,
// accountable. Lead with reassurance, never with adjectives.

export const COMPANY = {
  legalName: "RitaGreen Services Ltd",
  shortName: "RitaGreen",
  companyNo: "12528439",
  promise:
    "One trusted name for staff, training, cleaning and security — every worker qualified, checked and ready.",
  primaryTagline: "Qualified. Checked. Ready.",
  heroLine: "People you can count on, every part of the day.",
  area: "London and surrounding boroughs",
  phone: "0203 105 2218",
  phoneHref: "tel:+442031052218",
  emergencyPhone: "+44 (0)7377 563859",
  emergencyPhoneHref: "tel:+447377563859",
  email: "hello@ritagreenservices.com",
  hours: "Mon–Fri, 9am–5pm · 24hr line for urgent cover",
  registeredAddress: "London, United Kingdom", // TODO: set full registered office address
} as const

// Billing details rendered on invoice/quote PDFs. FILL THESE before sending real
// invoices — a UK VAT invoice must show the VAT number, and clients pay against
// the bank details. Leave a value as "" to hide that line on the PDF.
export const BILLING = {
  vatNumber: "", // e.g. "GB 123 4567 89" — leave "" if not VAT registered
  bankName: "", // e.g. "Barclays"
  accountName: "RitaGreen Services Ltd",
  sortCode: "", // e.g. "20-00-00"
  accountNumber: "", // e.g. "12345678"
  paymentTermsDays: 14,
} as const

// Social profiles — all @ritagreenservices__
export const SOCIALS = [
  { key: "instagram", label: "Instagram", href: "https://instagram.com/ritagreenservices__" },
  { key: "facebook", label: "Facebook", href: "https://facebook.com/ritagreenservices__" },
  { key: "linkedin", label: "LinkedIn", href: "https://linkedin.com/company/ritagreenservices__" },
  { key: "x", label: "X", href: "https://x.com/ritagreenservices__" },
] as const

// Brand pillars — the four things that make the model work.
export const PILLARS = [
  {
    key: "checked",
    title: "Checked",
    body: "Every RitaGreen worker is DBS-checked, qualified for the role, and right-to-work verified before a shift. We say it because we do it.",
  },
  {
    key: "whole-day",
    title: "Whole-day",
    body: "We look after people across the whole day — the carer at 7am, the cleaner at noon, the guard overnight. One supplier, one standard.",
  },
  {
    key: "trained",
    title: "Trained",
    body: "Our academy means we don't just hire carers, we make them. Training runs through everything we do.",
  },
  {
    key: "dependable",
    title: "Dependable",
    body: "We're a London company that turns up, picks up the phone, and stands behind the people we send.",
  },
] as const

// "Why RitaGreen" — three short reasons for the home and about pages.
export const WHY = [
  {
    title: "Checked first",
    body: "Enhanced DBS, references and right-to-work on everyone, before they start. No exceptions, no shortcuts.",
  },
  {
    title: "One name",
    body: "Care, training, cleaning and security from one contact, one invoice and one standard — instead of juggling four suppliers.",
  },
  {
    title: "We train our own",
    body: "Our academy trains the carers we then place, so we know exactly who we send and how they were taught.",
  },
] as const

// How we work — the engagement journey.
export const HOW_WE_WORK = [
  {
    step: "01",
    title: "Tell us what you need",
    body: "A short conversation about what you need, when and where. One enquiry reaches the right division.",
  },
  {
    step: "02",
    title: "We confirm it in writing",
    body: "We confirm scope and rates in writing, so there are no surprises on the invoice.",
  },
  {
    step: "03",
    title: "We vet and brief the team",
    body: "We vet and brief the right people for the role, and give you a named contact who stays with the account.",
  },
  {
    step: "04",
    title: "We keep cover steady",
    body: "After the first shifts we check in, fix anything that needs fixing, and keep the cover reliable.",
  },
] as const

// Engagement models — "buying from RitaGreen".
export const ENGAGEMENTS = [
  {
    name: "Ad-hoc / short notice",
    bestFor: "One-off or emergency cover",
    terms: "Per-shift or per-visit rate, confirmed in writing",
  },
  {
    name: "Block booking",
    bestFor: "Predictable, recurring needs",
    terms: "Agreed weekly hours, reviewed monthly",
  },
  {
    name: "Managed contract",
    bestFor: "Ongoing service across a site or portfolio",
    terms: "Fixed monthly fee, named account manager, SLA",
  },
  {
    name: "Permanent placement",
    bestFor: "Filling a substantive role",
    terms: "One-off placement fee with a rebate period",
  },
] as const

// Headline proof stats for the home page.
export const STATS = [
  { n: "4", l: "Divisions under one trusted name" },
  { n: "100%", l: "Staff DBS-checked before their first shift" },
  { n: "16", l: "Care Certificate standards evidenced" },
  { n: "London", l: "Wide coverage across the capital" },
] as const

// Standards & assurance — the compliance backbone.
export const ASSURANCE = [
  {
    title: "Care",
    body: "Care delivered against the Care Certificate (16 standards), aligned to CQC expectations and Skills for Care frameworks.",
  },
  {
    title: "Security",
    body: "Officers hold valid SIA licences for their role; we vet to BS 7858 principles and work towards Approved Contractor Scheme standards.",
  },
  {
    title: "Cleaning",
    body: "Cleaning follows recognised industry standards with full COSHH control of every substance used.",
  },
  {
    title: "Vetting",
    body: "Enhanced DBS, reference checks and right-to-work verification on every worker, refreshed on schedule.",
  },
  {
    title: "Insurance",
    body: "Public liability and employers' liability insurance (min £5m) in place; certificates available on request.",
  },
  {
    title: "Data",
    body: "Client and worker records handled under UK GDPR and the Data Protection Act 2018, with clear retention and access controls.",
  },
] as const

// FAQs reused on home and contact.
export const FAQS = [
  {
    q: "How quickly can you cover a shift?",
    a: "For care, cleaning and security we can often confirm cover the same day. The fastest way is to call us — tell us the role, the site and the times, and we'll have the right person ready.",
  },
  {
    q: "How do you check the people you send?",
    a: "Before anyone steps on your site they're enhanced-DBS checked, right-to-work verified and referenced. Carers are Care Certificate-trained; security officers hold a valid SIA licence for the role. We check licence validity before every assignment.",
  },
  {
    q: "Can I use one contract for all four services?",
    a: "Yes — that's the whole idea. Care, training, cleaning and security come from one supplier, one named contact and one invoice, to one standard. Most clients start with one service and grow the account sideways.",
  },
  {
    q: "Do you train your own carers?",
    a: "We do. RitaGreen Academy runs the Care Certificate and mandatory training, and strong learners get a guaranteed interview with RitaGreen Care — so we know exactly who we're sending and how they were taught.",
  },
  {
    q: "Which areas do you cover?",
    a: "We're a London business covering the capital and surrounding boroughs. Tell us your site and we'll confirm we can reach it.",
  },
  {
    q: "Are you insured?",
    a: "Yes. We hold public liability and employers' liability insurance (minimum £5m). Certificates and cover limits are available on request.",
  },
] as const
