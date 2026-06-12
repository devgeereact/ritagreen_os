import { useState } from "react"
import { Plus, Clock, CheckCircle2, Check, Download } from "lucide-react"
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
import type { Timesheet, Worker } from "@/lib/types"
import { shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Totals = { pending_minutes: number; approved_minutes: number }
type Data = { timesheets: Timesheet[]; totals: Totals }

function hm(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m ? `${h}h ${m}m` : `${h}h`
}

export default function Timesheets() {
  const { data, loading, error, reload } = useApi<Data>("timesheets.php")
  const [open, setOpen] = useState(false)
  const [approving, setApproving] = useState<number | null>(null)

  async function approve(id: number) {
    setApproving(id)
    try {
      await api(`timesheets.php?action=approve&id=${id}`, { method: "POST", body: {} })
      toast.success("Timesheet approved.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not approve the entry.")
    } finally {
      setApproving(null)
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
                  "ritagreen-payroll-approved.csv",
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
          <NewEntryDialog open={open} onOpenChange={setOpen} onCreated={reload} />
        </div>
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-2">
            <Card>
              <CardContent className="flex items-center justify-between pt-6">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Pending approval</p>
                  <p className="mt-1 text-2xl font-bold">{hm(data.totals.pending_minutes)}</p>
                </div>
                <Clock className="size-7 text-amber-500" />
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
          </div>

          {data.timesheets.length === 0 ? (
            <EmptyBlock
              title="No timesheets yet"
              hint="Log a worker's hours for a day. Managers can then approve them for payroll."
              action={<Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> Add entry</Button>}
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
                        <Badge variant={t.status === "approved" ? "default" : "secondary"} className="capitalize">
                          {t.status}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        {t.status === "pending" && (
                          <Button size="sm" variant="outline" onClick={() => approve(t.id)} disabled={approving === t.id}>
                            <Check className="mr-1 size-4" /> Approve
                          </Button>
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
    </div>
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
