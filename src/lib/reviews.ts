// Curated client testimonials shown on the public site.
//
// Managed in the App (server/testimonials.php → testimonials table) and fetched
// at runtime. TESTIMONIALS below is the bundled SEED: it renders instantly and
// is the fallback when the API is unavailable (e.g. local dev with no PHP).
// ⚠️ Seed entries are illustrative samples — replace with real, approved quotes.
import { useEffect, useState } from "react"
import { api } from "@/lib/api"

export type Testimonial = {
  author: string
  role: string // e.g. "Care home manager, Lewisham"
  rating: number // 1–5
  text: string
}

// Headline aggregate shown above the testimonials. Set to your real figures.
export const REVIEW_SUMMARY = {
  rating: 5.0,
  count: 27,
} as const

export const TESTIMONIALS: Testimonial[] = [
  {
    author: "Sarah M.",
    role: "Care home manager",
    rating: 5,
    text: "We had a carer call in sick at 6am and RitaGreen had a checked replacement with us before the morning round. One phone call, sorted.",
  },
  {
    author: "David O.",
    role: "Managing agent",
    rating: 5,
    text: "Managing three blocks, I used to juggle a cleaning firm and a separate security contractor. Now it's one invoice and one contact who actually answers.",
  },
  {
    author: "Priya N.",
    role: "Academy graduate",
    rating: 5,
    text: "Did the Care Certificate with the academy and walked into an interview with their care team the week I finished. Genuinely changed things for me.",
  },
  {
    author: "Tom B.",
    role: "Events manager",
    rating: 5,
    text: "Booked door supervisors for a venue event at short notice. Briefed, licensed, professional — the team lead checked in with us through the night.",
  },
  {
    author: "Aisha R.",
    role: "Registered manager",
    rating: 5,
    text: "The infection-control cleaning in our care home is spotless and the paperwork is always ready for inspection. It takes a real worry off my plate.",
  },
  {
    author: "Michael F.",
    role: "Facilities lead",
    rating: 4,
    text: "Reliable cover and clear communication. Quotes come in writing with no surprises, which is rare. Would recommend to other managers.",
  },
]

type ApiTestimonial = { author: string; role?: string; rating?: number; body?: string }

function fromApi(t: ApiTestimonial): Testimonial {
  return {
    author: t.author,
    role: t.role ?? "",
    rating: t.rating ?? 5,
    text: t.body ?? "",
  }
}

export async function fetchTestimonials(): Promise<Testimonial[]> {
  try {
    const res = await api<{ testimonials: ApiTestimonial[] }>("testimonials.php", { auth: false })
    if (res?.testimonials?.length) return res.testimonials.map(fromApi)
  } catch {
    // No endpoint / offline — fall back to the bundled seed.
  }
  return TESTIMONIALS
}

export function useTestimonials(): Testimonial[] {
  const [items, setItems] = useState<Testimonial[]>(TESTIMONIALS)
  useEffect(() => {
    let active = true
    fetchTestimonials().then((t) => active && t.length && setItems(t))
    return () => {
      active = false
    }
  }, [])
  return items
}
