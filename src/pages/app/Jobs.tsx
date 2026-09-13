import { useEffect, useState } from "react"
import { Plus, Search, Pencil, Archive } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import { Card } from "@/components/ui/card"
import { Textarea } from "@/components/ui/textarea"
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
} from "@/components/ui/dialog"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useApi } from "@/hooks/use-api"
import { useAuth } from "@/providers/auth-provider"
import { api, ApiError } from "@/lib/api"
import type { Job, Client } from "@/lib/types"
import { shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const STATUSES: Job["status"][] = ["booked", "assigned", "in_progress", "done", "cancelled"]
// A type implies a division, except "Other", which implies nothing.
//
// `other` used to be mapped to `care` and the division was never shown, so an
// "Other" cleaning or security work order was filed under Healthcare & Care with
// nothing on screen to say so. Choosing a type still fills the division in --
// that convenience was worth keeping -- but the field is visible and editable,
// and "Other" leaves whatever is already selected alone.
const TYPES: { value: string; label: string; division: string | null }[] = [
  { value: "care_visit", label: "Care visit", division: "care" },
  { value: "clean", label: "Clean", division: "cleaning" },
  { value: "guard_shift", label: "Guard shift", division: "security" },
  { value: "course", label: "Course", division: "academy" },
  { value: "other", label: "Other", division: null },
]

const DIVISIONS = ["care", "academy", "cleaning", "security"] as const

/**
 * Radix Select uses the empty string internally for "no value", so the
 * no-client option needs a real one, translated back to null for the server.
 * Without it a client chosen by mistake could not be cleared.
 */
const NO_CLIENT = "none"

const PRIORITY_VARIANT: Record<Job["priority"], "destructive" | "secondary" | "outline"> = {
  emergency: "destructive",
  high: "secondary",
  standard: "outline",
}

export default function Jobs() {
  // Reading jobs is staff-wide; creating one, and the clients.php lookup the
  // dialog needs, are office-only on the server.
  const { hasRole } = useAuth()
  const canCreate = hasRole("super_admin", "admin", "manager")
  const { data, loading, error, reload } = useApi<{ jobs: Job[]; truncated: boolean }>("jobs.php")
  const [q, setQ] = useState("")
  const [filter, setFilter] = useState<"all" | Job["status"]>("all")
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<Job | null>(null)
  const [archiving, setArchiving] = useState<Job | null>(null)

  const jobs = data?.jobs ?? []
  const filtered = jobs.filter(
    (j) =>
      (filter === "all" || j.status === filter) &&
      [j.title, j.client].some((f) => f?.toLowerCase().includes(q.toLowerCase())),
  )

  async function archive(job: Job) {
    try {
      await api(`jobs.php?action=archive&id=${job.id}`, { method: "POST", body: {} })
      toast.success("Job archived.")
      setArchiving(null)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not archive the job.")
    }
  }

  async function setStatus(id: number, status: Job["status"]) {
    try {
      await api(`jobs.php?action=status&id=${id}`, { method: "POST", body: { status } })
      // Changing a job's state through a dropdown gave no acknowledgement at
      // all, so the only way to know it had saved was to watch the row redraw.
      toast.success(`Job marked ${status.replace("_", " ")}.`)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update the job.")
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Jobs & work orders</h1>
          <p className="text-sm text-muted-foreground">
            Care visits, cleans, guard shifts and courses, on one work-order spine.
          </p>
        </div>
        {canCreate && (
          <Button onClick={() => { setEditing(null); setOpen(true) }}>
            <Plus className="mr-1 size-4" /> New job
          </Button>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative max-w-xs flex-1">
          <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input placeholder="Search jobs…" value={q} onChange={(e) => setQ(e.target.value)} className="pl-9" />
        </div>
        <div className="flex flex-wrap gap-1">
          {(["all", ...STATUSES] as const).map((s) => (
            <button
              key={s}
              onClick={() => setFilter(s)}
              aria-pressed={filter === s}
              className={
                "rounded-full px-3 py-1 text-xs font-medium capitalize transition-colors " +
                (filter === s
                  ? "bg-primary text-primary-foreground"
                  : "bg-muted text-muted-foreground hover:bg-accent")
              }
            >
              {s.replace("_", " ")}
            </button>
          ))}
        </div>
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && jobs.length === 0 && (
        <EmptyBlock
          title="No jobs yet"
          hint="Raise your first work order — care visit, clean, guard shift or course."
          action={canCreate ? <Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> New job</Button> : undefined}
        />
      )}

      {data && !loading && jobs.length > 0 && (
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Job</TableHead>
                <TableHead>Division</TableHead>
                <TableHead>Client</TableHead>
                <TableHead>Priority</TableHead>
                <TableHead>Scheduled</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-px"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((j) => (
                <TableRow key={j.id}>
                  <TableCell className="font-medium">
                    {j.title}
                    {j.notes && (
                      <p className="mt-0.5 max-w-sm text-xs font-normal leading-snug text-muted-foreground">
                        {j.notes}
                      </p>
                    )}
                  </TableCell>
                  <TableCell className="text-muted-foreground">{DIVISION_LABELS[j.division] ?? j.division}</TableCell>
                  <TableCell className="text-muted-foreground">{j.client || "—"}</TableCell>
                  <TableCell>
                    <Badge variant={PRIORITY_VARIANT[j.priority]} className="capitalize">{j.priority}</Badge>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{shortDate(j.scheduled_for)}</TableCell>
                  <TableCell>
                    <Select value={j.status} onValueChange={(v) => setStatus(j.id, v as Job["status"])}>
                      <SelectTrigger className="h-8 w-36">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {STATUSES.map((s) => (
                          <SelectItem key={s} value={s} className="capitalize">{s.replace("_", " ")}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </TableCell>
                  <TableCell className="text-right">
                    {canCreate && (
                      <div className="flex justify-end gap-1">
                        <Button size="sm" variant="ghost" aria-label={`Edit ${j.title}`} onClick={() => { setEditing(j); setOpen(true) }}>
                          <Pencil className="size-4" />
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          aria-label={`Archive ${j.title}`}
                          className="text-destructive hover:text-destructive"
                          onClick={() => setArchiving(j)}
                        >
                          <Archive className="size-4" />
                        </Button>
                      </div>
                    )}
                  </TableCell>
                </TableRow>
              ))}
              {filtered.length === 0 && (
                <TableRow>
                  <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                    No jobs match the filters.
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </Card>
      )}

      {data?.truncated && (
        <p className="text-sm text-muted-foreground">
          Showing the first 500 work orders. Narrow the search or the status filter to see the rest.
        </p>
      )}

      <JobDialog open={open} job={editing} onOpenChange={setOpen} onSaved={reload} />

      <Dialog open={archiving !== null} onOpenChange={(o) => !o && setArchiving(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Archive this work order?</DialogTitle>
            <DialogDescription>
              {archiving
                ? `"${archiving.title}" comes off the board. The record is kept — nothing here is hard-deleted — so it can be brought back if this was a mistake.`
                : ""}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setArchiving(null)}>Keep it</Button>
            <Button variant="destructive" onClick={() => archiving && archive(archiving)}>Archive</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

/**
 * One dialog for raising a work order and for correcting one.
 *
 * There was no edit at all: a typo in a title, a job raised against the wrong
 * client or scheduled for the wrong day could only be cancelled and re-raised,
 * which loses the history of what was actually asked for.
 */
function JobDialog({
  open,
  job,
  onOpenChange,
  onSaved,
}: {
  open: boolean
  /** null to raise a new one, a job to correct it. */
  job: Job | null
  onOpenChange: (o: boolean) => void
  onSaved: () => void
}) {
  const { data, loading, error } = useApi<{ clients: Client[] }>(open ? "clients.php" : null, [open])
  const [type, setType] = useState<string>(job?.type ?? "care_visit")
  const [division, setDivision] = useState<string>(job?.division ?? "care")
  const [priority, setPriority] = useState<string>(job?.priority ?? "standard")
  const [clientId, setClientId] = useState<string>(job?.client_id ? String(job.client_id) : NO_CLIENT)
  const [submitting, setSubmitting] = useState(false)

  // Re-seed when a different job is opened: the component stays mounted between
  // rows, so without this the second row edited shows the first one's values.
  useEffect(() => {
    if (!open) return
    setType(job?.type ?? "care_visit")
    setDivision(job?.division ?? "care")
    setPriority(job?.priority ?? "standard")
    setClientId(job?.client_id ? String(job.client_id) : NO_CLIENT)
  }, [open, job])

  function chooseType(next: string) {
    setType(next)
    const implied = TYPES.find((t) => t.value === next)?.division
    if (implied) setDivision(implied)
  }

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const payload = {
      title: String(form.get("title") || "").trim(),
      type,
      division,
      priority,
      client_id: clientId === NO_CLIENT ? null : clientId,
      scheduled_for: String(form.get("scheduled_for") || ""),
      notes: String(form.get("notes") || "").trim(),
    }
    if (!payload.title) {
      toast.error("Add a job title.")
      return
    }
    setSubmitting(true)
    try {
      await api(job ? `jobs.php?action=update&id=${job.id}` : "jobs.php", { method: "POST", body: payload })
      toast.success(job ? "Job updated." : "Job created.")
      onOpenChange(false)
      onSaved()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not save the job.")
    } finally {
      setSubmitting(false)
    }
  }

  /** datetime-local wants "YYYY-MM-DDTHH:MM"; the API returns a SQL datetime. */
  const scheduledDefault = job?.scheduled_for ? job.scheduled_for.replace(" ", "T").slice(0, 16) : ""

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{job ? "Edit work order" : "New work order"}</DialogTitle>
          <DialogDescription>Choosing a type fills in the division; change it if it is wrong.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="title">Title</Label>
            <Input
              id="title"
              name="title"
              defaultValue={job?.title ?? ""}
              placeholder="e.g. Morning care visit — Mrs Patel"
              maxLength={190}
              required
            />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="type">Type</Label>
              <Select value={type} onValueChange={chooseType}>
                <SelectTrigger id="type"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="division">Division</Label>
              <Select value={division} onValueChange={setDivision}>
                <SelectTrigger id="division"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {DIVISIONS.map((d) => (
                    <SelectItem key={d} value={d}>{DIVISION_LABELS[d] ?? d}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="priority">Priority</Label>
              <Select value={priority} onValueChange={setPriority}>
                <SelectTrigger id="priority"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="emergency">Emergency</SelectItem>
                  <SelectItem value="high">High</SelectItem>
                  <SelectItem value="standard">Standard</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="client">Client (optional)</Label>
              <Select value={clientId} onValueChange={setClientId}>
                <SelectTrigger id="client"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {/* Without this a client chosen by mistake could not be cleared. */}
                  <SelectItem value={NO_CLIENT}>No client</SelectItem>
                  {(data?.clients ?? []).map((c) => (
                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {/* An empty client list and a failed request used to look identical. */}
              {loading && <p className="text-xs text-muted-foreground">Loading clients…</p>}
              {error && <p className="text-xs text-destructive">Clients could not be loaded: {error}</p>}
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="scheduled_for">Scheduled for (optional)</Label>
            <Input id="scheduled_for" name="scheduled_for" type="datetime-local" defaultValue={scheduledDefault} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="notes">Notes (optional)</Label>
            <Textarea id="notes" name="notes" rows={3} defaultValue={job?.notes ?? ""} maxLength={5000} />
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : job ? "Save changes" : "Create job"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
