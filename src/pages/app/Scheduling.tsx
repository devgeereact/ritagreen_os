import { useCallback, useEffect, useState } from "react"
import FullCalendar from "@fullcalendar/react"
import dayGridPlugin from "@fullcalendar/daygrid"
import timeGridPlugin from "@fullcalendar/timegrid"
import interactionPlugin from "@fullcalendar/interaction"
import type { DatesSetArg } from "@fullcalendar/core"
import { Plus, Radio, Sparkles, CalendarPlus } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
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
import type { Shift, Client, Skill, MatchWorker, ShiftTemplate } from "@/lib/types"
import { DIVISION_ACCENTS, DIVISION_LABELS } from "@/lib/format"
import { ErrorBlock } from "@/components/app/state-block"

function isoDate(d: Date) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, "0")
  const day = String(d.getDate()).padStart(2, "0")
  return `${y}-${m}-${day}`
}

const DIVISIONS = [
  { value: "care", label: "Care" },
  { value: "academy", label: "Academy" },
  { value: "cleaning", label: "Cleaning" },
  { value: "security", label: "Security" },
]

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

  const reload = () => range && load(range.from, range.to)

  function onDatesSet(arg: DatesSetArg) {
    const from = isoDate(arg.start)
    const to = isoDate(arg.end)
    setRange((prev) => (prev?.from === from && prev?.to === to ? prev : { from, to }))
  }

  async function broadcast(id: number) {
    try {
      const r = await api<{ offered: number }>(`scheduling.php?action=broadcast&id=${id}`, { method: "POST", body: {} })
      toast.success(`Offer sent to ${r.offered} eligible worker${r.offered === 1 ? "" : "s"}.`)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not broadcast.")
    }
  }

  const openShifts = shifts.filter((s) => s.status === "open")

  const events = shifts.map((s) => ({
    id: String(s.id),
    title: s.worker ? `${s.title} — ${s.worker}` : `OPEN — ${s.title}`,
    start: s.starts_at.replace(" ", "T"),
    end: s.ends_at.replace(" ", "T"),
    backgroundColor: s.status === "open" ? "#B7791F" : DIVISION_ACCENTS[s.division] ?? "#4D7C0F",
    borderColor: s.status === "open" ? "#B7791F" : DIVISION_ACCENTS[s.division] ?? "#4D7C0F",
  }))

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Scheduling & dispatch</h1>
          <p className="text-sm text-muted-foreground">
            One rota across the divisions. Smart match shows only eligible workers; clashes and lapsed checks are blocked.
          </p>
        </div>
        <div className="flex gap-2">
          <TemplatesDialog onGenerated={reload} />
          <NewShiftDialog open={open} onOpenChange={setOpen} onChanged={reload} />
        </div>
      </div>

      {error && <ErrorBlock message={error} onRetry={reload} />}

      {openShifts.length > 0 && (
        <Card>
          <CardContent className="pt-6">
            <div className="mb-3 flex items-center gap-2 text-sm font-semibold">
              <Radio className="size-4 text-warning" /> Open shifts needing cover ({openShifts.length})
            </div>
            <div className="space-y-2">
              {openShifts.map((s) => (
                <div key={s.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3">
                  <div>
                    <p className="text-sm font-medium">{s.title}</p>
                    <p className="text-xs text-muted-foreground">
                      {DIVISION_LABELS[s.division] ?? s.division} · {s.starts_at.slice(0, 16).replace("T", " ")}
                      {s.site_name ? ` · ${s.site_name}` : ""}
                    </p>
                  </div>
                  <Button size="sm" onClick={() => broadcast(s.id)}>
                    <Radio className="mr-1 size-4" /> Broadcast to eligible
                  </Button>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      <Card className="p-4 [&_.fc]:text-sm [&_.fc-toolbar-title]:text-base [&_.fc-toolbar-title]:font-semibold">
        <FullCalendar
          plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
          initialView="dayGridMonth"
          headerToolbar={{ left: "prev,next today", center: "title", right: "dayGridMonth,timeGridWeek,timeGridDay" }}
          height="auto"
          events={events}
          datesSet={onDatesSet}
          nowIndicator
          firstDay={1}
        />
      </Card>

      <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
        <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-full" style={{ backgroundColor: "#B7791F" }} /> open</span>
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

const WEEKDAYS = [
  { value: "1", label: "Monday" },
  { value: "2", label: "Tuesday" },
  { value: "3", label: "Wednesday" },
  { value: "4", label: "Thursday" },
  { value: "5", label: "Friday" },
  { value: "6", label: "Saturday" },
  { value: "7", label: "Sunday" },
]

function TemplatesDialog({ onGenerated }: { onGenerated: () => void }) {
  const [open, setOpen] = useState(false)
  const [templates, setTemplates] = useState<ShiftTemplate[]>([])
  const [division, setDivision] = useState("care")
  const [weekday, setWeekday] = useState("1")
  const [title, setTitle] = useState("")
  const [start, setStart] = useState("")
  const [end, setEnd] = useState("")
  const [busy, setBusy] = useState(false)

  const load = () => api<{ templates: ShiftTemplate[] }>("shift-templates.php").then((r) => setTemplates(r.templates)).catch(() => {})
  useEffect(() => {
    if (open) load()
  }, [open])

  async function add() {
    if (!title || !start || !end) {
      toast.error("Add a title and times.")
      return
    }
    setBusy(true)
    try {
      await api("shift-templates.php", { method: "POST", body: { title, division, weekday: Number(weekday), start_time: start, end_time: end } })
      toast.success("Template added.")
      setTitle(""); setStart(""); setEnd("")
      load()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not add template.")
    } finally {
      setBusy(false)
    }
  }

  async function toggle(id: number) {
    await api(`shift-templates.php?action=toggle&id=${id}`, { method: "POST", body: {} }).catch(() => {})
    load()
  }

  async function generate() {
    setBusy(true)
    try {
      const r = await api<{ created: number }>("shift-templates.php?action=generate&weeks=4", { method: "POST", body: {} })
      toast.success(`Generated ${r.created} open shift${r.created === 1 ? "" : "s"} for the next 4 weeks.`)
      onGenerated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not generate.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="outline"><CalendarPlus className="mr-1 size-4" /> Templates</Button>
      </DialogTrigger>
      <DialogContent className="max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Recurring shift templates</DialogTitle>
          <DialogDescription>Define weekly patterns, then generate open shifts to broadcast.</DialogDescription>
        </DialogHeader>

        <div className="grid gap-3 rounded-lg border p-3">
          <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Title e.g. Nightly guard — Acme" />
          <div className="grid grid-cols-2 gap-3">
            <Select value={division} onValueChange={setDivision}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>{DIVISIONS.map((d) => <SelectItem key={d.value} value={d.value}>{d.label}</SelectItem>)}</SelectContent>
            </Select>
            <Select value={weekday} onValueChange={setWeekday}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>{WEEKDAYS.map((d) => <SelectItem key={d.value} value={d.value}>{d.label}</SelectItem>)}</SelectContent>
            </Select>
            <Input type="time" value={start} onChange={(e) => setStart(e.target.value)} />
            <Input type="time" value={end} onChange={(e) => setEnd(e.target.value)} />
          </div>
          <Button size="sm" onClick={add} disabled={busy}><Plus className="mr-1 size-4" /> Add template</Button>
        </div>

        <div className="space-y-2">
          {templates.length === 0 ? (
            <p className="text-sm text-muted-foreground">No templates yet.</p>
          ) : (
            templates.map((t) => (
              <div key={t.id} className="flex items-center justify-between gap-2 rounded-md border p-2 text-sm">
                <div>
                  <p className="font-medium">{t.title}</p>
                  <p className="text-xs text-muted-foreground">
                    {WEEKDAYS.find((d) => d.value === String(t.weekday))?.label} · {t.start_time.slice(0, 5)}–{t.end_time.slice(0, 5)} · {DIVISION_LABELS[t.division] ?? t.division}
                  </p>
                </div>
                <button onClick={() => toggle(t.id)}>
                  <Badge variant={t.active ? "default" : "outline"}>{t.active ? "Active" : "Paused"}</Badge>
                </button>
              </div>
            ))
          )}
        </div>

        <DialogFooter>
          <Button onClick={generate} disabled={busy || templates.filter((t) => t.active).length === 0}>
            Generate next 4 weeks
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function NewShiftDialog({
  open,
  onOpenChange,
  onChanged,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  onChanged: () => void
}) {
  const [mode, setMode] = useState<"assign" | "open">("assign")
  const [clients, setClients] = useState<Client[]>([])
  const [skills, setSkills] = useState<Skill[]>([])
  const [division, setDivision] = useState("care")
  const [skillId, setSkillId] = useState("")
  const [clientId, setClientId] = useState("")
  const [title, setTitle] = useState("")
  const [site, setSite] = useState("")
  const [starts, setStarts] = useState("")
  const [ends, setEnds] = useState("")
  const [matches, setMatches] = useState<MatchWorker[] | null>(null)
  const [workerId, setWorkerId] = useState("")
  const [matching, setMatching] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    if (!open) return
    api<{ clients: Client[] }>("clients.php").then((r) => setClients(r.clients)).catch(() => {})
    api<{ skills: Skill[] }>("skills.php").then((r) => setSkills(r.skills)).catch(() => {})
  }, [open])

  async function findEligible() {
    if (!division || !starts || !ends) {
      toast.error("Pick a division, start and end first.")
      return
    }
    setMatching(true)
    setMatches(null)
    setWorkerId("")
    try {
      const q = new URLSearchParams({ action: "match", division, starts_at: starts, ends_at: ends })
      if (skillId) q.set("skill_id", skillId)
      const r = await api<{ workers: MatchWorker[] }>(`scheduling.php?${q.toString()}`)
      setMatches(r.workers)
      if (r.workers.length === 0) toast("No eligible workers for that slot.")
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not match workers.")
    } finally {
      setMatching(false)
    }
  }

  async function submitAssign() {
    if (!workerId || !title || !starts || !ends) {
      toast.error("Find and pick a worker, and add a title.")
      return
    }
    setSubmitting(true)
    try {
      await api("scheduling.php", {
        method: "POST",
        body: { worker_id: Number(workerId), client_id: clientId || null, title, site_name: site, starts_at: starts, ends_at: ends },
      })
      toast.success("Shift scheduled.")
      close()
      onChanged()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not schedule the shift.")
    } finally {
      setSubmitting(false)
    }
  }

  async function submitOpen() {
    if (!title || !division || !starts || !ends) {
      toast.error("Add a title, division, start and end.")
      return
    }
    setSubmitting(true)
    try {
      await api("scheduling.php?action=open", {
        method: "POST",
        body: { title, division, client_id: clientId || null, site_name: site, starts_at: starts, ends_at: ends },
      })
      toast.success("Open shift created — broadcast it to fill it.")
      close()
      onChanged()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not create the open shift.")
    } finally {
      setSubmitting(false)
    }
  }

  function close() {
    onOpenChange(false)
    setMatches(null); setWorkerId(""); setTitle(""); setSite(""); setStarts(""); setEnds(""); setClientId(""); setSkillId("")
  }

  return (
    <Dialog open={open} onOpenChange={(o) => (o ? onOpenChange(true) : close())}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> New shift</Button>
      </DialogTrigger>
      <DialogContent className="max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>New shift</DialogTitle>
          <DialogDescription>Assign an eligible worker, or open it for broadcast to whoever can cover.</DialogDescription>
        </DialogHeader>

        <div className="mb-2 inline-flex rounded-lg border p-1 text-sm">
          <button type="button" onClick={() => setMode("assign")} className={`rounded-md px-3 py-1 ${mode === "assign" ? "bg-primary text-primary-foreground" : ""}`}>Assign</button>
          <button type="button" onClick={() => setMode("open")} className={`rounded-md px-3 py-1 ${mode === "open" ? "bg-primary text-primary-foreground" : ""}`}>Open / broadcast</button>
        </div>

        <div className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="title">Title</Label>
            <Input id="title" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Night cover — Rosewood" />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="division">Division</Label>
              <Select value={division} onValueChange={setDivision}>
                <SelectTrigger id="division"><SelectValue /></SelectTrigger>
                <SelectContent>{DIVISIONS.map((d) => <SelectItem key={d.value} value={d.value}>{d.label}</SelectItem>)}</SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="skill">Skill needed (optional)</Label>
              <Select value={skillId} onValueChange={setSkillId}>
                <SelectTrigger id="skill"><SelectValue placeholder="Any" /></SelectTrigger>
                <SelectContent>{skills.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}</SelectContent>
              </Select>
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="starts">Starts</Label>
              <Input id="starts" type="datetime-local" value={starts} onChange={(e) => setStarts(e.target.value)} />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="ends">Ends</Label>
              <Input id="ends" type="datetime-local" value={ends} onChange={(e) => setEnds(e.target.value)} />
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="client">Client (optional)</Label>
              <Select value={clientId} onValueChange={setClientId}>
                <SelectTrigger id="client"><SelectValue placeholder="None" /></SelectTrigger>
                <SelectContent>{clients.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="site">Site (optional)</Label>
              <Input id="site" value={site} onChange={(e) => setSite(e.target.value)} />
            </div>
          </div>

          {mode === "assign" && (
            <div className="grid gap-2 rounded-lg border p-3">
              <div className="flex items-center justify-between">
                <Label>Eligible workers</Label>
                <Button type="button" size="sm" variant="outline" onClick={findEligible} disabled={matching}>
                  <Sparkles className="mr-1 size-3.5" /> {matching ? "Matching…" : "Find eligible"}
                </Button>
              </div>
              {matches === null ? (
                <p className="text-xs text-muted-foreground">Compliant + available + clash-free workers appear here.</p>
              ) : matches.length === 0 ? (
                <p className="text-xs text-destructive">No eligible workers for that slot. Try opening it for broadcast.</p>
              ) : (
                <div className="grid gap-1">
                  {matches.map((m) => (
                    <button
                      type="button"
                      key={m.id}
                      onClick={() => setWorkerId(String(m.id))}
                      className={`flex items-center justify-between rounded-md border px-3 py-2 text-left text-sm ${workerId === String(m.id) ? "border-primary bg-primary/5" : ""}`}
                    >
                      <span>{m.name} · <span className="text-muted-foreground">{m.role}</span></span>
                      <Badge variant="outline">{m.upcoming_shifts} upcoming</Badge>
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>

        <DialogFooter className="mt-2">
          {mode === "assign" ? (
            <Button onClick={submitAssign} disabled={submitting || !workerId}>
              {submitting ? "Scheduling…" : "Schedule shift"}
            </Button>
          ) : (
            <Button onClick={submitOpen} disabled={submitting}>
              <CalendarPlus className="mr-1 size-4" /> {submitting ? "Creating…" : "Create open shift"}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
