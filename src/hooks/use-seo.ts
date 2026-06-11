import { useEffect } from "react"

// Lightweight, dependency-free SEO for the public SPA. Sets the document title,
// meta description, canonical and Open Graph / Twitter tags per page, and can
// inject a JSON-LD block. Tags are reused by id so navigation just updates them.
//
// This is the SPA-friendly answer to the copy pack's SEO note: each public page
// gets its own title tag, meta description and (where useful) structured data.

const SITE = "RitaGreen Services"
const ORIGIN = "https://ritagreenservices.com"

type Seo = {
  title: string
  description: string
  /** path only, e.g. "/services" — combined with the production origin */
  path?: string
  /** optional JSON-LD object injected as application/ld+json */
  jsonLd?: Record<string, unknown>
  /** override the og:type (default "website") */
  ogType?: string
}

function setMeta(attr: "name" | "property", key: string, content: string) {
  const sel = `meta[${attr}="${key}"]`
  let el = document.head.querySelector<HTMLMetaElement>(sel)
  if (!el) {
    el = document.createElement("meta")
    el.setAttribute(attr, key)
    document.head.appendChild(el)
  }
  el.setAttribute("content", content)
}

function setLink(rel: string, href: string) {
  let el = document.head.querySelector<HTMLLinkElement>(`link[rel="${rel}"]`)
  if (!el) {
    el = document.createElement("link")
    el.setAttribute("rel", rel)
    document.head.appendChild(el)
  }
  el.setAttribute("href", href)
}

export function useSeo({ title, description, path, jsonLd, ogType = "website" }: Seo) {
  useEffect(() => {
    const fullTitle = title.includes(SITE) ? title : `${title} — ${SITE}`
    const url = path ? `${ORIGIN}${path}` : ORIGIN

    document.title = fullTitle
    setMeta("name", "description", description)

    setMeta("property", "og:site_name", SITE)
    setMeta("property", "og:type", ogType)
    setMeta("property", "og:title", fullTitle)
    setMeta("property", "og:description", description)
    setMeta("property", "og:url", url)

    setMeta("name", "twitter:card", "summary_large_image")
    setMeta("name", "twitter:title", fullTitle)
    setMeta("name", "twitter:description", description)

    setLink("canonical", url)

    const LD_ID = "page-jsonld"
    const existing = document.getElementById(LD_ID)
    if (existing) existing.remove()
    if (jsonLd) {
      const script = document.createElement("script")
      script.type = "application/ld+json"
      script.id = LD_ID
      script.textContent = JSON.stringify(jsonLd)
      document.head.appendChild(script)
    }

    return () => {
      const node = document.getElementById(LD_ID)
      if (node) node.remove()
    }
  }, [title, description, path, ogType, jsonLd])
}
