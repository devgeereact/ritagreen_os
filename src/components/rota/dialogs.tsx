import { useEffect, useState } from "react"
import { CalendarPlus, Plus } from "lucide-react"
import { toast } from "sonner"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { api, ApiError } from "@/lib/api"
import { fromInput } from "@/lib/rota"
import { SERVICE_OPTIONS, serviceLabel } from "@/lib/services"
import type { RotaShift, ShiftTemplate, Site } from "@/lib/types"

/**
 * Add a shift. It is created as an open draft at a site; who works it is chosen
 * in the shift panel, which opens straight after, where the eligibility check
 * and its reasons are shown.
 */
export function AddShiftDialog({
  open,
  onOpenChange,
  sites,
  defaultDate,
  defaultService,
  onCreated,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  sites: Site[]
  defaultDate: string
  defaultService: string
  onCreated: (shift: RotaShift) => void
}) {
  const [title, setTitle] = useState("")
  const [service, setService] = useState(defaultService || "care")
  const [siteId, setSiteId] = useState("")
  const [starts, setStarts] = useState(`${defaultDate}T08:00`)
  const [ends, setEnds] = useState(`${defaultDate}T16:00`)
  const [busy, setBusy] = useState(false)

  async function submit() {
    setBusy(true)
    try {
      const r = await api<{ shift: RotaShift }>("scheduling.php", {
        method: "POST",
        body: {
          title,
          division: service,
          ...(siteId ? { site_id: Number(siteId) } : {}),
          starts_at: fromInput(starts),
          ends_at: fromInput(ends),
        },
      })
      toast.success("Draft shift added. Choose who works it.")
      onOpenChange(false)
      setTitle("")
      onCreated({ ...r.shift, is_draft: true, state: "draft", live_offers: 0, conflicts: [] } as RotaShift)
    } catch (err) {
      // What was typed stays in the form.
      toast.error(err instanceof ApiError ? err.message : "Could not add the shift.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Add shift</DialogTitle>
          <DialogDescription>Added as a draft. Workers see it once the week is published.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-1.5">
            <Label htmlFor="add-title">Role</Label>
            <Input id="add-title" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="For example: Day carer" />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-1.5">
              <Label htmlFor="add-service">Service</Label>
              <Select value={service} onValueChange={setService}>
                <SelectTrigger id="add-service"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {SERVICE_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="add-site">Site</Label>
              <Select value={siteId} onValueChange={setSiteId}>
                <SelectTrigger id="add-site"><SelectValue placeholder="Choose a site" /></SelectTrigger>
                <SelectContent>
                  {sites.filter((s) => s.active).map((s) => (
                    <SelectItem key={s.id} value={String(s.id)}>{s.name}{s.client ? ` (${s.client})` : ""}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-1.5">
              <Label htmlFor="add-start">Starts</Label>
              <Input id="add-start" type="datetime-local" value={starts} onChange={(e) => setStarts(e.target.value)} />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="add-end">Ends</Label>
              <Input id="add-end" type="datetime-local" value={ends} onChange={(e) => setEnds(e.target.value)} />
            </div>
          </div>
          <p className="text-xs text-muted-foreground">A night shift ends the next day: set the end date to the morning after.</p>
        </div>
        <DialogFooter>
          <Button onClick={submit} disabled={busy || title.trim() === "" || !starts || !ends}>
            <Plus className="size-4" /> {busy ? "Adding…" : "Add draft"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

const WEEKDAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]

/** Weekly patterns that generate draft shifts (server/shift-templates.php). */
export function TemplatesDialog({ open, onOpenChange, onGenerated }: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onGenerated: () => void
}) {
  const [templates, setTemplates] = useState<ShiftTemplate[]>([])
  const [division, setDivision] = useState("care")
  const [weekday, setWeekday] = useState("1")
  const [title, setTitle] = useState("")
  const [start, setStart] = useState("")
  const [end, setEnd] = useState("")
  const [busy, setBusy] = useState(false)

  const load = () => api<{ templates: ShiftTemplate[] }>("shift-templates.php").then((r) => setTemplates(r.templates)).catch(() => {})
  useEffect(() => {
    if (open) void load()
  }, [open])

  async function add() {
    setBusy(true)
    try {
      await api("shift-templates.php", { method: "POST", body: { title, division, weekday: Number(weekday), start_time: start, end_time: end } })
      toast.success("Pattern added.")
      setTitle(""); setStart(""); setEnd("")
      void load()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not add the pattern.")
    } finally {
      setBusy(false)
    }
  }

  async function toggle(id: number) {
    await api(`shift-templates.php?action=toggle&id=${id}`, { method: "POST", body: {} }).catch(() => {})
    void load()
  }

  async function generate() {
    setBusy(true)
    try {
      const r = await api<{ created: number }>("shift-templates.php?action=generate&weeks=4", { method: "POST", body: {} })
      toast.success(`${r.created} draft ${r.created === 1 ? "shift" : "shifts"} added for the next four weeks.`)
      onGenerated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not generate shifts.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Weekly patterns</DialogTitle>
          <DialogDescription>Shifts that happen every week. Generating adds them as drafts to publish with the rest.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-3 rounded-lg border border-border p-3">
          <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Role, for example Night guard" aria-label="Role" />
          <div className="grid grid-cols-2 gap-3">
            <Select value={division} onValueChange={setDivision}>
              <SelectTrigger aria-label="Service"><SelectValue /></SelectTrigger>
              <SelectContent>{SERVICE_OPTIONS.map((d) => <SelectItem key={d.value} value={d.value}>{d.label}</SelectItem>)}</SelectContent>
            </Select>
            <Select value={weekday} onValueChange={setWeekday}>
              <SelectTrigger aria-label="Day"><SelectValue /></SelectTrigger>
              <SelectContent>{WEEKDAYS.map((d, i) => <SelectItem key={d} value={String(i + 1)}>{d}</SelectItem>)}</SelectContent>
            </Select>
            <Input type="time" value={start} onChange={(e) => setStart(e.target.value)} aria-label="Start time" />
            <Input type="time" value={end} onChange={(e) => setEnd(e.target.value)} aria-label="End time" />
          </div>
          <Button size="sm" onClick={add} disabled={busy || !title || !start || !end}><Plus className="size-4" /> Add pattern</Button>
        </div>
        <div className="space-y-2">
          {templates.length === 0 ? (
            <p className="text-sm text-muted-foreground">No weekly patterns yet.</p>
          ) : (
            templates.map((t) => (
              <div key={t.id} className="flex items-center justify-between gap-2 rounded-md border border-border p-2 text-sm">
                <div>
                  <p className="font-medium">{t.title}</p>
                  <p className="text-xs tabular-nums text-muted-foreground">
                    {WEEKDAYS[t.weekday - 1]} · {t.start_time.slice(0, 5)} to {t.end_time.slice(0, 5)} · {serviceLabel(t.division)}
                  </p>
                </div>
                <button type="button" onClick={() => toggle(t.id)}>
                  <Badge variant={t.active ? "default" : "outline"}>{t.active ? "Active" : "Paused"}</Badge>
                </button>
              </div>
            ))
          )}
        </div>
        <DialogFooter>
          <Button onClick={generate} disabled={busy || templates.filter((t) => t.active).length === 0}>
            <CalendarPlus className="size-4" /> Add the next four weeks as drafts
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
