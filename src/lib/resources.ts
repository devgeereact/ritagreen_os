// News posts for the public site.
//
// Posts are written in the office app (Website > Posts, server/blog.php) and
// fetched at runtime. Only published posts are shown, and nothing else: there is
// no bundled fallback. The previous site shipped seven sample articles that
// rendered whenever the API returned nothing, dated as if published, with claims
// the plan does not support. A news section with nothing in it is hidden on the
// home page and says so on /news, which is honest; sample posts presented as
// real ones were not.
import { useEffect, useState } from "react"
import { api } from "@/lib/api"
import { SITE_IMAGES } from "@/lib/site-images"

export type BlogPost = {
  slug: string
  title: string
  excerpt: string
  /** The stored category value, e.g. "Care". Show it with categoryLabel(). */
  category: string
  date: string // ISO date (published_at)
  readMins: number
  image: string // cover image
  body: string[] // paragraphs
}

/** Back-compat alias: older imports used `Article`. */
export type Article = BlogPost

// The stored category values. They are written by the office Posts screen and
// are not migrated: "Care" and "Academy" stay in the database and are shown as
// Healthcare and Training through categoryLabel().
export const CATEGORIES = ["All", "Care", "Academy", "Cleaning", "Security", "Company"] as const

const CATEGORY_LABELS: Record<string, string> = {
  Care: "Healthcare",
  Academy: "Training",
}

/** The public name of a stored category. */
export function categoryLabel(category: string): string {
  return CATEGORY_LABELS[category] ?? category
}

// Category to cover photo, for posts saved without their own image.
const CATEGORY_IMAGE: Record<string, string> = {
  Care: SITE_IMAGES.care.src,
  Academy: SITE_IMAGES.training.src,
  Cleaning: SITE_IMAGES.cleaning.src,
  Security: SITE_IMAGES.security.src,
  Company: SITE_IMAGES.company.src,
}

export function categoryImage(category: string): string {
  return CATEGORY_IMAGE[category] ?? CATEGORY_IMAGE.Company
}

/** "12 May 2026" from an ISO date; empty when there is no date. */
export function formatPostDate(date: string): string {
  if (!date) return ""
  const d = new Date(`${date.slice(0, 10)}T00:00:00Z`)
  if (Number.isNaN(d.getTime())) return ""
  return d.toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" })
}

type ApiPost = {
  slug: string
  title: string
  excerpt: string
  category: string
  image?: string
  read_mins?: number
  published_at?: string
  body?: string
}

function fromApi(p: ApiPost): BlogPost {
  const paragraphs = String(p.body ?? "")
    .split(/\n{2,}/)
    .map((s) => s.trim())
    .filter(Boolean)
  return {
    slug: p.slug,
    title: p.title,
    excerpt: p.excerpt,
    category: p.category,
    date: p.published_at ?? "",
    readMins: p.read_mins ?? 3,
    image: p.image && p.image.trim() ? p.image : categoryImage(p.category),
    body: paragraphs,
  }
}

export async function fetchBlogPosts(): Promise<BlogPost[]> {
  const res = await api<{ posts: ApiPost[] }>("blog.php", { auth: false })
  return (res?.posts ?? []).map(fromApi)
}

export type PostsState = { posts: BlogPost[]; loading: boolean; failed: boolean }

/** Published posts, newest first, as the endpoint orders them. */
export function useBlogPosts(): PostsState {
  const [state, setState] = useState<PostsState>({ posts: [], loading: true, failed: false })
  useEffect(() => {
    let active = true
    fetchBlogPosts()
      .then((posts) => active && setState({ posts, loading: false, failed: false }))
      .catch(() => active && setState({ posts: [], loading: false, failed: true }))
    return () => {
      active = false
    }
  }, [])
  return state
}
