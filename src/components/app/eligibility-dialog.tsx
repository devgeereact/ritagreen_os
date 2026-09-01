import { useEffect, useState } from "react"
import { ShieldCheck, ShieldAlert, TriangleAlert, Loader2 } from "lucide-react"

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Badge } from "@/components/ui/badge"
import { api } from "@/lib/api"
import { DIVISION_LABELS } from "@/lib/format"
import type { EligibilityBreakdown, ServiceId } from "@/lib/types"

const SERVICES: ServiceId[] = ["care", "academy", "cleaning", "security"]

// Whether one person may work in each service, and why not where they may not.
//
// Every value here is calculated by the server from the rules configured for the
// service and the evidence on file. Nothing on this screen is editable, and
// there is no endpoint that would accept a compliance status if it were — see
// docs/RULES.md rule 1.
export function EligibilityDialog({
  workerId,
  workerName,
  onOpenChange,
}: {
  workerId: number | null
  workerName?: string
  onOpenChange: (open: boolean) => void
}) {
  // One piece of state, written once per response, and it carries the worker it
  // belongs to. That lets `loading` be DERIVED rather than set at the top of the
  // effect, and it makes a stale response impossible to render: a reply for a
  // worker who is no longer selected simply never matches.
  const [result, setResult] = useState<{
    forWorker: number
    data: EligibilityBreakdown | null
    error: string | null
  } | null>(null)

  useEffect(() => {
    if (workerId === null) return
    let alive = true
    api<EligibilityBreakdown>(`compliance.php?action=eligibility_all&worker_id=${workerId}`)
      .then((r) => alive && setResult({ forWorker: workerId, data: r, error: null }))
      .catch(
        (e: unknown) =>
          alive &&
          setResult({
            forWorker: workerId,
            data: null,
            error: e instanceof Error ? e.message : "Could not load eligibility.",
          })
      )
    return () => {
      alive = false
    }
  }, [workerId])

  const current = result?.forWorker === workerId ? result : null
  const data = current?.data ?? null
  const error = current?.error ?? null
  const loading = workerId !== null && current === null

  return (
    <Dialog open={workerId !== null} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Eligibility — {data?.worker ?? workerName ?? "worker"}</DialogTitle>
          <DialogDescription>
            Calculated from the requirements configured for each service and the evidence on
            file. It cannot be edited here, or anywhere.
          </DialogDescription>
        </DialogHeader>

        {loading && (
          <div className="flex items-center gap-2 py-8 text-muted-foreground">
            <Loader2 className="size-4 animate-spin" /> Assessing…
          </div>
        )}

        {error && <p className="py-6 text-sm text-destructive">{error}</p>}

        {data && !loading && (
          <div className="space-y-3">
            {SERVICES.map((service) => {
              const v = data.services[service]
              if (!v) return null
              const warned = v.status === "eligible_with_warnings"
              return (
                <div key={service} className="rounded-lg border p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-2">
                      {v.eligible ? (
                        warned ? (
                          <TriangleAlert className="size-4 text-warning" />
                        ) : (
                          <ShieldCheck className="size-4 text-success" />
                        )
                      ) : (
                        <ShieldAlert className="size-4 text-destructive" />
                      )}
                      <span className="font-medium">
                        {DIVISION_LABELS[service] ?? service}
                      </span>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm tabular-nums text-muted-foreground">{v.score}%</span>
                      <Badge
                        variant={v.eligible ? (warned ? "secondary" : "default") : "destructive"}
                      >
                        {v.eligible ? (warned ? "Eligible, with warnings" : "Eligible") : "Not eligible"}
                      </Badge>
                    </div>
                  </div>

                  {/* Every blocker, not just the first: fixing them one at a time
                      against a fresh refusal each attempt is how a day is lost. */}
                  {v.blocking.length > 0 && (
                    <ul className="mt-2 space-y-1 border-t pt-2 text-sm text-destructive">
                      {v.blocking.map((reason) => (
                        <li key={reason}>{reason}</li>
                      ))}
                    </ul>
                  )}

                  {v.blocking.length === 0 && warned && (
                    <p className="mt-2 border-t pt-2 text-sm text-muted-foreground">{v.reason}</p>
                  )}
                </div>
              )
            })}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
