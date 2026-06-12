import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { Cookie } from "lucide-react"

const STORAGE_KEY = "rg-cookie-consent"

// Lightweight consent notice for the public site. We set no analytics or
// marketing cookies today, so this is a transparency notice with an explicit
// acknowledgement, stored in localStorage so it shows once per visitor.
export function CookieConsent() {
  const [open, setOpen] = useState(false)

  useEffect(() => {
    try {
      if (!localStorage.getItem(STORAGE_KEY)) setOpen(true)
    } catch {
      // localStorage blocked (private mode) — show the notice, don't crash.
      setOpen(true)
    }
  }, [])

  const dismiss = (choice: "accepted" | "declined") => {
    try {
      localStorage.setItem(STORAGE_KEY, `${choice}:${new Date().toISOString()}`)
    } catch {
      /* ignore */
    }
    setOpen(false)
  }

  if (!open) return null

  return (
    <div
      role="dialog"
      aria-label="Cookie notice"
      className="fixed inset-x-0 bottom-0 z-50 animate-fade-up px-4 pb-4"
    >
      <div className="surface mx-auto flex max-w-3xl flex-col items-start gap-4 p-5 shadow-lift sm:flex-row sm:items-center">
        <Cookie className="hidden size-6 shrink-0 text-primary sm:block" />
        <p className="text-sm text-muted-foreground">
          We use only the cookies needed to keep this site working and to remember this
          choice. We don't track you or sell your data. See our{" "}
          <Link to="/cookies" className="font-medium text-primary hover:underline">
            cookie policy
          </Link>{" "}
          and{" "}
          <Link to="/privacy" className="font-medium text-primary hover:underline">
            privacy policy
          </Link>
          .
        </p>
        <div className="flex shrink-0 gap-2">
          <button
            onClick={() => dismiss("declined")}
            className="rounded-xl border border-border px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
          >
            Decline
          </button>
          <button
            onClick={() => dismiss("accepted")}
            className="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition hover:opacity-90"
          >
            Got it
          </button>
        </div>
      </div>
    </div>
  )
}
