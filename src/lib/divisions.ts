// The four RitaGreen divisions. This single source drives the nav mega-menu,
// the services pages, the home page and the footer, so the whole public site
// re-skins from one place. RitaGreen is one master brand: every division shares
// the same name, colours and standard — only the descriptor word changes.
export type Service = {
  slug: string
  name: string
  summary: string
  points: string[]
}

export type Division = {
  id: string
  name: string
  descriptor: string // the single brand word: Care · Academy · Cleaning · Security
  accent: string // hex, used for the division dot/accent (brand palette)
  headline: string // the page-hero line, in brand voice
  tagline: string
  blurb: string
  intro: string // longer lead paragraph for the division section
  audience: string[] // who this division serves
  assurance: string[] // the compliance / "checked" backbone
  services: Service[]
}

export const DIVISIONS: Division[] = [
  {
    id: "care",
    name: "RitaGreen Care",
    descriptor: "Care",
    accent: "#0C447C",
    headline: "Safe hands when you need them.",
    tagline: "Trained, checked carers and nurses, when and where they are needed.",
    blurb:
      "We staff and run care across homes, supported living and the community. Every carer is DBS-checked, Care Certificate-trained and matched to the people they support.",
    intro:
      "When a carer calls in sick at 6am, you need cover you can trust — not a stranger. RitaGreen Care sends DBS-checked, Care Certificate-trained carers to residential and nursing homes, supported living and home-care providers across London, including nights and weekends. Temporary, permanent or temp-to-perm, every placement is held to one standard.",
    audience: [
      "Residential and nursing care homes",
      "Supported living and learning-disability services",
      "Domiciliary and home-care providers",
      "Families arranging care at home",
    ],
    assurance: [
      "Enhanced DBS, references and right-to-work on every carer before placement",
      "Care Certificate (16 standards) and mandatory training evidenced before the first shift",
      "Aligned to CQC expectations and Skills for Care frameworks",
      "Where we run managed care, we operate against a Statement of Purpose and recognised governance",
    ],
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
    name: "RitaGreen Academy",
    descriptor: "Academy",
    accent: "#B7791F",
    headline: "Start a career in care — and get hired.",
    tagline: "We train the next wave of carers, then help them into work.",
    blurb:
      "Our academy runs the Care Certificate and mandatory training, then guarantees strong learners an interview with RitaGreen Care. We don't just hire carers — we make them.",
    intro:
      "RitaGreen Academy trains the next wave of carers and gives us a pipeline of people we can place. Complete the Care Certificate and mandatory training with us, and strong learners get a guaranteed interview with RitaGreen Care. Content is mapped to Skills for Care guidance, with completion expected inside the recommended 12-week induction window.",
    audience: [
      "New starters and career changers entering care",
      "Employers upskilling existing staff",
      "Care providers needing oven-ready, certified recruits",
      "People wanting a real route into employment",
    ],
    assurance: [
      "Content mapped to Skills for Care guidance and the Care Certificate framework",
      "Completion expected inside the recommended 12-week induction window",
      "Certificates issued on assessment; training records retained for audit",
      "Guaranteed-interview route into RitaGreen Care for strong learners",
    ],
    services: [
      {
        slug: "health-social-care",
        name: "Care Certificate (16 standards)",
        summary: "The full Care Certificate for new starters, with assessor support.",
        points: [
          "All 16 Care Certificate standards for new starters",
          "Classroom and blended delivery",
          "Assessor observation and sign-off",
          "Verifiable certificates on completion",
        ],
      },
      {
        slug: "mandatory-training",
        name: "Mandatory & CSTF training",
        summary: "Moving and handling, safeguarding, first aid, medication awareness and more.",
        points: [
          "Aligned to the Core Skills Training Framework subjects",
          "Moving & handling, basic life support, first aid, safeguarding",
          "Refreshers tracked to expiry",
          "Group bookings for employers",
        ],
      },
      {
        slug: "cpd-courses",
        name: "Refresher & top-up courses",
        summary: "Focused sessions that keep teams current and count toward CPD.",
        points: [
          "Short, practical sessions for working staff",
          "CPD hours logged automatically",
          "Delivered on site or online",
        ],
      },
    ],
  },
  {
    id: "cleaning",
    name: "RitaGreen Cleaning",
    descriptor: "Cleaning",
    accent: "#5DCAA5",
    headline: "Clean, safe, presentable — every time.",
    tagline: "Buildings kept clean, safe and inspection-ready.",
    blurb:
      "We clean offices, communal blocks, care settings and void properties. COSHH records, method statements and risk assessments come as standard, not as an afterthought.",
    intro:
      "RitaGreen Cleaning keeps offices, communal areas and care settings spotless and safe. From daily contract cleaning to end-of-tenancy turnarounds and infection control, we turn up and do it properly — to recognised industry standards with full COSHH control of every chemical we use.",
    audience: [
      "Landlords and managing agents",
      "Offices and commercial premises",
      "Care and clinical settings",
      "Letting agents needing void and end-of-tenancy cleans",
    ],
    assurance: [
      "Work follows recognised industry cleaning standards",
      "Documented COSHH assessments for every chemical used",
      "Operatives trained, insured and DBS-checked where they work in care or resident settings",
      "Method statements and risk assessments supplied per site",
    ],
    services: [
      {
        slug: "commercial-cleaning",
        name: "Commercial & contract cleaning",
        summary: "Daily and periodic cleaning for offices and commercial space.",
        points: [
          "Daily, weekly or periodic schedules",
          "COSHH records held per site",
          "Supervisor spot-checks on quality",
        ],
      },
      {
        slug: "care-environment-cleaning",
        name: "Care & infection-control cleaning",
        summary: "Infection-control cleaning for care and clinical settings.",
        points: [
          "Infection-control aware cleaning",
          "Colour-coded systems and audited rounds",
          "Ready for CQC and environmental inspection",
        ],
      },
      {
        slug: "communal-end-of-tenancy",
        name: "Communal, void & end-of-tenancy",
        summary: "Block communal areas and turnaround cleans for landlords.",
        points: [
          "Communal areas for landlords and managing agents",
          "End-of-tenancy and void-property turnarounds",
          "Before and after photo evidence",
        ],
      },
    ],
  },
  {
    id: "security",
    name: "RitaGreen Security",
    descriptor: "Security",
    accent: "#1B2A3C",
    headline: "Licensed officers who keep it calm.",
    tagline: "SIA-licensed guards for the places people live, work and gather.",
    blurb:
      "We guard buildings, sites and events. Every officer is SIA-licensed and vetted to BS 7858 principles, with licence validity checked before they are ever scheduled.",
    intro:
      "RitaGreen Security protects the places people live, work and gather. Our SIA-licensed officers handle static guarding, door supervision, mobile patrols and events — professionally and quietly. We vet to BS 7858 principles, brief officers per assignment, and work towards SIA Approved Contractor Scheme standards.",
    audience: [
      "Landlords, managing agents and residential blocks",
      "Event organisers and licensed venues",
      "Construction sites and void properties",
      "Retail and commercial premises",
    ],
    assurance: [
      "Every officer holds a valid SIA licence for their role, verified before dispatch",
      "Vetted to BS 7858 principles with verified right to work",
      "Working towards SIA Approved Contractor Scheme standards",
      "Assignment instructions and patrol logs maintained for every site",
    ],
    services: [
      {
        slug: "manned-guarding",
        name: "Manned guarding & patrols",
        summary: "Static and patrol officers for sites and premises.",
        points: [
          "Static manned guarding and mobile patrol cover",
          "Key-holding and alarm response",
          "SIA licences verified before dispatch",
          "GPS-verified check-ins on shift",
        ],
      },
      {
        slug: "event-security",
        name: "Event & door supervision",
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
