import { useEffect, useRef, useState } from "react"

// Counts a number up from 0 to `end` once the element scrolls into view — the
// Oxybuild "stats band" effect, done dependency-free. Respects reduced-motion
// (jumps straight to the final value) and only fires once.
export function useCountUp(end: number, durationMs = 1600) {
  const ref = useRef<HTMLSpanElement>(null)
  const [value, setValue] = useState(0)
  const started = useRef(false)

  useEffect(() => {
    const el = ref.current
    if (!el) return

    const reduce =
      typeof window !== "undefined" &&
      window.matchMedia?.("(prefers-reduced-motion: reduce)").matches

    const run = () => {
      if (started.current) return
      started.current = true
      if (reduce || end === 0) {
        setValue(end)
        return
      }
      const start = performance.now()
      const tick = (now: number) => {
        const t = Math.min(1, (now - start) / durationMs)
        // easeOutCubic for a natural settle
        const eased = 1 - Math.pow(1 - t, 3)
        setValue(Math.round(eased * end))
        if (t < 1) requestAnimationFrame(tick)
      }
      requestAnimationFrame(tick)
    }

    const io = new IntersectionObserver(
      (entries) => entries.forEach((e) => e.isIntersecting && run()),
      { threshold: 0.4 },
    )
    io.observe(el)
    return () => io.disconnect()
  }, [end, durationMs])

  return { ref, value }
}
