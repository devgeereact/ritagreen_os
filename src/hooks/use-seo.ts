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
  /**
   * Keep this page out of search results.
   *
   * Needed for two kinds of page: one that does not exist (the 404) and one
   * that is a step in a flow rather than a destination (sign-in, reset, the
   * Google callback). Both also drop their canonical — see below.
   */
  noindex?: boolean
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

function removeMeta(attr: "name" | "property", key: string) {
  document.head.querySelector(`meta[${attr}="${key}"]`)?.remove()
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

function removeLink(rel: string) {
  document.head.querySelector(`link[rel="${rel}"]`)?.remove()
}

export function useSeo({ title, description, path, jsonLd, ogType = "website", noindex }: Seo) {
  // jsonLd is nearly always written as an object literal in the caller's render
  // body, so it is a new reference every render and would re-run this effect
  // every render if it were a dependency. Serialised instead: the effect reruns
  // when the CONTENT changes, which is what was meant.
  const jsonLdKey = jsonLd ? JSON.stringify(jsonLd) : ""

  useEffect(() => {
    const fullTitle = title.includes(SITE) ? title : `${title} — ${SITE}`

    document.title = fullTitle
    setMeta("name", "description", description)

    setMeta("property", "og:site_name", SITE)
    setMeta("property", "og:type", ogType)
    setMeta("property", "og:title", fullTitle)
    setMeta("property", "og:description", description)

    setMeta("name", "twitter:card", "summary_large_image")
    setMeta("name", "twitter:title", fullTitle)
    setMeta("name", "twitter:description", description)

    if (noindex) {
      setMeta("name", "robots", "noindex, follow")
      // A page that should not be indexed must not claim a canonical either.
      // index.html carries a static canonical pointing at the homepage, so a
      // page that leaves it in place tells Google "I am a duplicate of the
      // homepage" — which is how the 404 page was previously described. Dropping
      // the tag is the correct signal; the URL still resolves, it is just not a
      // canonical for anything.
      removeLink("canonical")
      removeMeta("property", "og:url")
    } else {
      removeMeta("name", "robots")
      const url = path ? `${ORIGIN}${path}` : ORIGIN
      setLink("canonical", url)
      setMeta("property", "og:url", url)
    }

    const LD_ID = "page-jsonld"
    document.getElementById(LD_ID)?.remove()
    if (jsonLdKey) {
      const script = document.createElement("script")
      script.type = "application/ld+json"
      script.id = LD_ID
      script.textContent = jsonLdKey
      document.head.appendChild(script)
    }

    return () => {
      document.getElementById(LD_ID)?.remove()
    }
  }, [title, description, path, ogType, jsonLdKey, noindex])
}
