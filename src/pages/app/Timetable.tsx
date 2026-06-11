import { useState } from "react"
import { Plus, Clock, CheckCircle2 } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
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
import { useAuth } from "@/providers/auth-provider"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import type { Timesheet, Worker } from "@/lib/types"
import { shortDate } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Data = { timesheets: Timesheet[]; totals: { pending_minutes: number; approved_minutes: number } }

function hours(minutes: number) {
  return `${(minutes / 60).toFixed(1)}h`
}

export default function Timetable() {
  const { hasRole } = useAuth()
  const canApprove = hasRole("super_admin", "admin", "manager")
  const { data, loading, error, reload } = useApi<Data>("timesheets.php")
  const [open, setOpen] = useState(false)

  async function approve(id: number) {
    try {
      await api(`timesheets.php?action=approve&id=${id}`, { method: "POST", body: {} })
      toast.success("Approved.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not approve the entry.")
    }
  }

  const timesheets = data?.timesheets ?? []

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Timetable & time tracking</h1>
          <p className="text-sm text-muted-foreground">
            Hours logged per worker. Manager approval feeds payroll and billing.
          </p>
        </div>
        <NewEntryDialog open={open} onOpenChange={setOpen} onCreated={reload} />
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
                  <p className="mt-1 text-2xl font-bold">{hours(data.totals.pending_minutes)}</p>
                </div>
                <Clock className="size-7 text-amber-600" />
              </CardContent>
            </Card>
            <Card>
              <CardContent className="flex items-center justify-between pt-6">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Approved</p>
                  <p className="mt-1 text-2xl font-bold">{hours(data.totals.approved_minutes)}</p>
                </div>
                <CheckCircle2 className="size-7 text-primary" />
              </CardContent>
            </Card>
          </div>

          {timesheets.length === 0 ? (
            <EmptyBlock
              title="No time entries yet"
              hint="Log hours against workers. Managers approve before payroll."
              action={<Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> Log time</Button>}
            />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Worker</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Start</TableHead>
                    <TableHead>End</TableHead>
                    <TableHead>Hours</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {timesheets.map((t) => (
                    <TableRow key={t.id}>
                      <TableCell className="font-medium">{t.worker}</TableCell>
                      <TableCell className="text-muted-foreground">{shortDate(t.work_date)}</TableCell>
                      <TableCell className="text-muted-foreground">{t.start_time.slice(0, 5)}</TableCell>
                      <TableCell className="text-muted-foreground">{t.end_time.slice(0, 5)}</TableCell>
                      <TableCell>{hours(t.minutes)}</TableCell>
                      <TableCell>
                        <Badge variant={t.status === "approved" ? "default" : "secondary"} className="capitalize">
                          {t.status}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        {canApprove && t.status === "pending" && (
                          <Button size="sm" variant="outline" onClick={() => approve(t.id)}>
                            Approve
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
  const [submitting, setSubmitting] = useState(false)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const payload = {
      worker_id: Number(workerId),
      work_date: String(form.get("work_date") || ""),
      start_time: String(form.get("start_time") || ""),
      end_time: String(form.get("end_time") || ""),
    }
    if (!payload.worker_id || !payload.work_date || !payload.start_time || !payload.end_time) {
      toast.error("Pick a worker and fill the date and times.")
      return
    }
    setSubmitting(true)
    try {
      await api("timesheets.php", { method: "POST", body: payload })
      toast.success("Time logged.")
      onOpenChange(false)
      setWorkerId("")
      onCreated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not log the time.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> Log time</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Log time</DialogTitle>
          <DialogDescription>Record hours worked. A manager approves before payroll.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="worker">Worker</Label>
            <Select value={workerId} onValueChange={setWorkerId}>
              <SelectTrigger id="worker"><SelectValue placeholder="Choose a worker" /></SelectTrigger>
              <SelectContent>
                {(data?.workers ?? []).map((w) => (
                  <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="work_date">Date</Label>
            <Input id="work_date" name="work_date" type="date" required />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="start_time">Start</Label>
              <Input id="start_time" name="start_time" type="time" required />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="end_time">End</Label>
              <Input id="end_time" name="end_time" type="time" required />
            </div>
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Log time"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
