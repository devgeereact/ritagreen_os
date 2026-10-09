import { useCallback, useEffect, useSyncExternalStore } from "react"
import { api, ApiError } from "@/lib/api"
import type { FeatureName, FeaturesResponse } from "@/lib/types"

// The owner's feature switches (server/features.php), shared by everything in
// the office shell that reads them: the sidebar, the ⌘K palette, the header and
// the Settings card.
//
// One small module-level store rather than a context provider, so the switches
// are fetched once per page load however many components ask, and a change
// made in Settings shows in the sidebar straight away. The switches are the
// same for every account, so nothing here is per-user.
//
// Until the answer arrives every switch reads as off. Something switched on
// appears a moment late; something switched off never flashes into view.

type Snapshot = {
  data: FeaturesResponse | null
  loading: boolean
  error: string | null
}

let snapshot: Snapshot = { data: null, loading: false, error: null }
let inflight: Promise<void> | null = null
const listeners = new Set<() => void>()

function publish(next: Partial<Snapshot>) {
  snapshot = { ...snapshot, ...next }
  listeners.forEach((l) => l())
}

function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

function load(): Promise<void> {
  if (inflight) return inflight
  publish({ loading: true, error: null })
  inflight = api<FeaturesResponse>("features.php")
    .then((data) => publish({ data, loading: false, error: null }))
    .catch((err) =>
      publish({ loading: false, error: err instanceof ApiError ? err.message : "Could not load the feature switches." }),
    )
    .finally(() => {
      inflight = null
    })
  return inflight
}

export function useFeatures() {
  const state = useSyncExternalStore(subscribe, () => snapshot)

  useEffect(() => {
    if (!snapshot.data && !inflight) void load()
  }, [])

  const isOn = useCallback((name: FeatureName) => state.data?.features[name] ?? false, [state.data])

  /** Owner only; the server refuses anyone else. Throws ApiError on refusal. */
  const setFeature = useCallback(async (name: FeatureName, enabled: boolean) => {
    const data = await api<FeaturesResponse>("features.php", { method: "POST", body: { name, enabled } })
    publish({ data, error: null })
  }, [])

  return { ...state, isOn, setFeature, reload: load }
}
