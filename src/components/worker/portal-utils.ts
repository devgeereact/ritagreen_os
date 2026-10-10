// Non-component helpers for the staff portal, apart from portal-bits.tsx so that
// file exports components only (fast refresh).
import { useEffect, useState } from "react"
import { toast } from "sonner"
import { ApiError } from "@/lib/api"

/** Is the phone online? Follows the browser's online and offline events. */
export function useOnline(): boolean {
  const [online, setOnline] = useState(() => (typeof navigator === "undefined" ? true : navigator.onLine))
  useEffect(() => {
    const on = () => setOnline(true)
    const off = () => setOnline(false)
    window.addEventListener("online", on)
    window.addEventListener("offline", off)
    return () => {
      window.removeEventListener("online", on)
      window.removeEventListener("offline", off)
    }
  }, [])
  return online
}

/**
 * Run an action with a saved or failed message after it, the plan's rule for
 * every action in the portal. Returns whether it worked.
 */
export async function act(run: () => Promise<unknown>, saved: string, failed = "That did not work. Please try again."): Promise<boolean> {
  try {
    await run()
    toast.success(saved)
    return true
  } catch (err) {
    toast.error(err instanceof ApiError ? err.message : failed)
    return false
  }
}

/** Tailwind classes for a native input or select, matching the vendored Input. */
export const fieldClass =
  "flex h-11 w-full rounded-md border border-input bg-background px-3 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50"
