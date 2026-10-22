import { useState } from "react"
import { toast } from "sonner"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { shortDate } from "@/lib/format"
import type { DeletionRequest, DeletionRequestStatus } from "@/lib/types"
import { EmptyBlock, ErrorBlock, LoadingBlock } from "@/components/app/state-block"

const STATUS_LABEL: Record<DeletionRequestStatus, string> = {
  requested: "Waiting for a decision",
  approved: "Approved",
  rejected: "Declined",
  completed: "Done",
  cancelled: "Cancelled",
}

const ENTITY_LABEL: Record<string, string> = {
  person: "Person", worker: "Worker", applicant: "Applicant", client: "Client", file: "Document", user: "Login",
}

type Decision = { request: DeletionRequest; decision: "approved" | "rejected" }

/**
 * Deletion requests (server/governance.php), decided here.
 *
 * Nobody removes a record on their own say so: one person asks with a reason,
 * a different owner decides. The endpoint enforces both halves; this screen
 * only stops offering a decision it knows would be refused. A delete that would
 * take other records with it is refused once with the counts, shown here, and
 * goes ahead only when the owner confirms having read them.
 */
export function DataRequestsTab() {
  const [status, setStatus] = useState<"requested" | "all">("requested")
  const { data, loading, error, reload } = useApi<{ requests: DeletionRequest[]; can_decide: boolean }>(
    `governance.php?action=deletions${status === "requested" ? "&status=requested" : ""}`,
    [status],
  )
  const [deciding, setDeciding] = useState<Decision | null>(null)
  const [note, setNote] = useState("")
  const [cascade, setCascade] = useState<Record<string, number> | null>(null)
  const [busy, setBusy] = useState(false)

  function open(d: Decision) {
    setDeciding(d)
    setNote("")
    setCascade(null)
  }

  async function decide(e: React.FormEvent) {
    e.preventDefault()
    if (!deciding) return
    setBusy(true)
    try {
      await api(`governance.php?action=decide_deletion&id=${deciding.request.id}`, {
        method: "POST",
        body: { decision: deciding.decision, note: note.trim(), ...(cascade ? { confirm_cascade: true } : {}) },
      })
      toast.success(deciding.decision === "approved" ? "Request approved and carried out." : "Request declined.")
      setDeciding(null)
      reload()
    } catch (err) {
      const detail = err instanceof ApiError ? (err.data as { cascade?: Record<string, number> } | null) : null
      if (err instanceof ApiError && err.status === 409 && detail?.cascade) {
        setCascade(detail.cascade)
      } else {
        toast.error(err instanceof ApiError ? err.message : "The decision could not be saved.")
      }
    } finally {
      setBusy(false)
    }
  }

  const requests = data?.requests ?? []

  return (
    <Card className="max-w-4xl">
      <div className="flex flex-col gap-3 border-b p-6 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h2 className="text-lg font-semibold">Data requests</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Requests to archive or delete a record. Somebody other than the person who asked must decide, and both
            halves are recorded with a reason. Archiving keeps everything linked to the record; prefer it.
          </p>
        </div>
        <Select value={status} onValueChange={(v) => setStatus(v as "requested" | "all")}>
          <SelectTrigger className="w-48" aria-label="Which requests"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="requested">Waiting for a decision</SelectItem>
            <SelectItem value="all">All requests</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {loading && !data && <div className="p-6"><LoadingBlock rows={3} /></div>}
      {error && !data && <div className="p-6"><ErrorBlock message={error} onRetry={reload} /></div>}
      {data && requests.length === 0 && (
        <div className="p-6">
          <EmptyBlock title="Nothing to decide" hint={status === "requested" ? "No deletion request is waiting." : "No deletion requests yet."} />
        </div>
      )}

      {requests.length > 0 && (
        <ul className="divide-y">
          {requests.map((r) => (
            <li key={r.id} className="grid gap-3 p-6 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
              <div className="min-w-0 space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold">{ENTITY_LABEL[r.entity_type] ?? r.entity_type}: {r.entity_label ?? `#${r.entity_id}`}</span>
                  <Badge variant="secondary">{r.disposition === "archive" ? "Archive" : "Delete"}</Badge>
                  <Badge variant="outline">{STATUS_LABEL[r.status] ?? r.status}</Badge>
                </div>
                <p className="text-sm">{r.reason}</p>
                <p className="text-xs text-muted-foreground">
                  Asked by {r.requested_by ?? "unknown"} on {shortDate(r.requested_at)}
                  {r.decided_at && `. Decided by ${r.decided_by ?? "unknown"} on ${shortDate(r.decided_at)}`}
                  {r.decision_note && `: ${r.decision_note}`}
                </p>
              </div>
              {r.status === "requested" && data?.can_decide && (
                r.is_mine ? (
                  <p className="text-sm text-muted-foreground sm:max-w-48">You asked for this, so somebody else must decide it.</p>
                ) : (
                  <div className="flex flex-wrap gap-2 sm:justify-end">
                    <Button size="sm" variant="outline" onClick={() => open({ request: r, decision: "rejected" })}>Decline</Button>
                    <Button size="sm" variant={r.disposition === "delete" ? "destructive" : "default"}
                      onClick={() => open({ request: r, decision: "approved" })}>
                      Approve
                    </Button>
                  </div>
                )
              )}
            </li>
          ))}
        </ul>
      )}

      <Dialog open={deciding !== null} onOpenChange={(o) => !o && setDeciding(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>
              {deciding?.decision === "approved"
                ? `${deciding.request.disposition === "archive" ? "Archive" : "Delete"} ${deciding.request.entity_label ?? "this record"}?`
                : "Decline this request?"}
            </DialogTitle>
            <DialogDescription>
              {deciding?.decision === "approved"
                ? deciding.request.disposition === "archive"
                  ? "The record is archived: hidden from lists, kept in full, and recoverable."
                  : "The record is deleted for good. This cannot be undone."
                : "The record stays as it is. Say why, so the person who asked knows."}
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={decide} className="space-y-4">
            {cascade && (
              <div className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm" role="alert">
                <p className="font-semibold">Deleting this also removes:</p>
                <ul className="mt-1 list-disc pl-5">
                  {Object.entries(cascade).map(([table, n]) => <li key={table}>{n} from {table.replace(/_/g, " ")}</li>)}
                </ul>
                <p className="mt-2">Confirm to go ahead, or decline and ask for an archive instead, which keeps them.</p>
              </div>
            )}
            <div className="space-y-1.5">
              <Label htmlFor="decision-note">Note{deciding?.decision === "approved" ? " (optional)" : ""}</Label>
              <Textarea id="decision-note" rows={3} maxLength={500} required={deciding?.decision === "rejected"}
                value={note} onChange={(e) => setNote(e.target.value)} />
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDeciding(null)}>Cancel</Button>
              <Button type="submit" disabled={busy || (deciding?.decision === "rejected" && note.trim() === "")}
                variant={deciding?.decision === "approved" && deciding.request.disposition === "delete" ? "destructive" : "default"}>
                {busy ? "Saving" : cascade ? "Confirm and delete" : deciding?.decision === "approved" ? "Approve" : "Decline"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  )
}
