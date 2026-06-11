// Resource articles for the public site. A later pass moves these to a
// read-only PHP catalogue feed so the office can publish from the CMS.
export type Article = {
  slug: string
  title: string
  excerpt: string
  category: string
  date: string
  readMins: number
  body: string[]
}

export const ARTICLES: Article[] = [
  {
    slug: "what-to-look-for-in-a-care-provider",
    title: "What to look for in a care provider",
    excerpt: "Five practical checks before you choose who supports a loved one at home.",
    category: "Care",
    date: "2026-05-12",
    readMins: 4,
    body: [
      "Choosing a care provider is a decision families rarely make twice, and usually under pressure. A few checks make the difference between a provider that turns up and one that genuinely fits.",
      "Ask how carers are matched. Consistency matters more than almost anything else: the same faces, who know the person and the routine, beat a rotating cast every time.",
      "Ask how visits are recorded. Electronic visit logs mean families and funders can see what happened on each call, not just trust that it did.",
      "Ask about training. A carer trained against the Care Certificate's 16 standards arrives knowing the basics — safeguarding, moving and handling, medication awareness — rather than learning them on your relative.",
      "Finally, ask about compliance. DBS checks, training and the right to work should all be confirmed before a carer's first visit, not chased afterwards.",
    ],
  },
  {
    slug: "why-sia-licence-checks-matter",
    title: "Why SIA licence checks matter",
    excerpt: "An out-of-date licence is not a paperwork problem. It is a liability.",
    category: "Security",
    date: "2026-04-28",
    readMins: 3,
    body: [
      "Every front-line security officer in the UK must hold a valid Security Industry Authority licence. The rule is simple. Keeping on top of it across a workforce is not.",
      "Licences expire on different dates, and an officer with a lapsed licence on site exposes everyone to risk. That is why we verify licence validity before an officer is ever scheduled, and block the assignment if it has lapsed.",
      "Vetting goes further than the licence. We check identity, right to work and references to BS 7858 principles, so the person on your gate is exactly who they say they are.",
      "For clients, the takeaway is straightforward: ask your provider how they check, and how often.",
    ],
  },
  {
    slug: "infection-control-cleaning-in-care-settings",
    title: "Infection control cleaning in care settings",
    excerpt: "Why colour-coded systems and audited rounds are non-negotiable in care.",
    category: "Cleaning",
    date: "2026-04-03",
    readMins: 3,
    body: [
      "Cleaning a care home is not the same job as cleaning an office. The stakes are higher, and so are the standards.",
      "Colour-coded equipment keeps clean and dirty areas separate. Audited rounds mean nothing is skipped. COSHH records mean every chemical on site is accounted for.",
      "Method statements and risk assessments aren't bureaucracy — they're how a setting proves, at inspection, that cleaning is controlled rather than improvised.",
      "Done well, it is invisible. Done badly, it shows up in an inspection report. We treat it as part of care, not an afterthought.",
    ],
  },
  {
    slug: "train-and-get-hired-the-academy-route",
    title: "Train and get hired: the academy route into care",
    excerpt: "How completing the Care Certificate with us can lead straight to a job.",
    category: "Academy",
    date: "2026-03-18",
    readMins: 4,
    body: [
      "There's a strange gap in care recruitment: providers are short-staffed, while people who'd make excellent carers can't get a foot in the door without experience. RitaGreen Academy exists to close that gap.",
      "You don't need experience to start — just the right attitude. We teach the 16 Care Certificate standards, moving and handling, first aid and safeguarding, with assessor support and sign-off.",
      "The difference is what happens at the end. Strong learners get a guaranteed interview with RitaGreen Care, so the training leads somewhere real rather than into another pile of applications.",
      "For employers, it means the carers we place are people we trained ourselves — known quality, owned pipeline, no guesswork about how they were taught.",
    ],
  },
  {
    slug: "one-name-four-services-why-it-works",
    title: "One name, four services: why the model works",
    excerpt: "The case for buying care, training, cleaning and security from one supplier.",
    category: "Company",
    date: "2026-02-20",
    readMins: 4,
    body: [
      "Most care homes and managing agents run separate suppliers for staff, training, cleaning and security. Four contracts, four invoices, four different levels of trust — and four numbers to call when something goes wrong.",
      "RitaGreen is built the other way round. One name, one named contact, one standard, across all four services. You sell trust once and use it four ways.",
      "It works because the promise underneath is the same in every division: every worker is qualified, checked and ready before they start. A guard, a carer, a cleaner and a trainer are all vetted to the same bar.",
      "The practical upside is convenience without dropping standards: fewer suppliers to manage, one relationship to build, and a company that answers the phone and stands behind the people it sends.",
    ],
  },
  {
    slug: "end-of-tenancy-cleans-without-the-stress",
    title: "End-of-tenancy cleans without the stress",
    excerpt: "What landlords and agents should expect from a void-property turnaround.",
    category: "Cleaning",
    date: "2026-01-30",
    readMins: 3,
    body: [
      "A void property earns nothing. The faster it's cleaned, checked and re-let, the better — but rushed work just creates disputes over deposits and snagging.",
      "A good turnaround clean is systematic: kitchens and bathrooms deep-cleaned, appliances done, every room left presentable, and before-and-after photo evidence so there's no argument about condition.",
      "For agents managing a portfolio, the win is one reliable supplier who can also handle communal areas and, where needed, security on empty buildings — all on one invoice.",
      "Tell us the property and the deadline, and we'll turn it around to a standard that holds up at the next viewing.",
    ],
  },
]

export const CATEGORIES = ["All", "Care", "Academy", "Cleaning", "Security", "Company"] as const

export function findArticle(slug: string) {
  return ARTICLES.find((a) => a.slug === slug) ?? null
}
