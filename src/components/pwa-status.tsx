import { useEffect, useState } from "react"
import { useRegisterSW } from "virtual:pwa-register/react"
import { CloudOff, RefreshCw } from "lucide-react"

import { Button } from "@/components/ui/button"

// Two things a worker in the field needs the app to be honest about: whether it
// is talking to the server, and whether it is running an old version.
//
// Deliberately NOT an offline write queue. The server is the source of truth for
// compliance, and a queued clock-in that turns out to be invalid when it lands
// is worse than being told plainly that you are offline. See docs/BLUEPRINT.md.
export function PwaStatus() {
  const [offline, setOffline] = useState(() => typeof navigator !== "undefined" && !navigator.onLine)

  const {
    needRefresh: [needRefresh, setNeedRefresh],
    updateServiceWorker,
  } = useRegisterSW({
    onRegisterError: () => {
      // A failed registration must never break the page. The app works without
      // a service worker; it just does not work offline.
    },
  })

  useEffect(() => {
    const goOffline = () => setOffline(true)
    const goOnline = () => setOffline(false)
    window.addEventListener("offline", goOffline)
    window.addEventListener("online", goOnline)
    return () => {
      window.removeEventListener("offline", goOffline)
      window.removeEventListener("online", goOnline)
    }
  }, [])

  if (!offline && !needRefresh) return null

  return (
    <div className="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-3">
      {offline && (
        <div className="pointer-events-auto flex items-center gap-2 rounded-full bg-foreground px-4 py-2 text-sm text-background shadow-lift">
          <CloudOff className="size-4" />
          {/* Says what still works, not just that something is wrong. */}
          <span>Offline — you can read, but changes will not save until you reconnect.</span>
        </div>
      )}

      {needRefresh && (
        <div className="pointer-events-auto flex items-center gap-3 rounded-full bg-primary px-4 py-2 text-sm text-primary-foreground shadow-lift">
          <RefreshCw className="size-4" />
          <span>A new version is ready.</span>
          {/* Prompted, never automatic: swapping the app out from under someone
              part-way through clocking in is its own kind of failure. */}
          <Button
            size="sm"
            variant="secondary"
            className="h-7"
            onClick={() => updateServiceWorker(true)}
          >
            Reload
          </Button>
          <button
            type="button"
            onClick={() => setNeedRefresh(false)}
            className="text-xs underline underline-offset-2 opacity-80 hover:opacity-100"
          >
            Later
          </button>
        </div>
      )}
    </div>
  )
}
