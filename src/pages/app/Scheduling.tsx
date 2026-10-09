import { useMemo, useState } from "react"
import { CalendarClock, ChevronLeft, ChevronRight, Copy, Megaphone, Plus, Repeat } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group"
import { ErrorBlock, LoadingBlock } from "@/components/app/state-block"
import { ShiftPanel } from "@/components/rota/shift-panel"
import { AddShiftDialog, TemplatesDialog } from "@/components/rota/dialogs"
import { ConflictLine, ServiceDot, StateChip } from "@/components/rota/bits"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { addDays, dayLabel, rotaRows, shiftsOn, shiftTimes, todayIso, weekDays, weekLabel, weekStart } from "@/lib/rota"
import { SERVICE_OPTIONS } from "@/lib/services"
import { cn } from "@/lib/utils"
import type { RotaShift, Site } from "@/lib/types"

// The office rota (plan file 03, "Office rota screen").
//
// A week at a time, in rows by site (the default) or by worker on a desktop and
// as one day's list on a phone. Every shift says its time, role, who is on it or
// "Open", its service and its state, and anything wrong with the booking is
// written on the shift itself. Clicking a shift opens the panel where it is
// edited, assigned, offered or cancelled.
//
// FullCalendar used to draw this page. Its free build has no rows by site or by
// worker (that is the paid resource timeline), and its month grid could not
// show a status chip and a conflict reason on each shift, so the week is a plain
// grid of buttons now: keyboard-reachable, and nothing needs dragging.

type Data = { shifts: RotaShift[]; rest_gap_min_hours: number }
const ALL = "all"

export default function Scheduling() {
  const [monday, setMonday] = useState(() => weekStart(todayIso()))
  const [service, setService] = useState(ALL)
  const [site, setSite] = useState(ALL)
  const [by, setBy] = useState<"site" | "worker">("site")
  const [day, setDay] = useState(() => todayIso())
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [adding, setAdding] = useState(false)
  const [templates, setTemplates] = useState(false)
  const [publishing, setPublishing] = useState(false)
  const [busy, setBusy] = useState(false)
  const [created, setCreated] = useState<RotaShift | null>(null)

  const days = weekDays(monday)
  const sunday = days[6]
  const filters = `${service !== ALL ? `&division=${service}` : ""}${site !== ALL ? `&site_id=${site}` : ""}`
  const rota = useApi<Data>(`scheduling.php?from=${monday}&to=${sunday}${filters}`, [monday, filters])
  const sitesApi = useApi<{ sites: Site[] }>("sites.php")
  const sites = useMemo(() => sitesApi.data?.sites ?? [], [sitesApi.data])

  const shifts = useMemo(() => rota.data?.shifts ?? [], [rota.data])
  const rows = useMemo(() => rotaRows(shifts, by), [shifts, by])
  const drafts = shifts.filter((s) => s.state === "draft").length
  const selected = shifts.find((s) => s.id === selectedId) ?? (created?.id === selectedId ? created : null)

  // The phone view shows one day; keep it inside the week being looked at.
  const phoneDay = days.includes(day) ? day : monday

  function goWeek(next: string) {
    const m = weekStart(next)
    setMonday(m)
    setDay(days.includes(next) ? next : m)
  }

  async function copyLastWeek() {
    setBusy(true)
    try {
      const r = await api<{ created: number; skipped: number; unassigned: { title: string; worker: string; reason: string }[] }>(
        "scheduling.php?action=copy_week",
        {
          method: "POST",
          body: { week_start: monday, ...(service !== ALL ? { division: service } : {}), ...(site !== ALL ? { site_id: Number(site) } : {}) },
        },
      )
      toast.success(`${r.created} draft ${r.created === 1 ? "shift" : "shifts"} copied from last week${r.skipped ? `, ${r.skipped} already here` : ""}.`, {
        description: r.unassigned.length
          ? r.unassigned.map((u) => `${u.title}: ${u.worker} left off (${u.reason})`).join(" ")
          : undefined,
      })
      rota.reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not copy last week.")
    } finally {
      setBusy(false)
    }
  }

  async function publish() {
    setBusy(true)
    try {
      const r = await api<{ published: number; workers_notified: number }>("scheduling.php?action=publish", {
        method: "POST",
        body: { from: monday, to: sunday, ...(service !== ALL ? { division: service } : {}), ...(site !== ALL ? { site_id: Number(site) } : {}) },
      })
      toast.success(`${r.published} ${r.published === 1 ? "shift" : "shifts"} published. ${r.workers_notified} ${r.workers_notified === 1 ? "worker" : "workers"} told.`)
      setPublishing(false)
      rota.reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not publish.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Rota</h1>
          <p className="text-sm text-muted-foreground">
            Plan the week as drafts, then publish. Workers see a shift once it is published, and are told when it changes.
          </p>
        </div>
        <Button onClick={() => setAdding(true)}>
          <Plus className="size-4" /> Add shift
        </Button>
      </div>

      {/* Filter row: the week, then what to show, then the week's actions. */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-1">
          <Button variant="outline" size="icon" className="size-9" aria-label="Previous week" onClick={() => goWeek(addDays(monday, -7))}>
            <ChevronLeft className="size-4" />
          </Button>
          <Input
            type="date"
            aria-label="Week of"
            className="h-9 w-[9.5rem]"
            value={monday}
            onChange={(e) => e.target.value && goWeek(e.target.value)}
          />
          <Button variant="outline" size="icon" className="size-9" aria-label="Next week" onClick={() => goWeek(addDays(monday, 7))}>
            <ChevronRight className="size-4" />
          </Button>
          <Button variant="outline" className="h-9" onClick={() => goWeek(todayIso())}>Today</Button>
        </div>
        <p className="text-sm font-medium tabular-nums" aria-live="polite">{weekLabel(monday)}</p>
        <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
          <Select value={service} onValueChange={setService}>
            <SelectTrigger className="h-9 w-[9.5rem]" aria-label="Service"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All services</SelectItem>
              {SERVICE_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
            </SelectContent>
          </Select>
          <Select value={site} onValueChange={setSite}>
            <SelectTrigger className="h-9 w-[11rem]" aria-label="Site"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All sites</SelectItem>
              {sites.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
            </SelectContent>
          </Select>
          <ToggleGroup type="single" value={by} onValueChange={(v) => v && setBy(v as "site" | "worker")}
            className="hidden rounded-md border border-border md:flex" aria-label="Rows">
            <ToggleGroupItem value="site" className="h-9 px-3 text-sm">By site</ToggleGroupItem>
            <ToggleGroupItem value="worker" className="h-9 px-3 text-sm">By worker</ToggleGroupItem>
          </ToggleGroup>
        </div>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button variant="outline" size="sm" onClick={copyLastWeek} disabled={busy}>
          <Copy className="size-4" /> Copy last week
        </Button>
        <Button variant="outline" size="sm" onClick={() => setTemplates(true)}>
          <Repeat className="size-4" /> Weekly patterns
        </Button>
        <Button size="sm" variant={drafts > 0 ? "default" : "outline"} onClick={() => setPublishing(true)} disabled={drafts === 0}>
          <Megaphone className="size-4" /> Publish drafts{drafts > 0 ? ` (${drafts})` : ""}
        </Button>
      </div>

      {rota.error && <ErrorBlock message={rota.error} onRetry={rota.reload} />}
      {rota.loading && !rota.data && <LoadingBlock rows={5} />}

      {rota.data && (
        <>
          <WeekGrid rows={rows} days={days} by={by} onOpen={setSelectedId} />
          <DayList shifts={shifts} days={days} day={phoneDay} onDay={setDay} onOpen={setSelectedId} />
          <p className="text-xs text-muted-foreground">
            Minimum rest between shifts: {rota.data.rest_gap_min_hours} hours. Shorter gaps need acknowledging when booking.
          </p>
        </>
      )}

      <ShiftPanel shift={selected} sites={sites} onClose={() => setSelectedId(null)} onChanged={rota.reload} />
      <AddShiftDialog
        open={adding}
        onOpenChange={setAdding}
        sites={sites}
        defaultDate={days.includes(todayIso()) ? todayIso() : monday}
        defaultService={service !== ALL ? service : "care"}
        onCreated={(s) => {
          setCreated(s)
          setSelectedId(s.id)
          if (!days.includes(s.starts_at.slice(0, 10))) goWeek(s.starts_at.slice(0, 10))
          rota.reload()
        }}
      />
      <TemplatesDialog open={templates} onOpenChange={setTemplates} onGenerated={rota.reload} />
      <Dialog open={publishing} onOpenChange={setPublishing}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Publish {drafts} draft {drafts === 1 ? "shift" : "shifts"}?</DialogTitle>
            <DialogDescription>
              For the week {weekLabel(monday)}{service !== ALL || site !== ALL ? ", as filtered" : ""}. Workers booked on them are
              told in the staff app and by email. Open shifts become visible to eligible workers.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setPublishing(false)}>Not yet</Button>
            <Button onClick={publish} disabled={busy}>{busy ? "Publishing…" : "Publish"}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

/** One shift, as a button: time, role, who, service, state, and what is wrong. */
function ShiftCard({ s, onOpen, showSite, hideWorker }: { s: RotaShift; onOpen: (id: number) => void; showSite?: boolean; hideWorker?: boolean }) {
  const blocked = s.conflicts.some((c) => c.severity === "block")
  return (
    <button
      type="button"
      onClick={() => onOpen(s.id)}
      className={cn(
        "w-full space-y-1 rounded-md border bg-card p-2 text-left text-xs transition-colors hover:border-primary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
        s.state === "draft" && "border-dashed",
        s.state === "cancelled" && "opacity-70",
        blocked ? "border-destructive/60" : "border-border",
      )}
    >
      <p className="font-semibold tabular-nums">{shiftTimes(s.starts_at, s.ends_at)}</p>
      <p className={cn("truncate", s.state === "cancelled" && "line-through")}>{s.title}</p>
      {!(hideWorker && s.worker) && <p className={cn("truncate", s.worker ? "text-foreground" : "font-medium text-warning")}>{s.worker ?? "Open"}</p>}
      {showSite && s.site_name && <p className="truncate text-muted-foreground">{s.site_name}</p>}
      <div className="flex flex-wrap items-center gap-1.5">
        <StateChip state={s.state} />
        <ServiceDot service={s.division} />
      </div>
      {s.conflicts.map((c, i) => <ConflictLine key={i} c={c} compact />)}
    </button>
  )
}

function WeekGrid({ rows, days, by, onOpen }: {
  rows: ReturnType<typeof rotaRows>
  days: string[]
  by: "site" | "worker"
  onOpen: (id: number) => void
}) {
  const today = todayIso()
  if (rows.length === 0) {
    return (
      <div className="hidden rounded-lg border border-dashed border-border p-10 text-center md:block">
        <CalendarClock className="mx-auto size-6 text-muted-foreground" aria-hidden />
        <p className="mt-2 font-medium">Nothing on the rota this week</p>
        <p className="text-sm text-muted-foreground">Add a shift, or copy last week to start from what happened then.</p>
      </div>
    )
  }
  return (
    <div className="hidden overflow-x-auto rounded-lg border border-border bg-card md:block">
      <div className="grid min-w-[880px]" style={{ gridTemplateColumns: "11rem repeat(7, minmax(0, 1fr))" }} role="table" aria-label="Week rota">
        <div className="sticky left-0 border-b border-border bg-muted/50 p-2 text-xs font-medium text-muted-foreground" role="columnheader">
          {by === "site" ? "Site" : "Worker"}
        </div>
        {days.map((d) => (
          <div key={d} role="columnheader"
            className={cn("border-b border-l border-border bg-muted/50 p-2 text-xs font-medium tabular-nums", d === today && "text-primary")}>
            {dayLabel(d)}
          </div>
        ))}
        {rows.map((row) => (
          <div key={row.key} className="contents" role="row">
            <div className="border-b border-border p-2 text-sm" role="rowheader">
              <p className="font-medium leading-tight">{row.label}</p>
              {row.sub && <p className="text-xs text-muted-foreground">{row.sub}</p>}
            </div>
            {days.map((d) => (
              <div key={d} role="cell" className={cn("space-y-1.5 border-b border-l border-border p-1.5", d === today && "bg-accent/40")}>
                {shiftsOn(row.shifts, d).map((s) => <ShiftCard key={s.id} s={s} onOpen={onOpen} showSite={by === "worker"} hideWorker={by === "worker"} />)}
              </div>
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}

/** The phone view: one day at a time, shifts in time order. */
function DayList({ shifts, days, day, onDay, onOpen }: {
  shifts: RotaShift[]
  days: string[]
  day: string
  onDay: (d: string) => void
  onOpen: (id: number) => void
}) {
  const list = shiftsOn(shifts, day)
  return (
    <div className="space-y-3 md:hidden">
      <div className="grid grid-cols-7 gap-1" role="tablist" aria-label="Day">
        {days.map((d) => {
          const n = shiftsOn(shifts, d).length
          return (
            <button key={d} type="button" role="tab" aria-selected={d === day} onClick={() => onDay(d)}
              className={cn(
                "flex min-h-11 flex-col items-center justify-center rounded-md border text-xs tabular-nums",
                d === day ? "border-primary bg-primary text-primary-foreground" : "border-border bg-card",
              )}>
              <span>{dayLabel(d).split(" ")[0]}</span>
              <span className="font-semibold">{d.slice(8)}</span>
              {n > 0 && <span className="sr-only">{n} shifts</span>}
            </button>
          )
        })}
      </div>
      <p className="text-sm font-medium">{dayLabel(day, true)}</p>
      {list.length === 0 ? (
        <p className="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">No shifts this day.</p>
      ) : (
        <div className="space-y-2">{list.map((s) => <ShiftCard key={s.id} s={s} onOpen={onOpen} showSite />)}</div>
      )}
    </div>
  )
}
