import { useCallback, useEffect, useRef, useState } from "react"
import FullCalendar from "@fullcalendar/react"
import dayGridPlugin from "@fullcalendar/daygrid"
import timeGridPlugin from "@fullcalendar/timegrid"
import interactionPlugin from "@fullcalendar/interaction"
import type { DatesSetArg } from "@fullcalendar/core"
import { Plus } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
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
import { api, ApiError } from "@/lib/api"
import type { Shift, Worker, Client } from "@/lib/types"
import { DIVISION_ACCENTS } from "@/lib/format"
import { ErrorBlock } from "@/components/app/state-block"

function isoDate(d: Date) {
  return d.toISOString().slice(0, 10)
}

export default function Scheduling() {
  const [range, setRange] = useState<{ from: string; to: string } | null>(null)
  const [shifts, setShifts] = useState<Shift[]>([])
  const [error, setError] = useState<string | null>(null)
  const [open, setOpen] = useState(false)

  const load = useCallback(async (from: string, to: string) => {
    setError(null)
    try {
      const res = await api<{ shifts: Shift[] }>(`scheduling.php?from=${from}&to=${to}`)
      setShifts(res.shifts)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load the rota.")
    }
  }, [])

  useEffect(() => {
    if (range) load(range.from, range.to)
  }, [range, load])

  function onDatesSet(arg: DatesSetArg) {
    const from = isoDate(arg.start)
    const to = isoDate(arg.end)
    setRange((prev) => (prev?.from === from && prev?.to === to ? prev : { from, to }))
  }

  const events = shifts.map((s) => ({
    id: String(s.id),
    title: `${s.title} — ${s.worker}`,
    start: s.starts_at.replace(" ", "T"),
    end: s.ends_at.replace(" ", "T"),
    backgroundColor: DIVISION_ACCENTS[s.division] ?? "#2e7d32",
    borderColor: DIVISION_ACCENTS[s.division] ?? "#2e7d32",
  }))

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Scheduling & dispatch</h1>
          <p className="text-sm text-muted-foreground">
            One rota across care, cleaning and security. Lapsed checks and clashes are blocked.
          </p>
        </div>
        <NewShiftDialog
          open={open}
          onOpenChange={setOpen}
          onCreated={() => range && load(range.from, range.to)}
        />
      </div>

      {error && <ErrorBlock message={error} onRetry={() => range && load(range.from, range.to)} />}

      <Card className="p-4 [&_.fc]:text-sm [&_.fc-toolbar-title]:text-base [&_.fc-toolbar-title]:font-semibold">
        <FullCalendar
          plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
          initialView="dayGridMonth"
          headerToolbar={{
            left: "prev,next today",
            center: "title",
            right: "dayGridMonth,timeGridWeek,timeGridDay",
          }}
          height="auto"
          events={events}
          datesSet={onDatesSet}
          nowIndicator
          firstDay={1}
        />
      </Card>

      <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
        {Object.entries(DIVISION_ACCENTS).map(([id, color]) => (
          <span key={id} className="flex items-center gap-1.5 capitalize">
            <span className="size-2.5 rounded-full" style={{ backgroundColor: color }} />
            {id}
          </span>
        ))}
      </div>
    </div>
  )
}

function NewShiftDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  onCreated: () => void
}) {
  const [workers, setWorkers] = useState<Worker[]>([])
  const [clients, setClients] = useState<Client[]>([])
  const [workerId, setWorkerId] = useState("")
  const [clientId, setClientId] = useState("")
  const [submitting, setSubmitting] = useState(false)

  // Load pick-lists when the dialog opens.
  useEffect(() => {
    if (!open) return
    api<{ workers: Worker[] }>("workers.php").then((r) => setWorkers(r.workers)).catch(() => {})
    api<{ clients: Client[] }>("clients.php").then((r) => setClients(r.clients)).catch(() => {})
  }, [open])

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const payload = {
      worker_id: Number(workerId),
      client_id: clientId || null,
      title: String(form.get("title") || "").trim(),
      site_name: String(form.get("site_name") || "").trim(),
      starts_at: String(form.get("starts_at") || ""),
      ends_at: String(form.get("ends_at") || ""),
    }
    if (!payload.worker_id || !payload.title || !payload.starts_at || !payload.ends_at) {
      toast.error("Pick a worker and add a title, start and end.")
      return
    }
    setSubmitting(true)
    try {
      await api("scheduling.php", { method: "POST", body: payload })
      toast.success("Shift scheduled.")
      onOpenChange(false)
      setWorkerId("")
      setClientId("")
      onCreated()
    } catch (err) {
      // Server returns 409 with a clear reason for clashes / lapsed compliance.
      toast.error(err instanceof ApiError ? err.message : "Could not schedule the shift.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> Schedule shift</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Schedule a shift</DialogTitle>
          <DialogDescription>
            The worker's division sets the rules. Clashes and lapsed DBS/SIA are refused.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="title">Title</Label>
            <Input id="title" name="title" placeholder="e.g. Night cover — Rosewood" required />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="worker">Worker</Label>
            <Select value={workerId} onValueChange={setWorkerId}>
              <SelectTrigger id="worker"><SelectValue placeholder="Choose a worker" /></SelectTrigger>
              <SelectContent>
                {workers.map((w) => (
                  <SelectItem key={w.id} value={String(w.id)}>
                    {w.name} · {w.role}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="client">Client (optional)</Label>
              <Select value={clientId} onValueChange={setClientId}>
                <SelectTrigger id="client"><SelectValue placeholder="None" /></SelectTrigger>
                <SelectContent>
                  {clients.map((c) => (
                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="site_name">Site (optional)</Label>
              <Input id="site_name" name="site_name" />
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="starts_at">Starts</Label>
              <Input id="starts_at" name="starts_at" type="datetime-local" required />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="ends_at">Ends</Label>
              <Input id="ends_at" name="ends_at" type="datetime-local" required />
            </div>
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Scheduling…" : "Schedule shift"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
