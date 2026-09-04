import { useEffect } from "react"
import { Outlet, useLocation } from "react-router-dom"
import { PublicNav } from "@/components/public/PublicNav"
import { PublicFooter } from "@/components/public/PublicFooter"
import { CookieConsent } from "@/components/public/CookieConsent"

// Forces light theme + scroll handling for the public corporate site.
export default function PublicLayout() {
  const { pathname, hash } = useLocation()

  useEffect(() => {
    // Honour the operating system's reduced-motion setting. A smooth scroll is
    // decoration; for someone with a vestibular disorder it is the thing that
    // makes a page unusable, and WCAG 2.3.3 asks that motion from an interaction
    // can be turned off. The jump still happens, it just does not animate.
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches
    const behavior: ScrollBehavior = reduced ? "auto" : "smooth"

    if (hash) {
      const el = document.getElementById(hash.slice(1))
      if (el) {
        el.scrollIntoView({ behavior, block: "start" })
        return
      }
    }
    window.scrollTo({ top: 0 })
  }, [pathname, hash])

  return (
    <div className="flex min-h-svh flex-col bg-background">
      {/* Skip link — the first focusable element on the page.
          The internal CMS had one; the public site did not, so a keyboard or
          screen-reader user had to tab through the whole header, the services
          mega-menu and the utility bar on every single page before reaching the
          content. WCAG 2.4.1. */}
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[100] focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground focus:shadow-lift"
      >
        Skip to content
      </a>
      <PublicNav />
      <main id="main-content" tabIndex={-1} className="flex-1">
        <Outlet />
      </main>
      <PublicFooter />
      <CookieConsent />
    </div>
  )
}
