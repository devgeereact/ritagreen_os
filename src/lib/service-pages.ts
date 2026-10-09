// Content for the six service pages. Every page follows one pattern (2026 plan,
// file 05, "Service page pattern"): opening, what we provide, how it works,
// checks and training, who we work with, questions, then a focused enquiry form.
// src/pages/public/ServicePage.tsx draws them; this file is only words.
//
// Facts come from docs/Plans 2026/01-BUSINESS-AND-SCOPE.md. Anything that file
// marks [confirm] is not stated here: no regulator registration for supported
// living or home care, no response times, no fixed course list, no insurance
// figures. The checks listed under each service are the ones the office system
// records and the deployment gate enforces before somebody is booked.
//
// Replaces src/lib/divisions.ts, the previous site's copy, which used the
// retired "RitaGreen Care" and "RitaGreen Academy" names and claims the plan
// does not support (a guaranteed interview, alignment statements, GPS check-ins).

import type { ServiceId } from "@/lib/services"
import { SITE_IMAGES, type SiteImage } from "@/lib/site-images"

export type EnquiryKind = "staff" | "cleaning" | "care" | "training"

export type ServicePageContent = {
  path: string
  service: ServiceId
  /** Breadcrumb parent, for the two healthcare sub-pages. */
  parent?: { to: string; label: string }
  breadcrumb: string
  seoTitle: string
  seoDescription: string
  /** schema.org Service.serviceType */
  serviceType: string
  eyebrow: string
  title: string
  opening: string
  image: SiteImage
  /** Optional routes shown under the opening (the healthcare overview's two paths). */
  routes?: { to: string; title: string; body: string }[]
  provideTitle: string
  provide: string[]
  steps: { title: string; body: string }[]
  checksTitle: string
  checksIntro: string
  checks: string[]
  sectors: string[]
  faqs: { q: string; a: string }[]
  enquiry: { kind: EnquiryKind; title: string; intro: string }
}

const HEALTHCARE_CHECKS = [
  "Right to work in the UK, checked and recorded",
  "Enhanced DBS for the adult or child workforce, as the role requires",
  "References from previous employers",
  "Care Certificate or equivalent, and mandatory training in date",
  "NMC registration checked for every nurse",
]

const HEALTHCARE_STEPS = [
  {
    title: "Tell us what you need",
    body: "Call us or send the form with the site, dates, shifts and skills you need.",
  },
  {
    title: "We match checked staff",
    body: "We only offer the shift to people whose checks and training are in date.",
  },
  {
    title: "They arrive briefed",
    body: "Staff get the site notes, a contact name and the start time before they travel.",
  },
]

export const SERVICE_PAGES: Record<string, ServicePageContent> = {
  healthcare: {
    path: "/healthcare",
    service: "care",
    breadcrumb: "Healthcare",
    seoTitle: "Healthcare staffing in London: carers, support workers and nurses",
    seoDescription:
      "Carers, support workers, senior carers and nurses for care homes, hospitals and supported living across London. Every worker's checks and training are tracked before they are booked.",
    serviceType: "Healthcare staffing",
    eyebrow: "Healthcare",
    title: "Care staff for the shifts your rota cannot cover",
    opening:
      "We supply carers, support workers, senior carers and nurses to care homes, hospitals, day centres and supported living services across London. Every worker's checks and training are tracked in our system, and nobody is booked while something is missing or out of date.",
    image: SITE_IMAGES.care,
    routes: [
      {
        to: "/healthcare/staffing",
        title: "For care providers",
        body: "Planned cover and short-notice gaps for care homes, hospitals and day centres.",
      },
      {
        to: "/healthcare/supported-living",
        title: "For people and families",
        body: "Supported living and support at home. Start with a conversation.",
      },
    ],
    provideTitle: "Who we supply",
    provide: [
      "Care assistants and healthcare assistants",
      "Support workers",
      "Senior carers",
      "Registered nurses (RGN and RMN)",
      "Day, night and waking night shifts",
      "Single shifts, block bookings and regular cover",
    ],
    steps: HEALTHCARE_STEPS,
    checksTitle: "Checks and training",
    checksIntro: "Before anyone is offered a healthcare shift, our system holds a current record of:",
    checks: HEALTHCARE_CHECKS,
    sectors: [
      "Residential and nursing care homes",
      "NHS and private hospitals",
      "Day centres",
      "Supported living providers",
    ],
    faqs: [
      {
        q: "Can you cover a shift at short notice?",
        a: "Often, yes. Call us with the role, the site and the times, and we will tell you on the call whether we can fill it. Outside office hours, use the urgent cover line.",
      },
      {
        q: "Which roles can you supply?",
        a: "Care assistants, healthcare assistants, support workers, senior carers and registered nurses (RGN and RMN).",
      },
      {
        q: "How do you check the staff you send?",
        a: "Right to work, an enhanced DBS at the level the role needs, references, the Care Certificate or equivalent and mandatory training. For nurses we also check NMC registration. Our system will not book someone whose checks are missing or out of date.",
      },
      {
        q: "Can we have the same staff each time?",
        a: "Tell us who has worked well for you and we will offer them your shifts first, whenever they are available.",
      },
      {
        q: "Which areas do you cover?",
        a: "London and the surrounding boroughs. Tell us the site and we will confirm we can reach it.",
      },
    ],
    enquiry: {
      kind: "staff",
      title: "Request healthcare staff",
      intro: "Tell us about the shift. The more you can tell us, the faster we can confirm.",
    },
  },

  "healthcare/staffing": {
    path: "/healthcare/staffing",
    service: "care",
    parent: { to: "/healthcare", label: "Healthcare" },
    breadcrumb: "Staffing",
    seoTitle: "Care home and hospital staffing in London",
    seoDescription:
      "Carers, support workers and nurses for care homes, nursing homes, hospitals and day centres across London. Planned cover and short-notice shifts, with checks tracked before every booking.",
    serviceType: "Care home and hospital staffing",
    eyebrow: "Healthcare staffing",
    title: "Keep your rota covered, from planned leave to the call at 6am",
    opening:
      "For care homes, nursing homes, hospitals and day centres that need carers, support workers or nurses. We fill planned gaps and short-notice shifts with staff whose checks and training are in date.",
    image: SITE_IMAGES.care,
    provideTitle: "What we provide",
    provide: [
      "Single shifts and block bookings",
      "Day, night and waking night cover",
      "Care assistants, support workers and senior carers",
      "Registered nurses (RGN and RMN)",
      "The same staff for repeat bookings, where they are available",
      "One office contact for every booking",
    ],
    steps: [
      {
        title: "Send the shift",
        body: "The role, the site, the times and any skills it needs, by the form below or by phone.",
      },
      {
        title: "We match and confirm",
        body: "We offer it only to people whose checks are in date, then confirm the name to you.",
      },
      {
        title: "They arrive briefed",
        body: "Staff have the site notes, who to ask for and the start time before they travel.",
      },
    ],
    checksTitle: "Checks and training",
    checksIntro: "Every carer, support worker and nurse we book has a current record of:",
    checks: HEALTHCARE_CHECKS,
    sectors: [
      "Residential and nursing care homes",
      "NHS and private hospitals",
      "Day centres",
      "Supported living services",
    ],
    faqs: [
      {
        q: "Can you cover a shift at short notice?",
        a: "Often, yes. Call us with the role, the site and the times, and we will tell you on the call whether we can fill it. Outside office hours, use the urgent cover line.",
      },
      {
        q: "Can we book regular staff?",
        a: "Yes. Tell us who has worked well for you and we will offer them your shifts first, whenever they are available.",
      },
      {
        q: "Do your nurses hold a current NMC registration?",
        a: "Every nurse's NMC registration is checked before they are booked, and its renewal date is tracked.",
      },
      {
        q: "What if a worker is not right for our service?",
        a: "Tell the office the same day. We will talk it through with you and take it into account for future bookings.",
      },
      {
        q: "Do you supply supported living services?",
        a: "Yes. Support workers for supported living are booked the same way as any other shift.",
      },
    ],
    enquiry: {
      kind: "staff",
      title: "Request healthcare staff",
      intro: "Tell us about the shift. The more you can tell us, the faster we can confirm.",
    },
  },

  "healthcare/supported-living": {
    path: "/healthcare/supported-living",
    service: "care",
    parent: { to: "/healthcare", label: "Healthcare" },
    breadcrumb: "Supported living",
    seoTitle: "Supported living and support at home in London",
    seoDescription:
      "Talk to us about supported living and support at home in London. Tell us what would help and we will call you at a time that suits you.",
    serviceType: "Supported living staffing and enquiries",
    eyebrow: "Supported living and support at home",
    title: "Start with a conversation about the support you or your family member needs",
    opening:
      "For people living independently, their families, and the services that support them. Tell us a little about what would help, and we will call you to talk it through and be clear about what we can arrange.",
    image: SITE_IMAGES.care,
    provideTitle: "What we can talk about",
    provide: [
      "Support workers for supported living services",
      "A first conversation for families thinking about support at home",
      "Straight answers about what we can and cannot arrange",
      "A named contact in our office",
    ],
    steps: [
      {
        title: "Tell us a little",
        body: "Who the support is for and the kind of help you are thinking about. No medical details.",
      },
      {
        title: "We call you",
        body: "At the time you choose, to listen and to answer your questions.",
      },
      {
        title: "We agree next steps",
        body: "If we can help, we confirm what happens next in writing. If we cannot, we say so.",
      },
    ],
    checksTitle: "Checks and training",
    checksIntro: "Every support worker we book has a current record of:",
    checks: [
      "Right to work in the UK, checked and recorded",
      "Enhanced DBS at the level the role requires",
      "References from previous employers",
      "Care Certificate or equivalent, and mandatory training in date",
    ],
    sectors: [
      "Supported living providers",
      "Families arranging support",
      "Social workers and care coordinators",
    ],
    faqs: [
      {
        q: "Do I need to know exactly what support is needed?",
        a: "No. Many people call before they know. We will ask a few questions and help you work out what to look for.",
      },
      {
        q: "Why does the form not ask about health needs?",
        a: "Health information is sensitive, so we keep it off a website form. We talk it through on the phone and only record what is needed.",
      },
      {
        q: "Can you supply support workers to our supported living service?",
        a: "Yes. That is part of our healthcare staffing: use the request form on the staffing page or call the office.",
      },
      {
        q: "Who will I speak to?",
        a: "Someone from our office, at the time you choose on the form. If that time no longer suits, reply to our email and we will rearrange.",
      },
    ],
    enquiry: {
      kind: "care",
      title: "Ask us to call you",
      intro: "A few details so the right person calls you. Please leave out medical information.",
    },
  },

  cleaning: {
    path: "/cleaning",
    service: "cleaning",
    breadcrumb: "Cleaning",
    seoTitle: "Cleaning for landlords, schools, sites and offices in London",
    seoDescription:
      "Regular and one-off cleaning for landlords and letting agents, schools, construction sites, offices and care settings across London. Ask for a quote online.",
    serviceType: "Commercial cleaning",
    eyebrow: "Cleaning",
    title: "A clear cleaning plan for the places you manage, agreed in writing",
    opening:
      "For landlords and letting agents, schools, construction sites, offices and care settings. We supply regular and one-off cleaning with trained cleaners and a supervisor you can reach.",
    image: SITE_IMAGES.cleaning,
    provideTitle: "What we provide",
    provide: [
      "Regular cleaning for offices and commercial premises",
      "Communal areas for residential blocks",
      "Void property and end of tenancy cleans",
      "Builders' cleans on construction sites",
      "Infection control cleaning in care settings",
      "School cleaning, with DBS checked cleaners",
    ],
    steps: [
      {
        title: "Tell us about the site",
        body: "The type of site, its size and how often you need us, using the quote form below.",
      },
      {
        title: "We agree a plan and a price",
        body: "What is cleaned, how often and what it costs, confirmed in writing before we start.",
      },
      {
        title: "We clean to the plan",
        body: "A regular team, and a supervisor you can reach if anything needs putting right.",
      },
    ],
    checksTitle: "Checks and training",
    checksIntro: "Every cleaner we send has a current record of:",
    checks: [
      "Right to work in the UK, checked and recorded",
      "References from previous employers",
      "COSHH awareness, for the safe use of cleaning products",
      "A DBS check where the site requires one, such as schools and care settings",
    ],
    sectors: [
      "Landlords and letting agents",
      "Schools",
      "Construction sites",
      "Offices and commercial premises",
      "Care settings",
    ],
    faqs: [
      {
        q: "Do you do one-off cleans?",
        a: "Yes. End of tenancy, void property and builders' cleans as well as regular contracts.",
      },
      {
        q: "How do you price a job?",
        a: "From the type of site, its size and how often you need us. We confirm the price in writing before any work starts.",
      },
      {
        q: "Are your cleaners DBS checked?",
        a: "Where the site requires it, for example schools and care settings, yes. Every cleaner has their right to work and references checked.",
      },
      {
        q: "Can you clean care settings?",
        a: "Yes, including infection control cleaning. Tell us about the setting on the quote form.",
      },
    ],
    enquiry: {
      kind: "cleaning",
      title: "Ask for a cleaning quote",
      intro: "Tell us about the site. We will come back to you before we put a price in writing.",
    },
  },

  security: {
    path: "/security",
    service: "security",
    breadcrumb: "Security",
    seoTitle: "SIA licensed security officers in London",
    seoDescription:
      "Security officers, door supervisors and concierge staff for companies, sites, events and residential blocks across London. SIA licences checked before every booking.",
    serviceType: "Security staffing",
    eyebrow: "Security",
    title: "Licensed officers who know your site before their first shift",
    opening:
      "For companies, sites, events and residential blocks. We supply SIA licensed security officers and door supervisors, briefed on your site before they arrive.",
    image: SITE_IMAGES.security,
    provideTitle: "What we provide",
    provide: [
      "Static guarding",
      "Mobile patrols",
      "Door supervision",
      "Concierge and front of house",
      "Security for events",
      "Supervisors for larger teams",
    ],
    steps: [
      {
        title: "Tell us about the site",
        body: "The site, the hours and what the officers need to do, by the form below or by phone.",
      },
      {
        title: "We match licensed officers",
        body: "Each officer's SIA licence is the right type for the role and is checked before they are booked.",
      },
      {
        title: "They arrive briefed",
        body: "Officers have the site instructions and a contact name before their first shift.",
      },
    ],
    checksTitle: "Checks and licences",
    checksIntro: "Every officer we book has a current record of:",
    checks: [
      "Right to work in the UK, checked and recorded",
      "An SIA licence of the correct type, checked against the public register",
      "References from previous employers",
      "First aid training where the role requires it",
    ],
    sectors: [
      "Companies and offices",
      "Construction and void sites",
      "Events and venues",
      "Residential blocks",
    ],
    faqs: [
      {
        q: "Which SIA licences do your officers hold?",
        a: "Each officer holds the licence their role needs: security guarding for static and patrol work, door supervision for licensed venues and events. We check it against the SIA public register before booking.",
      },
      {
        q: "What happens if an officer's licence expires?",
        a: "Our system tracks every licence expiry date and will not book an officer whose licence has lapsed.",
      },
      {
        q: "Can you provide security for a one-off event?",
        a: "Yes. Tell us the date, the venue and the expected numbers, and we will come back with what the event needs.",
      },
      {
        q: "Which areas do you cover?",
        a: "London and the surrounding boroughs. Tell us the site and we will confirm we can reach it.",
      },
    ],
    enquiry: {
      kind: "staff",
      title: "Request security staff",
      intro: "Tell us about the site and the hours you need covered.",
    },
  },

  training: {
    path: "/training",
    service: "academy",
    breadcrumb: "Training",
    seoTitle: "Care training in London: Care Certificate, mandatory and refresher courses",
    seoDescription:
      "Care Certificate, mandatory and refresher training for care teams and individuals in London, with attendance and certificates on record. Check a certificate online.",
    serviceType: "Care training",
    eyebrow: "Training",
    title: "Practical training for care teams, with records you can rely on",
    opening:
      "For care providers, their teams and people starting out in care. Courses are run in person, and we keep attendance, certificates and renewal dates on record.",
    image: SITE_IMAGES.training,
    provideTitle: "Training we can arrange",
    provide: [
      "The Care Certificate",
      "Mandatory training for care teams",
      "Refresher training before certificates expire",
      "Group bookings for employers",
      "Certificates with an ID anyone can check online",
    ],
    steps: [
      {
        title: "Tell us what you need",
        body: "The course, how many people and roughly when, using the form below.",
      },
      {
        title: "We confirm the course",
        body: "Dates, place and cost, confirmed in writing.",
      },
      {
        title: "Records are kept",
        body: "Attendance and certificates are recorded, and renewal dates are tracked.",
      },
    ],
    checksTitle: "Records you can rely on",
    checksIntro: "For every course we run, we keep:",
    checks: [
      "Attendance for every session",
      "A certificate with an ID that anyone can check on this website",
      "The renewal date, so refreshers are not missed",
      "Our trainers' own qualifications, on file",
    ],
    sectors: [
      "Care homes and nursing homes",
      "Supported living and home care providers",
      "People starting a career in care",
      "RitaGreen's own staff",
    ],
    faqs: [
      {
        q: "Which courses do you run?",
        a: "The Care Certificate, mandatory training and refreshers for care teams. Ask us for the current list and dates.",
      },
      {
        q: "Can we book a course for our whole team?",
        a: "Group bookings are welcome. Tell us the numbers and the dates that suit you, and we will tell you what we can arrange.",
      },
      {
        q: "Do you offer online courses?",
        a: "No. Our courses are run in person.",
      },
      {
        q: "How do I check a certificate is genuine?",
        a: "Enter the certificate ID on our certificate check page. It shows the name, the course and the date it was issued.",
      },
      {
        q: "Can individuals book?",
        a: "Yes. Send an enquiry with the course you are interested in and we will tell you the next dates.",
      },
    ],
    enquiry: {
      kind: "training",
      title: "Ask about training",
      intro: "Tell us what you need and we will come back with dates.",
    },
  },
}

/** The four services in navigation order, for the footer and the home page. */
export const SERVICE_PAGE_LINKS = [
  { to: "/healthcare", label: "Healthcare", service: "care" },
  { to: "/cleaning", label: "Cleaning", service: "cleaning" },
  { to: "/security", label: "Security", service: "security" },
  { to: "/training", label: "Training", service: "academy" },
] as const satisfies readonly { to: string; label: string; service: ServiceId }[]
