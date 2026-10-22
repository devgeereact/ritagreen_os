import type { Testimonial } from "@/lib/reviews"
import type { BlogPost } from "@/lib/resources"
import { SITE_IMAGES } from "@/lib/site-images"

// DEMO CONTENT FOR DEVELOPMENT ONLY.
//
// The home page hides its testimonial and news bands when the office app has
// published none, which is right for the public but leaves a developer looking
// at a page with two holes in it. These fixtures fill those holes while
// `npm run dev` is running, so the layout can be judged with realistic lengths.
//
// They never reach production. Every reader guards them with
// `import.meta.env.DEV` written at the point of use (not a re-exported flag),
// which Vite replaces with `false` in `npm run build`; the minifier then drops
// the branch and nothing references this module, so it is not in the bundle.
// Every demo block also wears a visible "Demo content" label (DemoLabel in
// section.tsx). The quotes are invented and attributed to no real person or
// organisation: reviews.ts explains why an invented review must never be shown
// as a real one.

export const DEMO_TESTIMONIALS: Testimonial[] = [
  {
    author: "Demo reviewer",
    role: "Registered manager, care home (sample)",
    rating: 5,
    text: "Sample quote. We called on a Friday afternoon for two weekend carers and both arrived briefed, on time and with their training in date. This text is a placeholder for a real, permissioned testimonial.",
  },
]

export const DEMO_POSTS: BlogPost[] = [
  {
    slug: "demo-right-to-work-checks",
    title: "Sample article: what a right to work check involves",
    excerpt: "Placeholder text showing how a news card lays out with a two-line title and a short summary of about this length.",
    category: "Care",
    date: "2026-09-18",
    readMins: 4,
    image: SITE_IMAGES.care.src,
    body: [],
  },
  {
    slug: "demo-cleaning-schedules",
    title: "Sample article: planning a cleaning schedule for a school term",
    excerpt: "Placeholder text. Published posts from the office app (Website > Posts) replace these cards automatically.",
    category: "Cleaning",
    date: "2026-09-04",
    readMins: 3,
    image: SITE_IMAGES.cleaning.src,
    body: [],
  },
  {
    slug: "demo-mandatory-training",
    title: "Sample article: mandatory training dates for the year ahead",
    excerpt: "Placeholder text for a training update, long enough to wrap onto a second line at most widths.",
    category: "Academy",
    date: "2026-08-21",
    readMins: 5,
    image: SITE_IMAGES.training.src,
    body: [],
  },
]
