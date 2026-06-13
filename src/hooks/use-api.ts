import { useCallback, useEffect, useRef, useState } from "react"
import { api, ApiError } from "@/lib/api"

type State<T> = {
  data: T | null
  loading: boolean
  error: string | null
}

// Read-only GET hook with loading/error/empty handling. `deps` re-fetches.
export function useApi<T>(endpoint: string | null, deps: unknown[] = []) {
  const [state, setState] = useState<State<T>>({ data: null, loading: !!endpoint, error: null })
  // Monotonic request id: a slow earlier fetch must not overwrite a newer one
  // when endpoint/deps change quickly (last-issued request wins).
  const reqIdRef = useRef(0)

  const reload = useCallback(() => {
    if (!endpoint) return
    const reqId = ++reqIdRef.current
    setState((s) => ({ ...s, loading: true, error: null }))
    api<T>(endpoint)
      .then((data) => {
        if (reqId === reqIdRef.current) setState({ data, loading: false, error: null })
      })
      .catch((err) => {
        if (reqId !== reqIdRef.current) return // superseded by a newer request
        setState({
          data: null,
          loading: false,
          error: err instanceof ApiError ? err.message : "Failed to load.",
        })
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [endpoint, ...deps])

  useEffect(() => {
    reload()
  }, [reload])

  return { ...state, reload }
}
