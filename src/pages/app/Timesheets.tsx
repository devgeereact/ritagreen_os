import { useMemo, useState } from "react"
import {
  Check, CheckCircle2, ChevronLeft, ChevronRight, CircleHelp, Clock, Download, History, MoreHorizontal,
  PencilLine, Plus, Send, X, XCircle,
} from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { exportCsv } from "@/lib/csv"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Card, CardContent } from "@/components/ui/card"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { useAuth } from "@/providers/auth-provider"
import { Textarea } from "@/components/ui/textarea"
import type { Shift, Timesheet, TimesheetStatus, Worker } from "@/lib/types"
import { shortDate, DIVISION_LABELS } from "@/lib/format"
import { addDays, todayIso, weekLabel, weekStart } from "@/lib/rota"
import { SERVICE_OPTIONS } from "@/lib/services"
import { cn } from "@/lib/utils"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Totals = Record<`${TimesheetStatus}_minutes`, number>
type Data = { timesheets: Timesheet[]; totals: Totals; truncated: boolean }
type HistoryEntry = {
  action: string
  detail: string | null
  reason: string | null
  before: Record<string, string | number> | null
  after: Record<string, string | number> | null
  by: string | null
  at: string
}

/** Radix Select needs a real value for "none"; translated to null for the server. */
const NO_SHIFT = "none"
const ALL = "all"

const STATUS_FILTERS: { value: string; label: string }[] = [
  { value: ALL, label: "All statuses" },
  { value: "pending", label: "Submitted, to decide" },
  { value: "draft", label: "Not submitted yet" },
  { value: "queried", label: "Queried" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
]

// A symbol and a word, never colour alone.
const STATUS_CHIP: Record<TimesheetStatus, { label: string; icon: typeof Check; tone: string }> = {
  draft: { label: "Not submitted", icon: PencilLine, tone: "border-dashed border-border text-muted-foreground" },
  pending: { label: "Submitted", icon: Send, tone: "border-border bg-muted" },
  queried: { label: "Queried", icon: CircleHelp, tone: "border-warning/40 bg-warning-soft" },
  approved: { label: "Approved", icon: CheckCircle2, tone: "border-success/40 bg-success-soft" },
  rejected: { label: "Rejected", icon: XCircle, tone: "border-destructive/40 bg-destructive/10" },
}

const HISTORY_WORDS: Record<string, string> = {
  "timesheet.create": "Logged by the office",
  "timesheet.from_clock": "Made from the clock",
  "timesheet.submit": "Submitted by the worker",
  "timesheet.approve": "Approved",
  "timesheet.reject": "Rejected",
  "timesheet.query": "Queried",
  "timesheet.correct": "Corrected",
}

function StatusChip({ status }: { status: TimesheetStatus }) {
  const c = STATUS_CHIP[status]
  const Icon = c.icon
  return (
    <span className={cn("inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium", c.tone)}>
      <Icon className="size-3.5" aria-hidden />
      {c.label}
    </span>
  )
}

function hm(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m ? `${h}h ${m}m` : `${h}h`
}

/** Which actions the office may take from a status (mirrors rg_timesheet_can on the server). */
const can = {
  approve: (s: TimesheetStatus) => s !== "approved",
  query: (s: TimesheetStatus) => s === "draft" || s === "pending",
  correct: (s: TimesheetStatus) => s !== "approved",
  reject: (s: TimesheetStatus) => s !== "rejected",
}

type Reasoned = { entry: Timesheet; kind: "query" | "reject" }

export default function Timesheets() {
  // Hours are payroll-zone data: server/timesheets.php gates every branch on
  // can_view_zone(..., 'payroll'), and src/lib/access.ts keeps the route to the
  // same roles. Approving is narrower still, and enforced on the server.
  const { hasRole } = useAuth()
  const canManage = hasRole("super_admin", "admin", "manager")

  // A week at a time, as the rota is. The dates stay editable for a longer pay
  // period.
  const [{ from, to }, setPeriod] = useState(() => {
    const m = weekStart(todayIso())
    return { from: m, to: addDays(m, 6) }
  })
  const [status, setStatus] = useState(ALL)
  const [service, setService] = useState(ALL)

  const endpoint = useMemo(() => {
    const q = new URLSearchParams()
    if (from) q.set("from", from)
    if (to) q.set("to", to)
    if (status !== ALL) q.set("status", status)
    if (service !== ALL) q.set("division", service)
    return `timesheets.php?${q.toString()}`
  }, [from, to, status, service])

  const { data, loading, error, reload } = useApi<Data>(endpoint, [endpoint])
  const [open, setOpen] = useState(false)
  const [deciding, setDeciding] = useState<number | null>(null)
  const [reasoned, setReasoned] = useState<Reasoned | null>(null)
  const [correcting, setCorrecting] = useState<Timesheet | null>(null)

  const isWeek = from === weekStart(from) && to === addDays(from, 6)
  const shiftWeek = (days: number) => setPeriod({ from: addDays(weekStart(from), days), to: addDays(weekStart(from), days + 6) })

  async function approve(id: number) {
    setDeciding(id)
    try {
      await api(`timesheets.php?action=approve&id=${id}`, { method: "POST", body: {} })
      toast.success("Approved. These hours will be paid.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not approve the entry.")
    } finally {
      setDeciding(null)
    }
  }

  async function decide(r: Reasoned, note: string) {
    setDeciding(r.entry.id)
    try {
      await api(`timesheets.php?action=${r.kind}&id=${r.entry.id}`, { method: "POST", body: { note } })
      toast.success(r.kind === "query" ? `Queried. ${r.entry.worker} has been told why.` : "Rejected. These hours will not be paid.")
      setReasoned(null)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not save the decision.")
    } finally {
      setDeciding(null)
    }
  }

  function actions(t: Timesheet) {
    if (!canManage) return null
    return (
      <div className="flex justify-end gap-2">
        {can.approve(t.status) && (
          <Button size="sm" variant="outline" onClick={() => approve(t.id)} disabled={deciding === t.id}>
            <Check className="size-4" /> Approve
          </Button>
        )}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button size="sm" variant="ghost" aria-label={`More for ${t.worker}, ${shortDate(t.work_date)}`}>
              <MoreHorizontal className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {can.query(t.status) && (
              <DropdownMenuItem onSelect={() => setReasoned({ entry: t, kind: "query" })}>
                <CircleHelp className="size-4" /> Query with the worker
              </DropdownMenuItem>
            )}
            <DropdownMenuItem onSelect={() => setCorrecting(t)}>
              {can.correct(t.status) ? <><PencilLine className="size-4" /> Correct</> : <><History className="size-4" /> History</>}
            </DropdownMenuItem>
            {can.reject(t.status) && (
              <DropdownMenuItem className="text-destructive" onSelect={() => setReasoned({ entry: t, kind: "reject" })}>
                <X className="size-4" /> Reject
              </DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    )
  }

  const awaiting = data ? data.totals.pending_minutes : 0
  const notYet = data ? data.totals.draft_minutes + data.totals.queried_minutes : 0

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Timesheets</h1>
          <p className="text-sm text-muted-foreground">
            Hours from the clock and from the office. Workers submit them; the office approves or queries them. Only approved hours are paid.
          </p>
        </div>
        <div className="flex items-center gap-2">
          {data && data.timesheets.some((t) => t.status === "approved") && (
            <Button
              variant="outline"
              onClick={() =>
                exportCsv(
                  `ritagreen-approved-hours-${from}-to-${to}.csv`,
                  data.timesheets
                    .filter((t) => t.status === "approved")
                    .map((t) => ({
                      worker: t.worker,
                      division: DIVISION_LABELS[t.division] ?? t.division,
                      date: t.work_date,
                      start: t.start_time,
                      end: t.end_time,
                      break_minutes: t.break_minutes,
                      paid_hours: (Math.max(t.minutes - t.break_minutes, 0) / 60).toFixed(2),
                    })),
                )
              }
            >
              <Download className="size-4" /> Export payroll CSV
            </Button>
          )}
          {canManage && <NewEntryDialog open={open} onOpenChange={setOpen} onCreated={reload} />}
        </div>
      </div>

      <Card>
        <CardContent className="flex flex-wrap items-end gap-3 pt-6">
          <div className="flex items-end gap-1">
            <Button variant="outline" size="icon" className="size-9" aria-label="Previous week" onClick={() => shiftWeek(-7)}>
              <ChevronLeft className="size-4" />
            </Button>
            <div className="grid gap-1.5">
              <Label htmlFor="from">From</Label>
              <Input id="from" type="date" className="h-9" value={from} max={to || undefined}
                onChange={(e) => setPeriod((p) => ({ ...p, from: e.target.value }))} />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="to">To</Label>
              <Input id="to" type="date" className="h-9" value={to} min={from || undefined}
                onChange={(e) => setPeriod((p) => ({ ...p, to: e.target.value }))} />
            </div>
            <Button variant="outline" size="icon" className="size-9" aria-label="Next week" onClick={() => shiftWeek(7)}>
              <ChevronRight className="size-4" />
            </Button>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="service">Service</Label>
            <Select value={service} onValueChange={setService}>
              <SelectTrigger id="service" className="h-9 w-40"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>All services</SelectItem>
                {SERVICE_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="status">Status</Label>
            <Select value={status} onValueChange={setStatus}>
              <SelectTrigger id="status" className="h-9 w-48"><SelectValue /></SelectTrigger>
              <SelectContent>
                {STATUS_FILTERS.map((f) => (
                  <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Button
            variant="ghost"
            className="h-9"
            onClick={() => {
              const m = weekStart(todayIso())
              setPeriod({ from: m, to: addDays(m, 6) })
              setStatus(ALL)
              setService(ALL)
            }}
          >
            This week
          </Button>
          {isWeek && <p className="pb-2 text-sm tabular-nums text-muted-foreground">{weekLabel(from)}</p>}
        </CardContent>
      </Card>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <Tile label="Submitted, to decide" value={hm(awaiting)} icon={<Clock className="size-6 text-warning" />} />
            <Tile label="Approved" value={hm(data.totals.approved_minutes)} icon={<CheckCircle2 className="size-6 text-success" />} />
            <Tile label="Not submitted or queried" value={hm(notYet)} icon={<CircleHelp className="size-6 text-muted-foreground" />} />
          </div>

          {/* The totals above are computed by the server over the whole filter, so
              they stay right even when the list below is cut off. */}
          {data.truncated && (
            <p className="text-sm text-muted-foreground">
              Showing the first 500 entries in this period. The totals above cover all of it. Narrow the dates or the status to see the rest.
            </p>
          )}

          {data.timesheets.length === 0 ? (
            <EmptyBlock
              title="Nothing in this period"
              hint="No hours are recorded between those dates with that status. Widen the dates, or log a worker's hours for a day."
              action={canManage ? <Button onClick={() => setOpen(true)}><Plus className="size-4" /> Add entry</Button> : undefined}
            />
          ) : (
            <>
              <Card className="hidden md:block">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Worker</TableHead>
                      <TableHead>Date</TableHead>
                      <TableHead>Hours</TableHead>
                      <TableHead className="text-right">Paid</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead></TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {data.timesheets.map((t) => (
                      <TableRow key={t.id}>
                        <TableCell>
                          <p className="font-medium">{t.worker}</p>
                          <p className="text-xs text-muted-foreground">{DIVISION_LABELS[t.division] ?? t.division}</p>
                        </TableCell>
                        <TableCell className="tabular-nums text-muted-foreground">{shortDate(t.work_date)}</TableCell>
                        <TableCell className="tabular-nums text-muted-foreground">
                          {t.start_time.slice(0, 5)} to {t.end_time.slice(0, 5)}
                          <div className="text-xs">
                            {t.shift_title ?? "No rota entry"}{t.source === "clock" ? " · from the clock" : ""}
                          </div>
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                          {hm(Math.max(t.minutes - t.break_minutes, 0))}
                          {t.break_minutes > 0 && <div className="text-xs text-muted-foreground">less {t.break_minutes}m break</div>}
                        </TableCell>
                        <TableCell>
                          <StatusChip status={t.status} />
                          {t.decision_note && (
                            <p className="mt-1 max-w-60 text-xs leading-snug text-muted-foreground">
                              {t.decision_note}{t.decided_by ? `, ${t.decided_by}` : ""}
                            </p>
                          )}
                        </TableCell>
                        <TableCell className="text-right">{actions(t)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </Card>
              <div className="space-y-2 md:hidden">
                {data.timesheets.map((t) => (
                  <Card key={t.id}>
                    <CardContent className="space-y-2 p-4">
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <p className="font-medium">{t.worker}</p>
                          <p className="text-xs tabular-nums text-muted-foreground">
                            {shortDate(t.work_date)}, {t.start_time.slice(0, 5)} to {t.end_time.slice(0, 5)}
                          </p>
                        </div>
                        <p className="text-sm font-semibold tabular-nums">{hm(Math.max(t.minutes - t.break_minutes, 0))}</p>
                      </div>
                      <StatusChip status={t.status} />
                      {t.decision_note && <p className="text-xs text-muted-foreground">{t.decision_note}</p>}
                      {actions(t)}
                    </CardContent>
                  </Card>
                ))}
              </div>
            </>
          )}
        </>
      )}

      <ReasonDialog
        target={reasoned}
        busy={reasoned !== null && deciding === reasoned.entry.id}
        onCancel={() => setReasoned(null)}
        onConfirm={decide}
      />
      <CorrectDialog entry={correcting} onClose={() => setCorrecting(null)} onSaved={reload} />
    </div>
  )
}

function Tile({ label, value, icon }: { label: string; value: string; icon: React.ReactNode }) {
  return (
    <Card>
      <CardContent className="flex items-center justify-between pt-6">
        <div>
          <p className="text-xs font-medium text-muted-foreground">{label}</p>
          <p className="mt-1 text-2xl font-bold tabular-nums">{value}</p>
        </div>
        {icon}
      </CardContent>
    </Card>
  )
}

/**
 * Querying and rejecting both ask for a reason and will not proceed without one.
 * The server requires it too: the reason is what the worker is shown.
 */
function ReasonDialog({
  target,
  busy,
  onCancel,
  onConfirm,
}: {
  target: Reasoned | null
  busy: boolean
  onCancel: () => void
  onConfirm: (r: Reasoned, note: string) => void
}) {
  const [note, setNote] = useState("")
  const entry = target?.entry
  const query = target?.kind === "query"

  return (
    <Dialog open={target !== null} onOpenChange={(o) => { if (!o) { setNote(""); onCancel() } }}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{query ? "Query these hours" : "Reject these hours"}</DialogTitle>
          <DialogDescription>
            {entry
              ? `${entry.worker}, ${shortDate(entry.work_date)}, ${hm(entry.minutes)}. ` +
                (query
                  ? "The worker sees your question in the staff app and by email, and can correct and submit again."
                  : "Rejected hours are not paid, and the entry stays on the record.")
              : ""}
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-2">
          <Label htmlFor="reason">{query ? "What should they check?" : "Reason"}</Label>
          <Textarea id="reason" value={note} maxLength={255} onChange={(e) => setNote(e.target.value)}
            placeholder={query ? "For example: the site says you left at 15:30" : "For example: logged against the wrong worker"} />
        </div>
        <DialogFooter>
          <Button variant="ghost" onClick={onCancel}>Cancel</Button>
          <Button variant={query ? "default" : "destructive"} disabled={busy || note.trim() === ""}
            onClick={() => target && onConfirm(target, note.trim())}>
            {busy ? "Saving…" : query ? "Send query" : "Reject"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/**
 * Correct an entry, with a reason, and see what has happened to it. Every
 * correction keeps the values before and after in the entry's history.
 */
function CorrectDialog({ entry, onClose, onSaved }: { entry: Timesheet | null; onClose: () => void; onSaved: () => void }) {
  return (
    <Dialog open={entry !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto">
        {entry && <CorrectBody key={entry.id} entry={entry} onClose={onClose} onSaved={onSaved} />}
      </DialogContent>
    </Dialog>
  )
}

function CorrectBody({ entry, onClose, onSaved }: { entry: Timesheet; onClose: () => void; onSaved: () => void }) {
  const history = useApi<{ history: HistoryEntry[] }>(`timesheets.php?id=${entry.id}`)
  const editable = can.correct(entry.status)
  const [date, setDate] = useState(entry.work_date)
  const [start, setStart] = useState(entry.start_time.slice(0, 5))
  const [end, setEnd] = useState(entry.end_time.slice(0, 5))
  const [brk, setBrk] = useState(String(entry.break_minutes))
  const [reason, setReason] = useState("")
  const [busy, setBusy] = useState(false)

  async function save() {
    setBusy(true)
    try {
      const r = await api<{ changed: boolean }>(`timesheets.php?action=correct&id=${entry.id}`, {
        method: "POST",
        body: { work_date: date, start_time: start, end_time: end, break_minutes: Number(brk) || 0, reason },
      })
      toast.success(r.changed ? "Corrected. The change and the reason are in the history." : "Nothing changed.")
      onSaved()
      onClose()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not correct the entry.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{editable ? "Correct hours" : "History"}</DialogTitle>
        <DialogDescription>
          {entry.worker}, {shortDate(entry.work_date)}.{" "}
          {editable ? "Give a reason; it is kept with the values before and after." : "Approved hours are not corrected in place. Reject the entry first if it is wrong."}
        </DialogDescription>
      </DialogHeader>
      {editable && (
        <div className="grid gap-3">
          <div className="grid grid-cols-2 gap-3">
            <div className="grid gap-1.5">
              <Label htmlFor="c-date">Date</Label>
              <Input id="c-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="c-break">Break (minutes)</Label>
              <Input id="c-break" type="number" min={0} step={5} value={brk} onChange={(e) => setBrk(e.target.value)} />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="c-start">Start</Label>
              <Input id="c-start" type="time" value={start} onChange={(e) => setStart(e.target.value)} />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="c-end">End</Label>
              <Input id="c-end" type="time" value={end} onChange={(e) => setEnd(e.target.value)} />
            </div>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="c-reason">Reason</Label>
            <Textarea id="c-reason" rows={2} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)}
              placeholder="For example: left early, agreed with the home" />
          </div>
          <Button onClick={save} disabled={busy || reason.trim() === ""}>{busy ? "Saving…" : "Save correction"}</Button>
        </div>
      )}
      <div className="space-y-2">
        <p className="text-sm font-semibold">History</p>
        {(history.data?.history ?? []).length === 0 ? (
          <p className="text-xs text-muted-foreground">{history.loading ? "Loading…" : "Nothing recorded yet."}</p>
        ) : (
          <ol className="space-y-2 border-l border-border pl-3">
            {(history.data?.history ?? []).map((h, i) => (
              <li key={i} className="text-sm">
                <p className="font-medium">{HISTORY_WORDS[h.action] ?? h.action}{h.by ? `, ${h.by}` : ""}</p>
                {h.reason && <p className="text-xs text-muted-foreground">{h.reason}</p>}
                {h.before && h.after && (
                  <p className="text-xs tabular-nums text-muted-foreground">
                    {Object.keys(h.after).map((k) => `${k.replace("_", " ")}: ${h.before?.[k] ?? "none"} to ${h.after?.[k]}`).join("; ")}
                  </p>
                )}
                <p className="text-xs tabular-nums text-muted-foreground">
                  {new Date(`${h.at.replace(" ", "T")}Z`).toLocaleString("en-GB", { dateStyle: "medium", timeStyle: "short" })}
                </p>
              </li>
            ))}
          </ol>
        )}
      </div>
    </>
  )
}

function NewEntryDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  onCreated: () => void
}) {
  const { data } = useApi<{ workers: Worker[] }>(open ? "workers.php" : null, [open])
  const [workerId, setWorkerId] = useState("")
  const [date, setDate] = useState("")
  const [start, setStart] = useState("")
  const [end, setEnd] = useState("")
  const [breakMinutes, setBreakMinutes] = useState("0")
  const [shiftId, setShiftId] = useState(NO_SHIFT)
  const [submitting, setSubmitting] = useState(false)

  // The worker's rota for that day, so hours can be tied to the shift they were
  // worked on. Only asked for once both the worker and the date are known —
  // before that there is nothing to look up.
  const rota = useApi<{ shifts: Shift[] }>(
    open && workerId && date ? `scheduling.php?from=${date}&to=${date}` : null,
    [open, workerId, date],
  )
  const theirShifts = (rota.data?.shifts ?? []).filter(
    (sh) => sh.worker_id === Number(workerId) && sh.status !== "cancelled",
  )

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    if (!workerId || !date || !start || !end) {
      toast.error("Pick a worker and fill in the date and times.")
      return
    }
    setSubmitting(true)
    try {
      await api("timesheets.php", {
        method: "POST",
        body: {
          worker_id: Number(workerId),
          work_date: date,
          start_time: start,
          end_time: end,
          break_minutes: Number(breakMinutes) || 0,
          shift_id: shiftId === NO_SHIFT ? null : Number(shiftId),
        },
      })
      toast.success("Timesheet logged.")
      onOpenChange(false)
      setWorkerId(""); setDate(""); setStart(""); setEnd(""); setBreakMinutes("0"); setShiftId(NO_SHIFT)
      onCreated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not log the timesheet.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="size-4" /> Add entry</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Log hours</DialogTitle>
          <DialogDescription>Record a worker's hours for one day. Duration is worked out for you.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="worker">Worker</Label>
            <Select value={workerId} onValueChange={setWorkerId}>
              <SelectTrigger id="worker"><SelectValue placeholder="Select a worker" /></SelectTrigger>
              <SelectContent>
                {(data?.workers ?? []).map((w) => (
                  <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="date">Date</Label>
            <Input id="date" type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div className="grid gap-2">
              <Label htmlFor="start">Start</Label>
              <Input id="start" type="time" value={start} onChange={(e) => setStart(e.target.value)} required />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="end">End</Label>
              <Input id="end" type="time" value={end} onChange={(e) => setEnd(e.target.value)} required />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="break">Unpaid break (minutes)</Label>
            <Input
              id="break"
              type="number"
              min={0}
              step={5}
              value={breakMinutes}
              onChange={(e) => setBreakMinutes(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              An end time on or before the start is an overnight shift, not an error. Pay is on the
              hours worked less the break.
            </p>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="shift">Shift (optional)</Label>
            <Select value={shiftId} onValueChange={setShiftId} disabled={!workerId || !date}>
              <SelectTrigger id="shift">
                <SelectValue placeholder="Pick a worker and a date first" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NO_SHIFT}>No linked shift</SelectItem>
                {theirShifts.map((sh) => (
                  <SelectItem key={sh.id} value={String(sh.id)}>
                    {sh.title} · {sh.starts_at.slice(11, 16)}–{sh.ends_at.slice(11, 16)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {workerId && date && theirShifts.length === 0 && !rota.loading
                ? "Nothing on the rota for them that day. Leave it unlinked — a call-out does not have a shift."
                : "Linking the hours to the rota entry stops the same shift being claimed twice."}
            </p>
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Logging…" : "Log hours"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
