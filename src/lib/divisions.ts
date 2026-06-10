// The four RitaGreen divisions. This single source drives the nav mega-menu,
// the services pages, the home page and the footer, so the whole public site
// re-skins from one place.
export type Service = {
  slug: string
  name: string
  summary: string
  points: string[]
}

export type Division = {
  id: string
  name: string
  accent: string // hex, used for the division dot/accent
  tagline: string
  blurb: string
  services: Service[]
}

export const DIVISIONS: Division[] = [
  {
    id: "care",
    name: "Healthcare & Care",
    accent: "#0EA5E9",
    tagline: "Trained, checked carers and nurses, when and where they are needed.",
    blurb:
      "We staff and run care across homes, supported living and the community. Every carer is DBS-checked, trained and matched to the people they support.",
    services: [
      {
        slug: "domiciliary-care",
        name: "Domiciliary care",
        summary: "Care visits in people's own homes, from personal care to medication support.",
        points: [
          "Personal care, meals and medication support",
          "Regular carers who know the people they visit",
          "Care plans reviewed with families and commissioners",
          "Electronic visit logs for every call",
        ],
      },
      {
        slug: "care-home-staffing",
        name: "Care home staffing",
        summary: "Carers and nurses to cover shifts, sickness and short-notice gaps.",
        points: [
          "Day, night and waking-night cover",
          "Compliance checked before every first shift",
          "Block bookings and ongoing rota cover",
          "Cover confirmed fast when a shift falls through",
        ],
      },
      {
        slug: "supported-living",
        name: "Supported living",
        summary: "Support workers for people living independently with the right help in place.",
        points: [
          "Support matched to each person's plan",
          "Consistent teams, not a rotating cast",
          "Outcomes tracked and shared with funders",
        ],
      },
    ],
  },
  {
    id: "academy",
    name: "Academy & Training",
    accent: "#7C3AED",
    tagline: "We train the next wave of carers, then help them into work.",
    blurb:
      "Our academy runs accredited Health & Social Care courses and mandatory training. Top learners flow straight into roles across the group.",
    services: [
      {
        slug: "health-social-care",
        name: "Health & Social Care (Levels 2–5)",
        summary: "Accredited diplomas with assessor support and work placements.",
        points: [
          "Levels 2 to 5, classroom and blended",
          "NVQ assessor observation and sign-off",
          "Verifiable certificates on completion",
          "A route into care roles across RitaGreen",
        ],
      },
      {
        slug: "mandatory-training",
        name: "Mandatory & CSTF training",
        summary: "Care Certificate, moving and handling, safeguarding and more.",
        points: [
          "Care Certificate and statutory/mandatory training",
          "Refreshers tracked to expiry",
          "Group bookings for employers",
        ],
      },
      {
        slug: "cpd-courses",
        name: "CPD short courses",
        summary: "Focused courses that keep teams current and count toward CPD.",
        points: [
          "Short, practical sessions",
          "CPD hours logged automatically",
          "Delivered on site or online",
        ],
      },
    ],
  },
  {
    id: "cleaning",
    name: "Cleaning Services",
    accent: "#0891B2",
    tagline: "Buildings kept clean, safe and inspection-ready.",
    blurb:
      "We clean care homes, offices, communal blocks and event venues. COSHH records and risk assessments come as standard, not as an afterthought.",
    services: [
      {
        slug: "commercial-cleaning",
        name: "Commercial cleaning",
        summary: "Daily and periodic cleaning for offices and commercial space.",
        points: [
          "Daily, weekly or periodic schedules",
          "COSHH records held per site",
          "Supervisor spot-checks on quality",
        ],
      },
      {
        slug: "care-environment-cleaning",
        name: "Care environment cleaning",
        summary: "Infection-control cleaning for care and clinical settings.",
        points: [
          "Infection-control aware cleaning",
          "Colour-coded systems and audited rounds",
          "Ready for CQC and environmental inspection",
        ],
      },
      {
        slug: "communal-end-of-tenancy",
        name: "Communal & end-of-tenancy",
        summary: "Block communal areas and turnaround cleans for landlords.",
        points: [
          "Communal areas for landlords and managing agents",
          "End-of-tenancy turnarounds",
          "Before and after photo evidence",
        ],
      },
    ],
  },
  {
    id: "security",
    name: "Security Services",
    accent: "#334155",
    tagline: "SIA-licensed guards for the places people live, work and gather.",
    blurb:
      "We guard buildings, sites and events. Every officer is SIA-licensed, and licence validity is checked before they are ever scheduled.",
    services: [
      {
        slug: "manned-guarding",
        name: "Manned guarding",
        summary: "Static and patrol officers for sites and premises.",
        points: [
          "Static and mobile patrol cover",
          "SIA licences verified before dispatch",
          "GPS-verified check-ins on shift",
        ],
      },
      {
        slug: "event-security",
        name: "Event security",
        summary: "Stewards and door supervisors for events of any size.",
        points: [
          "Door supervision and crowd stewarding",
          "Scaled to the event and risk assessment",
          "Briefed teams with a named lead",
        ],
      },
      {
        slug: "concierge",
        name: "Concierge & front of house",
        summary: "Front-of-house cover for residential and commercial buildings.",
        points: [
          "Residential and commercial concierge",
          "Access control and visitor management",
          "A calm, professional first point of contact",
        ],
      },
    ],
  },
]

export function findService(slug: string): { division: Division; service: Service } | null {
  for (const division of DIVISIONS) {
    const service = division.services.find((s) => s.slug === slug)
    if (service) return { division, service }
  }
  return null
}

export function findDivision(id: string): Division | null {
  return DIVISIONS.find((d) => d.id === id) ?? null
}
