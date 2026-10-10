import { useEffect } from "react"
import { NavLink, Outlet } from "react-router-dom"
import { CalendarDays, FileText, Home, User } from "lucide-react"
import { purgeCachedApiReads } from "@/lib/api"
import { cn } from "@/lib/utils"
import {  } from "@/components/worker/portal-bits"
import { useOnline } from "@/components/worker/portal-utils"

const TABS = [
  { to: "/m", label: "Home", icon: Home, end: true },
  { to: "/m/rota", label: "Rota", icon: CalendarDays, end: false },
  { to: "/m/records", label: "Records", icon: FileText, end: false },
  { to: "/m/me", label: "Me", icon: User, end: false },
] as const

/**
 * The staff portal's frame: a short top bar, the tab's content, and four tabs at
 * the bottom where a thumb reaches them (docs/Plans 2026/03 and 04).
 *
 * Each tab reads its own data when it opens, so switching tabs is a fresh read
 * and nothing personal is held between them.
 */
export default function WorkerApp() {
  const online = useOnline()

  // Earlier builds cached me.php and notifications.php in the service worker for
  // twelve hours ("worker-reads"). This build caches no personal data, so drop
  // whatever an older install left behind on this phone.
  useEffect(() => {
    void purgeCachedApiReads()
  }, [])

  return (
    <div className="min-h-svh bg-background">
      <a
        href="#portal-main"
        className="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-card focus:px-3 focus:py-2 focus:shadow"
      >
        Skip to content
      </a>
      <header className="sticky top-0 z-20 border-b border-border bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/80">
        <div className="mx-auto flex h-12 max-w-md items-center justify-between px-4">
          <span className="font-semibold">
            <span className="text-foreground">Rita</span><span className="text-primary">Green</span>
          </span>
          {!online && (
            <span role="status" className="rounded-full border border-border bg-muted px-2 py-0.5 text-xs font-medium">
              Offline
            </span>
          )}
        </div>
      </header>

      <main id="portal-main" className="mx-auto max-w-md space-y-5 px-4 pb-28 pt-4">
        <Outlet />
      </main>

      <nav
        aria-label="Portal"
        className="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-card pb-[env(safe-area-inset-bottom)]"
      >
        <ul className="mx-auto grid max-w-md grid-cols-4">
          {TABS.map((t) => (
            <li key={t.to}>
              <NavLink
                to={t.to}
                end={t.end}
                className={({ isActive }) =>
                  cn(
                    "flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs font-medium text-muted-foreground outline-none transition-colors motion-reduce:transition-none",
                    "focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset",
                    isActive && "font-bold text-primary",
                  )
                }
              >
                <t.icon className="size-5" aria-hidden />
                {t.label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  )
}
