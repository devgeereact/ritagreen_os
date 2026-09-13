import { useEffect, useState } from "react"
import { MapPin, AlertOctagon, CheckCircle2, CircleDashed, RefreshCw, ShieldCheck } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from "@/components/ui/table"
import {
  Dialog, DialogContent, DialogHeader, DialogTitle,
} from "@/components/ui/dialog"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { useAuth } from "@/providers/auth-provider"
import type { FieldShift, ShiftEvent } from "@/lib/types"
import { DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const STATUS_META: Record<FieldShift["field_status"], { label: string; variant: "default" | "secondary" | "destructive" | "outline" }> = {
  scheduled: { label: "Scheduled", variant: "outline" },
  late: { label: "Late", variant: "secondary" },
  on_site: { label: "On site", variant: "default" },
  overrunning: { label: "Overrunning", variant: "secondary" },
  completed: { label: "Completed", variant: "default" },
  no_show: { label: "No-show", variant: "destructive" },
  unfilled: { label: "Unfilled", variant: "destructive" },
}

function hm(dt: string) {
  return dt.slice(11, 16)
}

/**
 * How often the board re-reads itself.
 *
 * It is titled "live" and "today" and it fetched exactly once, on mount. A duty
 * manager leaving it open saw a frozen page: a worker clocking in, a check call
 * landing late, a panic alert being raised -- none of it arrived until somebody
 * reloaded the browser. Thirty seconds is fast enough to act on and slow enough
 * not to hammer the endpoint from every office screen.
 */
const REFRESH_MS = 30_000

export default function FieldOps() {
  // keepPreviousOnError: this board polls, and a single dropped request must not
  // blank the page a duty manager is watching. The error shows beside the rows.
  const { data, loading, error, reload } = useApi<{ shifts: FieldShift[] }>(
    "field.php?action=live",
    [],
    { keepPreviousOnError: true },
  )
  const [openShift, setOpenShift] = useState<FieldShift | null>(null)
  const [refreshedAt, setRefreshedAt] = useState<Date | null>(null)
  // Acknowledging is the office responding to an alert, and the server agrees:
  // panic_ack is the one event kind a worker cannot post.
  const { hasRole } = useAuth()
  const canAcknowledge = hasRole("super_admin", "admin", "manager")
  const [acking, setAcking] = useState<number | null>(null)

  async function acknowledge(shift: FieldShift) {
    setAcking(shift.id)
    try {
      await api("field.php", {
        method: "POST",
        body: { shift_id: shift.id, kind: "panic_ack" },
      })
      toast.success("Alert acknowledged.")
      reload()
      setRefreshedAt(new Date())
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not acknowledge the alert.")
    } finally {
      setAcking(null)
    }
  }

  useEffect(() => {
    const id = setInterval(() => {
      reload()
      setRefreshedAt(new Date())
    }, REFRESH_MS)
    return () => clearInterval(id)
  }, [reload])

  const shifts = data?.shifts ?? []
  const panics = shifts.filter((s) => s.panic)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Field operations — today</h1>
          <p className="text-sm text-muted-foreground">Live on-site status across every division. Tap a shift for its log.</p>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-xs text-muted-foreground">
            {refreshedAt
              ? `Updated ${refreshedAt.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })}`
              : "Refreshes every 30 seconds"}
          </span>
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              reload()
              setRefreshedAt(new Date())
            }}
          >
            <RefreshCw className="mr-1 size-4" /> Refresh
          </Button>
        </div>
      </div>

      {panics.length > 0 && (
        <Card className="border-destructive">
          <CardContent className="space-y-3 pt-6">
            <p className="flex items-center gap-3 text-sm font-semibold text-destructive">
              <AlertOctagon className="size-6 shrink-0" />
              {panics.length} unacknowledged panic alert{panics.length === 1 ? "" : "s"}
            </p>
            <div className="space-y-2">
              {panics.map((p) => (
                <div key={p.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-destructive/30 p-3">
                  <button type="button" className="text-left" onClick={() => setOpenShift(p)}>
                    <span className="text-sm font-medium underline-offset-4 hover:underline">
                      {p.worker || "Unfilled"} — {p.title}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                      {p.client || p.site_name || "No site recorded"} · {hm(p.starts_at)}–{hm(p.ends_at)}
                    </span>
                  </button>
                  {canAcknowledge && (
                    <Button size="sm" variant="destructive" disabled={acking === p.id} onClick={() => acknowledge(p)}>
                      <ShieldCheck className="mr-1 size-4" />
                      {acking === p.id ? "Acknowledging…" : "Acknowledge"}
                    </Button>
                  )}
                </div>
              ))}
            </div>
            <p className="text-xs text-muted-foreground">
              Acknowledging records who responded and when, on the shift's own timeline. It clears the
              alert from this board; it does not close the incident.
            </p>
          </CardContent>
        </Card>
      )}

      {/* Only on the first load. A 30-second poll sets loading each time, and
          replacing the board with skeletons twice a minute would make it
          unreadable -- the point of refreshing is that the table stays put and
          the rows change underneath. */}
      {loading && !data && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && (
        shifts.length === 0 ? (
          <EmptyBlock title="Nothing on today" hint="Today's shifts and their on-site status will show here." />
        ) : (
          <Card>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Time</TableHead>
                  <TableHead>Shift</TableHead>
                  <TableHead>Worker</TableHead>
                  <TableHead>Where</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {shifts.map((s) => (
                  <TableRow key={s.id} className="cursor-pointer" onClick={() => setOpenShift(s)}>
                    <TableCell className="whitespace-nowrap text-muted-foreground">{hm(s.starts_at)}–{hm(s.ends_at)}</TableCell>
                    <TableCell className="font-medium">{s.title}<div className="text-xs text-muted-foreground">{DIVISION_LABELS[s.division] ?? s.division}</div></TableCell>
                    <TableCell>{s.worker || <span className="text-destructive">Unfilled</span>}</TableCell>
                    <TableCell className="text-muted-foreground">{s.client || s.site_name || "—"}</TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        <Badge variant={STATUS_META[s.field_status]?.variant ?? "outline"}>
                          {STATUS_META[s.field_status]?.label ?? s.field_status}
                        </Badge>
                        {s.panic && <AlertOctagon className="size-4 text-destructive" />}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        )
      )}

      <TimelineDialog shift={openShift} onOpenChange={(o) => !o && setOpenShift(null)} />
    </div>
  )
}

const EVENT_ICON: Record<string, React.ComponentType<{ className?: string }>> = {
  clock_in: CheckCircle2, clock_out: CheckCircle2, check_in: MapPin,
  panic: AlertOctagon, panic_ack: ShieldCheck, note: CircleDashed,
  task: CircleDashed, signoff: CheckCircle2,
}

function TimelineDialog({ shift, onOpenChange }: { shift: FieldShift | null; onOpenChange: (o: boolean) => void }) {
  const { data, loading } = useApi<{ events: ShiftEvent[]; location_withheld: boolean }>(
    shift ? `field.php?shift_id=${shift.id}` : null,
    [shift?.id],
  )
  return (
    <Dialog open={!!shift} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[80vh] overflow-y-auto">
        <DialogHeader><DialogTitle>{shift?.title} — field log</DialogTitle></DialogHeader>
        {loading && <LoadingBlock rows={3} />}
        {data?.location_withheld && (
          <p className="rounded-md border border-dashed px-3 py-2 text-xs text-muted-foreground">
            Locations are not shown on your account. Where a colleague physically was is
            employment-record detail, kept to the office.
          </p>
        )}
        {data && (
          data.events.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">No field events recorded yet.</p>
          ) : (
            <ul className="space-y-3">
              {data.events.map((e) => {
                const Icon = EVENT_ICON[e.kind] ?? CircleDashed
                return (
                  <li key={e.id} className="flex gap-3">
                    <span className={`mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg ${e.kind === "panic" ? "bg-destructive/10 text-destructive" : "bg-primary/10 text-primary"}`}>
                      <Icon className="size-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-baseline justify-between gap-2">
                        <p className="font-medium capitalize">{e.kind.replace("_", " ")}{e.signer ? ` — ${e.signer}` : ""}</p>
                        <span className="shrink-0 text-xs text-muted-foreground">{e.created_at?.slice(0, 16).replace("T", " ")}</span>
                      </div>
                      {e.note && <p className="text-sm text-muted-foreground">{e.note}</p>}
                      <div className="text-xs text-muted-foreground">
                        {e.worker || ""}{e.lat && e.lng ? ` · 📍 ${Number(e.lat).toFixed(4)}, ${Number(e.lng).toFixed(4)}` : ""}
                      </div>
                    </div>
                  </li>
                )
              })}
            </ul>
          )
        )}
      </DialogContent>
    </Dialog>
  )
}
