// Placeholder resource articles for the public site. A later pass moves these
// to a read-only PHP catalogue feed so the office can publish from the CMS.
export type Article = {
  slug: string
  title: string
  excerpt: string
  category: string
  date: string
  body: string[]
}

export const ARTICLES: Article[] = [
  {
    slug: "what-to-look-for-in-a-care-provider",
    title: "What to look for in a care provider",
    excerpt: "Five practical checks before you choose who supports a loved one at home.",
    category: "Care",
    date: "2026-05-12",
    body: [
      "Choosing a care provider is a decision families rarely make twice, and usually under pressure. A few checks make the difference between a provider that turns up and one that genuinely fits.",
      "Ask how carers are matched. Consistency matters more than almost anything else: the same faces, who know the person and the routine, beat a rotating cast every time.",
      "Ask how visits are recorded. Electronic visit logs mean families and funders can see what happened on each call, not just trust that it did.",
      "Finally, ask about compliance. DBS checks, training and the right to work should all be confirmed before a carer's first visit, not chased afterwards.",
    ],
  },
  {
    slug: "why-sia-licence-checks-matter",
    title: "Why SIA licence checks matter",
    excerpt: "An out-of-date licence is not a paperwork problem. It is a liability.",
    category: "Security",
    date: "2026-04-28",
    body: [
      "Every front-line security officer in the UK must hold a valid Security Industry Authority licence. The rule is simple. Keeping on top of it across a workforce is not.",
      "Licences expire on different dates, and an officer with a lapsed licence on site exposes everyone to risk. That is why we verify licence validity before an officer is ever scheduled, and block the assignment if it has lapsed.",
      "For clients, the takeaway is straightforward: ask your provider how they check, and how often.",
    ],
  },
  {
    slug: "infection-control-cleaning-in-care-settings",
    title: "Infection control cleaning in care settings",
    excerpt: "Why colour-coded systems and audited rounds are non-negotiable in care.",
    category: "Cleaning",
    date: "2026-04-03",
    body: [
      "Cleaning a care home is not the same job as cleaning an office. The stakes are higher, and so are the standards.",
      "Colour-coded equipment keeps clean and dirty areas separate. Audited rounds mean nothing is skipped. COSHH records mean every chemical on site is accounted for.",
      "Done well, it is invisible. Done badly, it shows up in an inspection report. We treat it as part of care, not an afterthought.",
    ],
  },
]

export function findArticle(slug: string) {
  return ARTICLES.find((a) => a.slug === slug) ?? null
}
