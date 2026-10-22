import { useState } from "react"
import { Link } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { EmptyBlock, ErrorBlock, LoadingBlock } from "@/components/app/state-block"
import { useApi } from "@/hooks/use-api"
import { usePostAction } from "@/hooks/use-post-action"
import { dateOnly, personPath, RTW_METHOD_LABEL, RTW_OUTCOME_LABEL } from "@/lib/people"
import type { RightToWorkDue, Worker } from "@/lib/types"
import { RightToWorkDialog } from "@/components/people/rtw-dialog"

const WINDOWS = [["30", "30 days"], ["60", "60 days"], ["90", "90 days"], ["365", "A year"]] as const

/**
 * Right-to-work checks needing attention: not yet signed off, a follow-up
 * required, or a recheck coming up. Recording a check names its method, date,
 * outcome and follow-up date; signing it off names the reviewer.
 */
export function RightToWorkPanel({ canManage }: { canManage: boolean }) {
  const [days, setDays] = useState("60")
  const endpoint = `right-to-work.php?action=due&days=${days}`
  const { data, loading, error, reload } = useApi<{ items: RightToWorkDue[] }>(endpoint, [endpoint])
  const { run, busy } = usePostAction(reload)
  const [recording, setRecording] = useState<{ personId: number; name: string } | null>(null)
  const items = data?.items ?? []

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-full space-y-1.5 sm:w-40">
          <Label htmlFor="rtw-window">Rechecks due within</Label>
          <Select value={days} onValueChange={setDays}>
            <SelectTrigger id="rtw-window"><SelectValue /></SelectTrigger>
            <SelectContent>{WINDOWS.map(([v, l]) => <SelectItem key={v} value={v}>{l}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        {canManage && <RecordForPerson onPick={setRecording} />}
      </div>

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && items.length === 0 && (
        <EmptyBlock title="Nothing needs attention" hint="Every recorded check is signed off and no recheck is due in this window." />
      )}

      {items.length > 0 && (
        <ul className="divide-y divide-border rounded-lg border border-border bg-card">
          {items.map((c) => (
            <li key={c.id} className="grid gap-2 p-3 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1.5fr)_auto] sm:items-center sm:gap-4">
              <div className="min-w-0">
                <Link to={`${personPath(c.person_id)}?tab=checks`} className="font-medium text-primary underline-offset-2 hover:underline">{c.full_name}</Link>
                <p className="text-sm font-medium">{c.attention}</p>
              </div>
              <p className="text-sm text-muted-foreground">
                Checked {dateOnly(c.checked_on)} by {RTW_METHOD_LABEL[c.method] ?? c.method}, {RTW_OUTCOME_LABEL[c.outcome] ?? c.outcome}.
                {c.recheck_due ? ` Follow-up ${dateOnly(c.recheck_due)}${c.days_left !== null ? ` (${c.days_left < 0 ? `${-c.days_left} days ago` : `in ${c.days_left} days`})` : ""}.` : " No follow-up date."}
              </p>
              {canManage && (
                <div className="flex flex-wrap gap-2 sm:justify-end">
                  {!c.verified && c.outcome !== "pending" && (
                    <Button size="sm" disabled={busy} onClick={() => run(`right-to-work.php?action=verify&id=${c.id}`, {}, "Signed off.")}>Sign off</Button>
                  )}
                  <Button size="sm" variant="outline" onClick={() => setRecording({ personId: c.person_id, name: c.full_name })}>Record new check</Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      {recording && (
        <RightToWorkDialog
          personId={recording.personId}
          personName={recording.name}
          open
          onOpenChange={(o) => !o && setRecording(null)}
          onSaved={reload}
        />
      )}
    </div>
  )
}

/** Choose somebody from the workforce to record a check for. */
function RecordForPerson({ onPick }: { onPick: (p: { personId: number; name: string }) => void }) {
  const { data } = useApi<{ workers: Worker[] }>("workers.php")
  // One entry per person: somebody with two worker records is still one person.
  const people = [...new Map((data?.workers ?? []).filter((w) => w.person_id).map((w) => [w.person_id, w])).values()]
  return (
    <div className="w-full space-y-1.5 sm:ml-auto sm:w-64">
      <Label htmlFor="rtw-person">Record a check for</Label>
      <Select
        value=""
        onValueChange={(v) => {
          const w = people.find((p) => String(p.person_id) === v)
          if (w?.person_id) onPick({ personId: w.person_id, name: w.name })
        }}
      >
        <SelectTrigger id="rtw-person"><SelectValue placeholder="Choose a person" /></SelectTrigger>
        <SelectContent>
          {people.map((w) => <SelectItem key={w.id} value={String(w.person_id)}>{w.name}</SelectItem>)}
        </SelectContent>
      </Select>
    </div>
  )
}
