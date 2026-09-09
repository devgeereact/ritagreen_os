import { useEffect } from "react"

/**
 * Name the page in the browser tab, the history entry and the screen reader's
 * announcement on navigation.
 *
 * The public site does this through useSeo(), which also writes the meta
 * description and canonical link. The three authenticated surfaces have no SEO
 * to set and had nothing setting a title at all, so every page behind the login
 * inherited whichever <title> the last public page left behind — the whole CMS,
 * the client portal and the worker app all read as the marketing home page.
 */
export function useDocumentTitle(title: string): void {
  useEffect(() => {
    document.title = title
  }, [title])
}
