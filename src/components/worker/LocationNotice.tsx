import { useEffect, useRef, useState } from "react"
import { MapPin } from "lucide-react"
import { Button } from "@/components/ui/button"

const STORAGE_KEY = "rg-worker-location-notice"
const VERSION = "2026-09-04"

/**
 * Told once, before the first clock-in, and reachable afterwards.
 *
 * The app has always sent a worker's position with a clock-in, a clock-out and
 * a panic alert. Nothing said so. The only thing a worker saw was their phone's
 * own permission prompt, which says "this site wants your location" and answers
 * none of the questions that matter: when, how precisely, who sees it, and what
 * happens if they say no. Monitoring people at work without telling them is the
 * problem here, not the monitoring.
 *
 * Deliberately not a consent gate. Location is part of how attendance is
 * recorded and how a panic alert is answered; presenting it as optional when
 * refusing it would not stop the shift being recorded would be a lie. What the
 * worker gets is the truth, before the fact, and a phone-level refusal that
 * still leaves clocking in working.
 */
export function LocationNotice({ onDone }: { onDone?: () => void }) {
  // Read during the first render, not in an effect: a worker about to clock in
  // should not see the shift list paint and the notice arrive on top of it.
  const [open, setOpen] = useState(() => {
    try {
      return localStorage.getItem(STORAGE_KEY) !== VERSION
    } catch {
      // Storage blocked. Showing the notice is the safe failure.
      return true
    }
  })
  const headingRef = useRef<HTMLHeadingElement>(null)

  useEffect(() => {
    if (open) headingRef.current?.focus()
  }, [open])

  function acknowledge() {
    try {
      localStorage.setItem(STORAGE_KEY, VERSION)
    } catch {
      /* ignore — it will show again, which is the safe failure for a notice */
    }
    setOpen(false)
    onDone?.()
  }

  if (!open) return null

  return (
    <section
      aria-labelledby="location-notice-heading"
      className="rounded-2xl border border-primary/30 bg-primary/5 p-4"
    >
      <div className="flex items-start gap-3">
        <MapPin className="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
        <div className="space-y-3">
          <h2
            id="location-notice-heading"
            ref={headingRef}
            tabIndex={-1}
            className="font-semibold focus:outline-none"
          >
            About your location
          </h2>
          <ul className="space-y-2 text-sm leading-relaxed text-muted-foreground">
            <li>
              We record where you are at three moments only: when you clock in, when you
              clock out, and if you send a panic alert. Not in between, and never while the
              app is closed.
            </li>
            <li>
              Clock-in and clock-out are stored roughly, to about 110 metres — enough to show
              you were at the site, not enough to be a record of your movements. A panic
              alert is stored precisely, so somebody can find you.
            </li>
            <li>The office sees it against that shift. Clients never do.</li>
            <li>
              Your phone will ask you first. If you say no, clocking in still works — we
              simply record the time without a position.
            </li>
          </ul>
          <p className="text-sm text-muted-foreground">
            There is more in our{" "}
            <a href="/privacy" className="font-medium text-primary underline-offset-4 hover:underline">
              privacy notice
            </a>
            . If you are unhappy with any of this, speak to the office.
          </p>
          <Button onClick={acknowledge} size="sm">Got it</Button>
        </div>
      </div>
    </section>
  )
}
