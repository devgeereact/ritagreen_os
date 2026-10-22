import { useCallback, useState } from "react"
import { toast } from "sonner"
import { api, ApiError } from "@/lib/api"

/**
 * Run a write against an endpoint, say what happened, and refresh.
 *
 * Every action on the person profile and the compliance screens goes through
 * this, so a refusal from the server is always shown in the server's own words
 * and never swallowed, and the page re-reads the calculated state afterwards
 * rather than guessing it.
 */
export function usePostAction(onDone: () => void) {
  const [busy, setBusy] = useState(false)

  const run = useCallback(
    async (path: string, body: unknown = {}, success?: string): Promise<boolean> => {
      setBusy(true)
      try {
        await api(path, { method: "POST", body })
        if (success) toast.success(success)
        onDone()
        return true
      } catch (err) {
        toast.error(err instanceof ApiError ? err.message : "Could not save that.")
        return false
      } finally {
        setBusy(false)
      }
    },
    [onDone],
  )

  return { run, busy }
}
