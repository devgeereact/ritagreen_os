import { useEffect, useMemo, useState } from "react"
import { Ban, Loader2, Radio, Search, Send, UserMinus } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { api, ApiError } from "@/lib/api"
import { useApi } from "@/hooks/use-api"
import { checkFromError, dayLabel, fromInput, HISTORY_LABEL, shiftTimes, toInput } from "@/lib/rota"
import type { AssignmentCheck, MatchWorker, RotaShift, ShiftHistoryEntry, ShiftOffer, Site, Worker } from "@/lib/types"
import { CheckResult, ConflictLine, ServiceDot, StateChip } from "./bits"

type Detail = { shift: RotaShift & { cancel_reason: string | null }; history: ShiftHistoryEntry[]; offers: ShiftOffer[] }

/**
 * One shift and everything the office can do to it: edit, assign, offer,
 * cancel with a reason, and its history. Every action has a button; nothing
 * needs dragging.
 *
 * Who may be put on the shift is the server's decision (rg_assignment_check).
 * This panel shows that decision before anybody presses Assign, and a refusal
 * keeps what was typed and says exactly what blocks the worker.
 */
export function ShiftPanel({
  shift,
  sites,
  onClose,
  onChanged,
}: {
  shift: RotaShift | null
  sites: Site[]
  onClose: () => void
  onChanged: () => void
}) {
  return (
    <Sheet open={shift !== null} onOpenChange={(o) => !o && onClose()}>
      <SheetContent side="right" className="w-full overflow-y-auto p-0 sm:max-w-lg">
        {shift && <PanelBody key={shift.id} shift={shift} sites={sites} onChanged={onChanged} onClose={onClose} />}
      </SheetContent>
    </Sheet>
  )
}

function PanelBody({ shift, sites, onChanged, onClose }: {
  shift: RotaShift
  sites: Site[]
  onChanged: () => void
  onClose: () => void
}) {
  const detail = useApi<Detail>(`scheduling.php?id=${shift.id}`, [shift])
  const live = shift.state !== "cancelled" && shift.state !== "completed"
  const refresh = () => {
    detail.reload()
    onChanged()
  }

  return (
    <div className="flex flex-col">
      <SheetHeader className="space-y-2 border-b border-border p-5 text-left">
        <div className="flex flex-wrap items-center gap-2">
          <StateChip state={shift.state} />
          <ServiceDot service={shift.division} />
        </div>
        <SheetTitle className="text-lg">{shift.title}</SheetTitle>
        <SheetDescription>
          <span className="tabular-nums">
            {dayLabel(shift.starts_at, true)}, {shiftTimes(shift.starts_at, shift.ends_at)}
          </span>
          {shift.site_name ? ` · ${shift.site_name}` : ""}
          {shift.client ? ` · ${shift.client}` : ""}
        </SheetDescription>
        <p className="text-sm">{shift.worker ? <>On it: <span className="font-medium">{shift.worker}</span></> : "Nobody assigned"}</p>
        {shift.state === "draft" && (
          <p className="text-xs text-muted-foreground">Draft: workers cannot see it until the week is published.</p>
        )}
        {shift.conflicts.length > 0 && (
          <div className="space-y-1 pt-1">
            {shift.conflicts.map((c, i) => (
              <ConflictLine key={i} c={c} />
            ))}
          </div>
        )}
        {shift.state === "cancelled" && detail.data?.shift.cancel_reason && (
          <p className="text-sm">
            <span className="font-medium">Cancelled:</span> {detail.data.shift.cancel_reason}
          </p>
        )}
      </SheetHeader>

      <div className="space-y-6 p-5">
        {live && <AssignSection shift={shift} onDone={refresh} />}
        {live && !shift.is_draft && shift.worker_id === null && <OfferSection shift={shift} onDone={refresh} />}
        {live && <EditSection shift={shift} sites={sites} onDone={refresh} />}
        {live && <CancelSection shift={shift} onDone={() => { refresh(); onClose() }} />}
        {!live && (
          <p className="text-sm text-muted-foreground">
            {shift.state === "completed"
              ? "This shift has been worked. It is a record now, not a plan."
              : "This shift is cancelled. Add a new shift to cover the slot."}
          </p>
        )}
        <HistorySection loading={detail.loading} history={detail.data?.history ?? []} offers={detail.data?.offers ?? []} />
      </div>
    </div>
  )
}

function SectionTitle({ children }: { children: React.ReactNode }) {
  return <h3 className="text-sm font-semibold">{children}</h3>
}

// ------------------------------------------------------------------ assign

function AssignSection({ shift, onDone }: { shift: RotaShift; onDone: () => void }) {
  const [matches, setMatches] = useState<MatchWorker[] | null>(null)
  const [matching, setMatching] = useState(false)
  const [pick, setPick] = useState<{ id: number; name: string } | null>(null)
  const [check, setCheck] = useState<AssignmentCheck | null>(null)
  const [checking, setChecking] = useState(false)
  const [ack, setAck] = useState(false)
  const [busy, setBusy] = useState(false)
  const workers = useApi<{ workers: Worker[] }>("workers.php")
  const sameService = useMemo(
    () => (workers.data?.workers ?? []).filter((w) => w.division === shift.division && w.status !== "inactive"),
    [workers.data, shift.division],
  )

  async function findEligible() {
    setMatching(true)
    try {
      const r = await api<{ workers: MatchWorker[] }>(`scheduling.php?action=match&id=${shift.id}`)
      setMatches(r.workers)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not find eligible workers.")
    } finally {
      setMatching(false)
    }
  }

  async function choose(id: number, name: string) {
    setPick({ id, name })
    setCheck(null)
    setAck(false)
    setChecking(true)
    try {
      const r = await api<{ check: AssignmentCheck }>(`scheduling.php?action=check&id=${shift.id}&worker_id=${id}`)
      setCheck(r.check)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not check that worker.")
    } finally {
      setChecking(false)
    }
  }

  async function assign() {
    if (!pick) return
    setBusy(true)
    try {
      await api(`scheduling.php?action=assign&id=${shift.id}`, {
        method: "POST",
        body: { worker_id: pick.id, acknowledge_warnings: ack },
      })
      toast.success(shift.is_draft ? `${pick.name} is on the draft.` : `${pick.name} is booked and has been told.`)
      setPick(null)
      setCheck(null)
      onDone()
    } catch (err) {
      // Keep the choice and show the server's reasons in place.
      if (err instanceof ApiError) {
        const { check: c } = checkFromError(err.data)
        if (c) setCheck(c)
        toast.error(err.message)
      } else toast.error("Could not assign.")
    } finally {
      setBusy(false)
    }
  }

  async function unassign() {
    setBusy(true)
    try {
      await api(`scheduling.php?action=unassign&id=${shift.id}`, { method: "POST", body: {} })
      toast.success(shift.is_draft ? "The draft is open again." : "Taken off. They have been told, and the shift is open again.")
      onDone()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not take them off.")
    } finally {
      setBusy(false)
    }
  }

  const needsAck = check !== null && check.ok && check.warnings.length > 0
  const canAssign = pick !== null && check !== null && check.ok && (!needsAck || ack)

  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between gap-2">
        <SectionTitle>{shift.worker ? "Move to someone else" : "Assign"}</SectionTitle>
        {shift.worker && (
          <Button size="sm" variant="outline" onClick={unassign} disabled={busy}>
            <UserMinus className="size-4" /> Take off
          </Button>
        )}
      </div>

      <div className="flex flex-wrap gap-2">
        <Button size="sm" variant="outline" onClick={findEligible} disabled={matching}>
          {matching ? <Loader2 className="size-4 animate-spin" /> : <Search className="size-4" />} Find eligible
        </Button>
        <Select value={pick ? String(pick.id) : ""} onValueChange={(v) => {
          const w = sameService.find((x) => String(x.id) === v)
          if (w) void choose(w.id, w.name)
        }}>
          <SelectTrigger className="h-8 w-full sm:w-56" aria-label="Check a named worker">
            <SelectValue placeholder="Or check someone by name" />
          </SelectTrigger>
          <SelectContent>
            {sameService.map((w) => (
              <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {matches !== null && (
        matches.length === 0 ? (
          <p className="text-xs text-muted-foreground">Nobody is free and eligible for this slot. Offer it, or change the time.</p>
        ) : (
          <ul className="divide-y divide-border rounded-md border border-border">
            {matches.map((m) => (
              <li key={m.id}>
                <button
                  type="button"
                  onClick={() => void choose(m.id, m.name)}
                  className={`flex w-full items-start justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-muted/60 ${pick?.id === m.id ? "bg-accent" : ""}`}
                >
                  <span>
                    {m.name} <span className="text-muted-foreground">· {m.role}</span>
                    {(m.warnings ?? []).map((w, i) => (
                      <ConflictLine key={i} c={{ ...w, severity: "warning" }} compact />
                    ))}
                  </span>
                  <span className="shrink-0 text-xs tabular-nums text-muted-foreground">{m.upcoming_shifts} booked</span>
                </button>
              </li>
            ))}
          </ul>
        )
      )}

      {checking && <p className="text-xs text-muted-foreground">Checking {pick?.name}…</p>}
      {pick && check && <CheckResult check={check} name={pick.name} />}
      {needsAck && (
        <label className="flex items-start gap-2 text-sm">
          <Checkbox checked={ack} onCheckedChange={(v) => setAck(v === true)} className="mt-0.5" />
          <span>I have read these warnings and want to book {pick?.name} anyway. This is recorded.</span>
        </label>
      )}
      {pick && (
        <Button onClick={assign} disabled={!canAssign || busy}>
          {busy ? "Assigning…" : `Assign ${pick.name}`}
        </Button>
      )}
    </section>
  )
}

// ------------------------------------------------------------------ offer

function OfferSection({ shift, onDone }: { shift: RotaShift; onDone: () => void }) {
  const [matches, setMatches] = useState<MatchWorker[] | null>(null)
  const [chosen, setChosen] = useState<number[]>([])
  const [warned, setWarned] = useState<{ worker: string; warnings: { code: string; reason: string }[] }[] | null>(null)
  const [ack, setAck] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let alive = true
    api<{ workers: MatchWorker[] }>(`scheduling.php?action=match&id=${shift.id}`)
      .then((r) => alive && setMatches(r.workers))
      .catch(() => alive && setMatches([]))
    return () => {
      alive = false
    }
  }, [shift.id])

  async function offerSelected() {
    setBusy(true)
    try {
      const r = await api<{ offered: number; refused: { worker: string; reason: string }[] }>(
        `scheduling.php?action=offer&id=${shift.id}`,
        { method: "POST", body: { worker_ids: chosen, acknowledge_warnings: ack } },
      )
      toast.success(`Offered to ${r.offered} ${r.offered === 1 ? "worker" : "workers"}. First to accept gets it.`)
      for (const x of r.refused) toast.error(`${x.worker}: ${x.reason}`)
      setChosen([])
      setWarned(null)
      onDone()
    } catch (err) {
      if (err instanceof ApiError && err.data && typeof err.data === "object" && "warned" in err.data) {
        setWarned((err.data as { warned: { worker: string; warnings: { code: string; reason: string }[] }[] }).warned)
      }
      toast.error(err instanceof ApiError ? err.message : "Could not send the offers.")
    } finally {
      setBusy(false)
    }
  }

  async function offerAll() {
    setBusy(true)
    try {
      const r = await api<{ offered: number }>(`scheduling.php?action=broadcast&id=${shift.id}`, { method: "POST", body: {} })
      toast.success(`Offered to ${r.offered} eligible ${r.offered === 1 ? "worker" : "workers"}.`)
      onDone()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not send the offers.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="space-y-3">
      <SectionTitle>Offer</SectionTitle>
      <p className="text-xs text-muted-foreground">
        Offers go to the staff app and by email. First eligible worker to accept gets the shift.
      </p>
      {matches === null ? (
        <p className="text-xs text-muted-foreground">Finding eligible workers…</p>
      ) : matches.length === 0 ? (
        <p className="text-xs text-muted-foreground">Nobody is eligible and free for this slot.</p>
      ) : (
        <ul className="space-y-1.5">
          {matches.map((m) => (
            <li key={m.id}>
              <label className="flex items-start gap-2 text-sm">
                <Checkbox
                  className="mt-0.5"
                  checked={chosen.includes(m.id)}
                  onCheckedChange={(v) => setChosen((c) => (v === true ? [...c, m.id] : c.filter((x) => x !== m.id)))}
                />
                <span>
                  {m.name}
                  {(m.warnings ?? []).map((w, i) => (
                    <ConflictLine key={i} c={{ ...w, severity: "warning" }} compact />
                  ))}
                </span>
              </label>
            </li>
          ))}
        </ul>
      )}
      {warned && (
        <label className="flex items-start gap-2 rounded-md border border-warning/40 bg-warning-soft p-3 text-sm">
          <Checkbox checked={ack} onCheckedChange={(v) => setAck(v === true)} className="mt-0.5" />
          <span>I have read the warnings for {warned.map((w) => w.worker).join(", ")} and want to offer anyway.</span>
        </label>
      )}
      <div className="flex flex-wrap gap-2">
        <Button size="sm" onClick={offerSelected} disabled={busy || chosen.length === 0 || (warned !== null && !ack)}>
          <Send className="size-4" /> Offer to selected
        </Button>
        <Button size="sm" variant="outline" onClick={offerAll} disabled={busy}>
          <Radio className="size-4" /> Offer to all eligible
        </Button>
      </div>
    </section>
  )
}

// ------------------------------------------------------------------ edit

function EditSection({ shift, sites, onDone }: { shift: RotaShift; sites: Site[]; onDone: () => void }) {
  const [title, setTitle] = useState(shift.title)
  const [starts, setStarts] = useState(toInput(shift.starts_at))
  const [ends, setEnds] = useState(toInput(shift.ends_at))
  const [siteId, setSiteId] = useState(shift.site_id !== null ? String(shift.site_id) : "")
  const [check, setCheck] = useState<AssignmentCheck | null>(null)
  const [ack, setAck] = useState(false)
  const [busy, setBusy] = useState(false)
  const open = sites.filter((s) => s.active || s.id === shift.site_id)

  async function save() {
    setBusy(true)
    try {
      const r = await api<{ changed: boolean; notified?: boolean }>(`scheduling.php?action=update&id=${shift.id}`, {
        method: "POST",
        body: {
          title,
          starts_at: fromInput(starts),
          ends_at: fromInput(ends),
          ...(siteId !== "" ? { site_id: Number(siteId) } : {}),
          acknowledge_warnings: ack,
        },
      })
      toast.success(!r.changed ? "Nothing changed." : r.notified ? "Saved. The worker has been told what changed." : "Saved.")
      setCheck(null)
      onDone()
    } catch (err) {
      if (err instanceof ApiError) {
        const { check: c } = checkFromError(err.data)
        setCheck(c)
        toast.error(err.message)
      } else toast.error("Could not save.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="space-y-3">
      <SectionTitle>Details</SectionTitle>
      <div className="grid gap-3">
        <div className="grid gap-1.5">
          <Label htmlFor="edit-title">Role</Label>
          <Input id="edit-title" value={title} onChange={(e) => setTitle(e.target.value)} />
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="grid gap-1.5">
            <Label htmlFor="edit-start">Starts</Label>
            <Input id="edit-start" type="datetime-local" value={starts} onChange={(e) => setStarts(e.target.value)} />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="edit-end">Ends</Label>
            <Input id="edit-end" type="datetime-local" value={ends} onChange={(e) => setEnds(e.target.value)} />
          </div>
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="edit-site">Site</Label>
          <Select value={siteId} onValueChange={setSiteId}>
            <SelectTrigger id="edit-site"><SelectValue placeholder={shift.site_name ?? "Choose a site"} /></SelectTrigger>
            <SelectContent>
              {open.map((s) => (
                <SelectItem key={s.id} value={String(s.id)}>{s.name}{s.client ? ` (${s.client})` : ""}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>
      {check && shift.worker && <CheckResult check={check} name={shift.worker} />}
      {check?.ok && check.warnings.length > 0 && (
        <label className="flex items-start gap-2 text-sm">
          <Checkbox checked={ack} onCheckedChange={(v) => setAck(v === true)} className="mt-0.5" />
          <span>I have read these warnings and want to keep {shift.worker} on it.</span>
        </label>
      )}
      <Button variant="outline" onClick={save} disabled={busy}>
        {busy ? "Saving…" : "Save changes"}
      </Button>
      {!shift.is_draft && shift.worker && (
        <p className="text-xs text-muted-foreground">{shift.worker} will be told what changed.</p>
      )}
    </section>
  )
}

// ------------------------------------------------------------------ cancel

function CancelSection({ shift, onDone }: { shift: RotaShift; onDone: () => void }) {
  const [reason, setReason] = useState("")
  const [busy, setBusy] = useState(false)

  async function cancel() {
    setBusy(true)
    try {
      const r = await api<{ notified?: boolean }>(`scheduling.php?action=cancel&id=${shift.id}`, {
        method: "POST",
        body: { reason },
      })
      toast.success(r.notified ? `Cancelled. ${shift.worker} has been told why.` : "Cancelled.")
      onDone()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not cancel.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="space-y-2">
      <SectionTitle>Cancel</SectionTitle>
      <Label htmlFor="cancel-reason" className="text-xs text-muted-foreground">
        Reason{!shift.is_draft && shift.worker ? ` (${shift.worker} will see it)` : ""}
      </Label>
      <Textarea id="cancel-reason" rows={2} value={reason} onChange={(e) => setReason(e.target.value)}
        placeholder="For example: the home has covered it" />
      <Button variant="outline" className="text-destructive hover:text-destructive" onClick={cancel}
        disabled={busy || reason.trim() === ""}>
        <Ban className="size-4" /> {busy ? "Cancelling…" : "Cancel shift"}
      </Button>
    </section>
  )
}

// ------------------------------------------------------------------ history

function HistorySection({ loading, history, offers }: { loading: boolean; history: ShiftHistoryEntry[]; offers: ShiftOffer[] }) {
  return (
    <section className="space-y-2">
      <SectionTitle>History</SectionTitle>
      {loading && history.length === 0 ? (
        <p className="text-xs text-muted-foreground">Loading…</p>
      ) : history.length === 0 ? (
        <p className="text-xs text-muted-foreground">Nothing recorded yet.</p>
      ) : (
        <ol className="space-y-2 border-l border-border pl-3">
          {history.map((h, i) => (
            <li key={i} className="text-sm">
              <p>
                <span className="font-medium">{HISTORY_LABEL[h.action] ?? h.action}</span>
                {h.detail && h.detail !== HISTORY_LABEL[h.action] ? <span className="text-muted-foreground"> · {h.detail}</span> : null}
              </p>
              <p className="text-xs tabular-nums text-muted-foreground">
                {/* audit_log.created_at is a UTC TIMESTAMP; shown in the reader's own time. */}
                {new Date(`${h.at.replace(" ", "T")}Z`).toLocaleString("en-GB", { dateStyle: "medium", timeStyle: "short" })}
                {h.by ? ` · ${h.by}` : ""}
              </p>
            </li>
          ))}
        </ol>
      )}
      {offers.length > 0 && (
        <p className="text-xs text-muted-foreground">
          Offers: {offers.map((o) => `${o.worker} (${o.status})`).join(", ")}
        </p>
      )}
    </section>
  )
}
