// Client testimonials shown on the public site.
//
// Written in the office app (Website > Testimonials, server/testimonials.php)
// and fetched at runtime. Only published ones are shown, and only those: there
// is no bundled fallback and no headline rating. The previous version shipped
// six sample quotes and a "5.0 from 27 reviews" summary that rendered whenever
// the API returned nothing, which presented invented reviews as real ones. A
// testimonial appears on the site when a real, permissioned one is published.
import { useEffect, useState } from "react"
import { api } from "@/lib/api"

export type Testimonial = {
  author: string
  role: string // e.g. "Care home manager, Lewisham"
  rating: number // 1 to 5
  text: string
}

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
  const res = await api<{ testimonials: ApiTestimonial[] }>("testimonials.php", { auth: false })
  return (res?.testimonials ?? []).map(fromApi).filter((t) => t.text.trim() !== "")
}

/** Published testimonials; empty while loading, when there are none, or when the API is unreachable. */
export function useTestimonials(): Testimonial[] {
  const [items, setItems] = useState<Testimonial[]>([])
  useEffect(() => {
    let active = true
    fetchTestimonials()
      .then((t) => active && setItems(t))
      .catch(() => {
        // Nothing to show is the right answer when they cannot be loaded.
      })
    return () => {
      active = false
    }
  }, [])
  return items
}
