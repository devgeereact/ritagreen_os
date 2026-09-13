import { useMemo, useState } from "react"
import { Plus, Clock, CheckCircle2, Check, Download, X, XCircle } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { exportCsv } from "@/lib/csv"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
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
import type { Timesheet, TimesheetStatus, Worker } from "@/lib/types"
import { shortDate, monthBounds, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Totals = { pending_minutes: number; approved_minutes: number; rejected_minutes: number }
type Data = { timesheets: Timesheet[]; totals: Totals }

const STATUS_FILTERS: { value: string; label: string }[] = [
  { value: "all", label: "All statuses" },
  { value: "pending", label: "Awaiting approval" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
]

const STATUS_BADGE: Record<TimesheetStatus, "default" | "secondary" | "destructive"> = {
  approved: "default",
  pending: "secondary",
  rejected: "destructive",
}

function hm(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m ? `${h}h ${m}m` : `${h}h`
}

export default function Timesheets() {
  // Hours are payroll-zone data: server/timesheets.php gates every branch on
  // can_view_zone(..., 'payroll'), and src/lib/access.ts keeps the route to the
  // same roles. Approving is narrower still, and enforced on the server.
  const { hasRole } = useAuth()
  const canManage = hasRole("super_admin", "admin", "manager")

  // Default to the current month rather than "the most recent 300 rows ever".
  // Approving hours is a periodic job, so the page opens on a period.
  const [{ from, to }, setPeriod] = useState(() => monthBounds(new Date()))
  const [status, setStatus] = useState("all")

  const endpoint = useMemo(() => {
    const q = new URLSearchParams()
    if (from) q.set("from", from)
    if (to) q.set("to", to)
    if (status !== "all") q.set("status", status)
    return `timesheets.php?${q.toString()}`
  }, [from, to, status])

  const { data, loading, error, reload } = useApi<Data>(endpoint, [endpoint])
  const [open, setOpen] = useState(false)
  const [deciding, setDeciding] = useState<number | null>(null)
  const [rejecting, setRejecting] = useState<Timesheet | null>(null)

  async function approve(id: number) {
    setDeciding(id)
    try {
      await api(`timesheets.php?action=approve&id=${id}`, { method: "POST", body: {} })
      toast.success("Timesheet approved.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not approve the entry.")
    } finally {
      setDeciding(null)
    }
  }

  async function reject(id: number, note: string) {
    setDeciding(id)
    try {
      await api(`timesheets.php?action=reject&id=${id}`, { method: "POST", body: { note } })
      toast.success("Timesheet rejected.")
      setRejecting(null)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not reject the entry.")
    } finally {
      setDeciding(null)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Timesheets</h1>
          <p className="text-sm text-muted-foreground">
            Log worker hours and approve them for payroll across the group.
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
                      hours: (t.minutes / 60).toFixed(2),
                    })),
                )
              }
            >
              <Download className="mr-1 size-4" /> Export payroll CSV
            </Button>
          )}
          {canManage && <NewEntryDialog open={open} onOpenChange={setOpen} onCreated={reload} />}
        </div>
      </div>

      <Card>
        <CardContent className="flex flex-wrap items-end gap-4 pt-6">
          <div className="grid gap-2">
            <Label htmlFor="from">From</Label>
            <Input
              id="from"
              type="date"
              value={from}
              max={to || undefined}
              onChange={(e) => setPeriod((p) => ({ ...p, from: e.target.value }))}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="to">To</Label>
            <Input
              id="to"
              type="date"
              value={to}
              min={from || undefined}
              onChange={(e) => setPeriod((p) => ({ ...p, to: e.target.value }))}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="status">Status</Label>
            <Select value={status} onValueChange={setStatus}>
              <SelectTrigger id="status" className="w-48"><SelectValue /></SelectTrigger>
              <SelectContent>
                {STATUS_FILTERS.map((f) => (
                  <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Button
            variant="ghost"
            onClick={() => {
              setPeriod(monthBounds(new Date()))
              setStatus("all")
            }}
          >
            This month
          </Button>
        </CardContent>
      </Card>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <Card>
              <CardContent className="flex items-center justify-between pt-6">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Pending approval</p>
                  <p className="mt-1 text-2xl font-bold">{hm(data.totals.pending_minutes)}</p>
                </div>
                <Clock className="size-7 text-warning" />
              </CardContent>
            </Card>
            <Card>
              <CardContent className="flex items-center justify-between pt-6">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Approved</p>
                  <p className="mt-1 text-2xl font-bold">{hm(data.totals.approved_minutes)}</p>
                </div>
                <CheckCircle2 className="size-7 text-primary" />
              </CardContent>
            </Card>
            <Card>
              <CardContent className="flex items-center justify-between pt-6">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Rejected</p>
                  <p className="mt-1 text-2xl font-bold">{hm(data.totals.rejected_minutes)}</p>
                </div>
                <XCircle className="size-7 text-destructive" />
              </CardContent>
            </Card>
          </div>

          {data.timesheets.length === 0 ? (
            <EmptyBlock
              title="Nothing in this period"
              hint="No hours are recorded between those dates with that status. Widen the dates, or log a worker's hours for a day."
              action={canManage ? <Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> Add entry</Button> : undefined}
            />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Worker</TableHead>
                    <TableHead>Division</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Hours</TableHead>
                    <TableHead className="text-right">Duration</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.timesheets.map((t) => (
                    <TableRow key={t.id}>
                      <TableCell className="font-medium">{t.worker}</TableCell>
                      <TableCell className="text-muted-foreground">{DIVISION_LABELS[t.division] ?? t.division}</TableCell>
                      <TableCell className="text-muted-foreground">{shortDate(t.work_date)}</TableCell>
                      <TableCell className="text-muted-foreground">{t.start_time}–{t.end_time}</TableCell>
                      <TableCell className="text-right">{hm(t.minutes)}</TableCell>
                      <TableCell>
                        <Badge variant={STATUS_BADGE[t.status]} className="capitalize">
                          {t.status}
                        </Badge>
                        {t.decision_note && (
                          <p className="mt-1 max-w-60 text-xs leading-snug text-muted-foreground">
                            {t.decision_note}
                            {t.decided_by ? ` — ${t.decided_by}` : ""}
                          </p>
                        )}
                      </TableCell>
                      <TableCell className="text-right">
                        {canManage && t.status === "pending" && (
                          <div className="flex justify-end gap-2">
                            <Button size="sm" variant="outline" onClick={() => approve(t.id)} disabled={deciding === t.id}>
                              <Check className="mr-1 size-4" /> Approve
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              className="text-destructive hover:text-destructive"
                              onClick={() => setRejecting(t)}
                              disabled={deciding === t.id}
                            >
                              <X className="mr-1 size-4" /> Reject
                            </Button>
                          </div>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Card>
          )}
        </>
      )}

      <RejectDialog
        entry={rejecting}
        busy={rejecting !== null && deciding === rejecting.id}
        onCancel={() => setRejecting(null)}
        onConfirm={reject}
      />
    </div>
  )
}

/**
 * Rejecting asks for a reason and will not proceed without one.
 *
 * The server requires it too. A rejection a worker cannot understand is a query
 * to the office, not a decision, and the reason is what they are shown.
 */
function RejectDialog({
  entry,
  busy,
  onCancel,
  onConfirm,
}: {
  entry: Timesheet | null
  busy: boolean
  onCancel: () => void
  onConfirm: (id: number, note: string) => void
}) {
  const [note, setNote] = useState("")

  return (
    <Dialog
      open={entry !== null}
      onOpenChange={(o) => {
        if (!o) {
          setNote("")
          onCancel()
        }
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Reject these hours</DialogTitle>
          <DialogDescription>
            {entry
              ? `${entry.worker} — ${shortDate(entry.work_date)}, ${hm(entry.minutes)}. Rejected hours are not paid, and the entry stays on the record.`
              : ""}
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-2">
          <Label htmlFor="reason">Reason</Label>
          <Textarea
            id="reason"
            value={note}
            maxLength={255}
            onChange={(e) => setNote(e.target.value)}
            placeholder="e.g. Logged against the wrong worker — this was Bola's shift."
          />
        </div>
        <DialogFooter>
          <Button variant="ghost" onClick={onCancel}>Cancel</Button>
          <Button
            variant="destructive"
            disabled={busy || note.trim() === ""}
            onClick={() => entry && onConfirm(entry.id, note.trim())}
          >
            {busy ? "Rejecting…" : "Reject"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
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
  const [submitting, setSubmitting] = useState(false)

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
        body: { worker_id: Number(workerId), work_date: date, start_time: start, end_time: end },
      })
      toast.success("Timesheet logged.")
      onOpenChange(false)
      setWorkerId(""); setDate(""); setStart(""); setEnd("")
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
        <Button><Plus className="mr-1 size-4" /> Add entry</Button>
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
