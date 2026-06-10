import { useCallback, useEffect, useState } from "react"
import { api, ApiError } from "@/lib/api"

type State<T> = {
  data: T | null
  loading: boolean
  error: string | null
}

// Read-only GET hook with loading/error/empty handling. `deps` re-fetches.
export function useApi<T>(endpoint: string | null, deps: unknown[] = []) {
  const [state, setState] = useState<State<T>>({ data: null, loading: !!endpoint, error: null })

  const reload = useCallback(() => {
    if (!endpoint) return
    setState((s) => ({ ...s, loading: true, error: null }))
    api<T>(endpoint)
      .then((data) => setState({ data, loading: false, error: null }))
      .catch((err) =>
        setState({
          data: null,
          loading: false,
          error: err instanceof ApiError ? err.message : "Failed to load.",
        }),
      )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [endpoint, ...deps])

  useEffect(() => {
    reload()
  }, [reload])

  return { ...state, reload }
}
