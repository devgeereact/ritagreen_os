import { Navigate, useLocation, useParams, useSearchParams } from "react-router-dom"
import {
  LEGACY_REDIRECTS,
  legacyQuoteTarget,
  legacyServicesTarget,
  legacyServiceTarget,
} from "@/lib/public-routes"

// Client-side redirects for the previous site's addresses (S6). The server
// sends a real 301 for each of these (public/.htaccess); these cover the cases
// the server never sees: an in-app link, the dev server, and a fragment such as
// /services#care, which browsers do not send to the server at all.
//
// Small and imported eagerly by src/routes/index.tsx: they pull in nothing but
// react-router, which the entry chunk already carries.

export function LegacyServicesRedirect() {
  const { hash } = useLocation()
  return <Navigate to={legacyServicesTarget(hash)} replace />
}

export function LegacyServiceRedirect() {
  const { slug } = useParams()
  return <Navigate to={legacyServiceTarget(slug)} replace />
}

export function LegacyArticleRedirect() {
  const { slug } = useParams()
  return <Navigate to={slug ? `/news/${slug}` : "/news"} replace />
}

export function LegacyQuoteRedirect() {
  const [params] = useSearchParams()
  return <Navigate to={legacyQuoteTarget(params.get("service"))} replace />
}

/** A fixed old address, by its key in LEGACY_REDIRECTS. */
export function LegacyRedirect({ from }: { from: keyof typeof LEGACY_REDIRECTS }) {
  return <Navigate to={LEGACY_REDIRECTS[from]} replace />
}
