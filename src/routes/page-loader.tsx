import { Loader2 } from "lucide-react"

/**
 * Suspense fallback while a lazily-loaded page chunk arrives.
 *
 * Its own file because routes/index.tsx exports the router, not components, and
 * a module mixing the two loses fast refresh
 * (react-refresh/only-export-components). It is imported eagerly and is a few
 * lines, so it does not affect the chunking that file is careful about.
 */
export function PageLoader() {
  return (
    <div className="flex h-full min-h-[60vh] w-full items-center justify-center">
      <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
    </div>
  )
}
