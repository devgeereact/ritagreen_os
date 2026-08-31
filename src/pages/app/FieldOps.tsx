import { useState } from "react"
import { MapPin, AlertOctagon, CheckCircle2, CircleDashed } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from "@/components/ui/table"
import {
  Dialog, DialogContent, DialogHeader, DialogTitle,
} from "@/components/ui/dialog"
import { useApi } from "@/hooks/use-api"
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

export default function FieldOps() {
  const { data, loading, error, reload } = useApi<{ shifts: FieldShift[] }>("field.php?action=live")
  const [openShift, setOpenShift] = useState<FieldShift | null>(null)

  const shifts = data?.shifts ?? []
  const panics = shifts.filter((s) => s.panic)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Field operations — today</h1>
          <p className="text-sm text-muted-foreground">Live on-site status across every division. Tap a shift for its log.</p>
        </div>
      </div>

      {panics.length > 0 && (
        <Card className="border-destructive">
          <CardContent className="flex items-center gap-3 pt-6 text-destructive">
            <AlertOctagon className="size-6" />
            <p className="text-sm font-semibold">
              {panics.length} active panic alert{panics.length === 1 ? "" : "s"} — {panics.map((p) => p.worker || p.title).join(", ")}
            </p>
          </CardContent>
        </Card>
      )}

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
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
                        <Badge variant={STATUS_META[s.field_status].variant}>{STATUS_META[s.field_status].label}</Badge>
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
  panic: AlertOctagon, note: CircleDashed, task: CircleDashed, signoff: CheckCircle2,
}

function TimelineDialog({ shift, onOpenChange }: { shift: FieldShift | null; onOpenChange: (o: boolean) => void }) {
  const { data, loading } = useApi<{ events: ShiftEvent[] }>(shift ? `field.php?shift_id=${shift.id}` : null, [shift?.id])
  return (
    <Dialog open={!!shift} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[80vh] overflow-y-auto">
        <DialogHeader><DialogTitle>{shift?.title} — field log</DialogTitle></DialogHeader>
        {loading && <LoadingBlock rows={3} />}
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
