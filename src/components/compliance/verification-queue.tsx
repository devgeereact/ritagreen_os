import { useMemo, useState } from "react"
import { Link } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { EmptyBlock, ErrorBlock, LoadingBlock } from "@/components/app/state-block"
import { useApi } from "@/hooks/use-api"
import { usePostAction } from "@/hooks/use-post-action"
import { personPath, serviceName, VERIFICATION_LABEL } from "@/lib/people"
import type { VerificationQueueItem } from "@/lib/types"
import { FileLink, UploadButton } from "@/components/people/shared"
import { ReasonDialog, VerifyDialog } from "@/components/people/action-dialogs"

const SERVICES = ["care", "cleaning", "security", "academy"] as const

/**
 * Everything waiting on somebody, oldest first.
 *
 * The server filters by the caller's service scope, so a manager confined to
 * security sees security people only. Verifying records what was checked and
 * who checked it; nothing here sets a compliance status.
 */
export function VerificationQueue({ canManage }: { canManage: boolean }) {
  const [service, setService] = useState("all")
  const [requirement, setRequirement] = useState("all")
  const endpoint = `verifications.php?action=queue${service !== "all" ? `&service=${service}` : ""}`
  const { data, loading, error, reload } = useApi<{ items: VerificationQueueItem[] }>(endpoint, [endpoint])
  const { run, busy } = usePostAction(reload)
  const [verifying, setVerifying] = useState<VerificationQueueItem | null>(null)
  const [rejecting, setRejecting] = useState<VerificationQueueItem | null>(null)

  const items = useMemo(() => data?.items ?? [], [data])
  const requirements = useMemo(() => {
    const seen = new Map<string, string>()
    for (const i of items) seen.set(i.requirement_key, i.requirement)
    return [...seen.entries()].sort((a, b) => a[1].localeCompare(b[1]))
  }, [items])
  const shown = requirement === "all" ? items : items.filter((i) => i.requirement_key === requirement)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-full space-y-1.5 sm:w-48">
          <Label htmlFor="queue-service">Service</Label>
          <Select value={service} onValueChange={(v) => { setService(v); setRequirement("all") }}>
            <SelectTrigger id="queue-service"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All services</SelectItem>
              {SERVICES.map((s) => <SelectItem key={s} value={s}>{serviceName(s)}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>
        <div className="w-full space-y-1.5 sm:w-56">
          <Label htmlFor="queue-type">Type of check</Label>
          <Select value={requirement} onValueChange={setRequirement}>
            <SelectTrigger id="queue-type"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All checks</SelectItem>
              {requirements.map(([key, label]) => <SelectItem key={key} value={key}>{label}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>
        <p className="text-sm text-muted-foreground sm:ml-auto">{shown.length} waiting, oldest first</p>
      </div>

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && shown.length === 0 && (
        <EmptyBlock title="Nothing waiting" hint="Checks appear here when evidence is requested or arrives." />
      )}

      {shown.length > 0 && (
        <ul className="divide-y divide-border rounded-lg border border-border bg-card">
          {shown.map((i) => (
            <li key={i.id} className="grid gap-2 p-3 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] sm:items-center sm:gap-4">
              <div className="min-w-0">
                <Link to={`${personPath(i.person_id)}?tab=checks`} className="font-medium text-primary underline-offset-2 hover:underline">
                  {i.full_name}
                </Link>
                <p className="text-sm">{i.requirement}{i.subject_label ? `, ${i.subject_label}` : ""}</p>
                <p className="text-xs text-muted-foreground">{i.services.map(serviceName).join(", ")}</p>
              </div>
              <div className="text-sm">
                <p>
                  <span className="font-medium">{VERIFICATION_LABEL[i.status]}</span>
                  <span className="text-muted-foreground">, waiting {i.waiting_days ?? 0} {i.waiting_days === 1 ? "day" : "days"}</span>
                </p>
                <FileLink id={i.evidence_file_id} name={i.evidence_file_id ? "Evidence" : null} />
              </div>
              {canManage && (
                <div className="flex flex-wrap gap-2 sm:justify-end">
                  {i.status === "requested" && (
                    <UploadButton personId={i.person_id} category="certificate" label="Upload evidence"
                      onUploaded={(fileId) => { void run(`verifications.php?action=received&id=${i.id}`, { evidence_file_id: fileId }, "Evidence received.") }} />
                  )}
                  {(i.status === "received" || i.status === "under_review") && (
                    <Button size="sm" disabled={busy} onClick={() => setVerifying(i)}>Verify</Button>
                  )}
                  <Button size="sm" variant="outline" disabled={busy} onClick={() => setRejecting(i)}>Reject</Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      <VerifyDialog
        open={verifying !== null}
        onOpenChange={(o) => !o && setVerifying(null)}
        requirement={verifying ? `${verifying.requirement} for ${verifying.full_name}` : ""}
        busy={busy}
        onConfirm={(body) => verifying ? run(`verifications.php?action=verify&id=${verifying.id}`, body, "Verified.") : false}
      />
      <ReasonDialog
        open={rejecting !== null}
        onOpenChange={(o) => !o && setRejecting(null)}
        title={rejecting ? `Reject ${rejecting.requirement}` : "Reject"}
        description="Say what was wrong, so the person can be told and can put it right."
        confirm="Reject"
        destructive
        busy={busy}
        onConfirm={(reason) => rejecting ? run(`verifications.php?action=reject&id=${rejecting.id}`, { reason }, "Rejected.") : false}
      />
    </div>
  )
}
