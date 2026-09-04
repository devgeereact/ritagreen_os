import { useCallback, useEffect, useRef, useState } from "react"
import { Link } from "react-router-dom"
import { Cookie, X } from "lucide-react"

const STORAGE_KEY = "rg-storage-notice"

/**
 * Bump when what the site stores changes.
 *
 * The stored acknowledgement carries the version it was given against, so a new
 * category of storage re-asks rather than silently inheriting a "yes" to
 * something the visitor never saw. Keep it in step with docs/COOKIES.md.
 */
export const NOTICE_VERSION = "2026-09-04"

/** Reopen the notice — the footer link calls this. */
export function openStorageNotice() {
  window.dispatchEvent(new CustomEvent("rg:storage-notice"))
}

type Ack = { version: string; at: string }

function readAck(): Ack | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<Ack>
    return parsed?.version ? { version: parsed.version, at: String(parsed.at ?? "") } : null
  } catch {
    // Blocked or unparseable (private mode, cleared site data, an older format).
    return null
  }
}

/**
 * Storage notice for the public site.
 *
 * This is deliberately a notice and not a consent gate. The site loads no
 * analytics, no advertising, no pixels and — since the webfonts were
 * self-hosted — no third-party requests at all, and it sets nothing optional.
 * There is no choice to offer, so offering one would be theatre: the previous
 * version had Decline and Got it buttons that ran identical code and a stored
 * value nothing read.
 *
 * It follows that this must not behave like a consent wall either. It does not
 * trap focus and does not block the page, because a modal barrier in front of
 * information a visitor did not need to decide anything about is its own dark
 * pattern. It is reachable by keyboard, dismissible with Escape, and reopenable
 * from the footer — which the old one was not, once dismissed it was gone.
 *
 * If an optional category is ever added, this becomes a real consent control
 * with granular choices, and the scripts stay inert until it says otherwise.
 */
export function CookieConsent() {
  // Read once, during the first render, rather than in an effect that then sets
  // state: the notice would otherwise paint absent and flash in a frame later.
  const [open, setOpen] = useState(() => {
    const ack = readAck()
    return !ack || ack.version !== NOTICE_VERSION
  })
  const [returnFocus, setReturnFocus] = useState(false)
  const panelRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLElement | null>(null)

  useEffect(() => {
    const reopen = () => {
      triggerRef.current = document.activeElement as HTMLElement | null
      setReturnFocus(true)
      setOpen(true)
    }
    window.addEventListener("rg:storage-notice", reopen)
    return () => window.removeEventListener("rg:storage-notice", reopen)
  }, [])

  const dismiss = useCallback(() => {
    try {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({ version: NOTICE_VERSION, at: new Date().toISOString() } satisfies Ack),
      )
    } catch {
      // Storage blocked. Hide it for this page view; it will show again next
      // visit, which is the correct failure for a notice we cannot record.
    }
    setOpen(false)
    if (returnFocus) {
      triggerRef.current?.focus()
      setReturnFocus(false)
    }
  }, [returnFocus])

  // Move focus to the notice only when the visitor asked for it. Stealing focus
  // from someone mid-sentence on first load would be worse than not having it.
  useEffect(() => {
    if (open && returnFocus) panelRef.current?.focus()
  }, [open, returnFocus])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") dismiss()
    }
    window.addEventListener("keydown", onKey)
    return () => window.removeEventListener("keydown", onKey)
  }, [open, dismiss])

  if (!open) return null

  return (
    <div
      ref={panelRef}
      role="region"
      aria-label="How this site uses browser storage"
      tabIndex={-1}
      className="fixed inset-x-0 bottom-0 z-50 animate-fade-up px-4 pb-4 focus:outline-none"
    >
      <div className="surface mx-auto flex max-w-3xl flex-col items-start gap-4 p-5 shadow-lift sm:flex-row sm:items-center">
        <Cookie className="hidden size-6 shrink-0 text-primary sm:block" aria-hidden="true" />
        <p className="text-sm text-muted-foreground">
          This site stores a few things in your browser to keep it working — your
          sign-in, your theme, and this notice. There is no analytics, no advertising and
          no third-party tracking, so there is nothing here to opt out of. See our{" "}
          <Link
            to="/cookies"
            className="rounded-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            cookie and storage notice
          </Link>{" "}
          and{" "}
          <Link
            to="/privacy"
            className="rounded-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            privacy notice
          </Link>
          .
        </p>
        <button
          type="button"
          onClick={dismiss}
          className="flex shrink-0 items-center gap-2 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          <X className="size-4" aria-hidden="true" />
          Got it
        </button>
      </div>
    </div>
  )
}
