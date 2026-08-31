import { useState } from "react"
import { Plus, Search } from "lucide-react"
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
import type { Job, Client } from "@/lib/types"
import { shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const STATUSES: Job["status"][] = ["booked", "assigned", "in_progress", "done", "cancelled"]
const TYPES = [
  { value: "care_visit", label: "Care visit", division: "care" },
  { value: "clean", label: "Clean", division: "cleaning" },
  { value: "guard_shift", label: "Guard shift", division: "security" },
  { value: "course", label: "Course", division: "academy" },
  { value: "other", label: "Other", division: "care" },
]

const PRIORITY_VARIANT: Record<Job["priority"], "destructive" | "secondary" | "outline"> = {
  emergency: "destructive",
  high: "secondary",
  standard: "outline",
}

export default function Jobs() {
  const { data, loading, error, reload } = useApi<{ jobs: Job[] }>("jobs.php")
  const [q, setQ] = useState("")
  const [filter, setFilter] = useState<"all" | Job["status"]>("all")
  const [open, setOpen] = useState(false)

  const jobs = data?.jobs ?? []
  const filtered = jobs.filter(
    (j) =>
      (filter === "all" || j.status === filter) &&
      [j.title, j.client].some((f) => f?.toLowerCase().includes(q.toLowerCase())),
  )

  async function setStatus(id: number, status: Job["status"]) {
    try {
      await api(`jobs.php?action=status&id=${id}`, { method: "POST", body: { status } })
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
        <NewJobDialog open={open} onOpenChange={setOpen} onCreated={reload} />
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
          action={<Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> New job</Button>}
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
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((j) => (
                <TableRow key={j.id}>
                  <TableCell className="font-medium">{j.title}</TableCell>
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
                </TableRow>
              ))}
              {filtered.length === 0 && (
                <TableRow>
                  <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                    No jobs match the filters.
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </Card>
      )}
    </div>
  )
}

function NewJobDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  onCreated: () => void
}) {
  const { data } = useApi<{ clients: Client[] }>(open ? "clients.php" : null, [open])
  const [type, setType] = useState("care_visit")
  const [priority, setPriority] = useState("standard")
  const [clientId, setClientId] = useState("")
  const [submitting, setSubmitting] = useState(false)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const division = TYPES.find((t) => t.value === type)?.division ?? "care"
    const payload = {
      title: String(form.get("title") || "").trim(),
      type,
      division,
      priority,
      client_id: clientId || null,
      scheduled_for: String(form.get("scheduled_for") || ""),
      notes: String(form.get("notes") || "").trim(),
    }
    if (!payload.title) {
      toast.error("Add a job title.")
      return
    }
    setSubmitting(true)
    try {
      await api("jobs.php", { method: "POST", body: payload })
      toast.success("Job created.")
      onOpenChange(false)
      setClientId("")
      onCreated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not create the job.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> New job</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>New work order</DialogTitle>
          <DialogDescription>The type sets the division automatically.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="title">Title</Label>
            <Input id="title" name="title" placeholder="e.g. Morning care visit — Mrs Patel" required />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="type">Type</Label>
              <Select value={type} onValueChange={setType}>
                <SelectTrigger id="type"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
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
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="client">Client (optional)</Label>
              <Select value={clientId} onValueChange={setClientId}>
                <SelectTrigger id="client"><SelectValue placeholder="None" /></SelectTrigger>
                <SelectContent>
                  {(data?.clients ?? []).map((c) => (
                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="scheduled_for">Scheduled for (optional)</Label>
              <Input id="scheduled_for" name="scheduled_for" type="datetime-local" />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="notes">Notes (optional)</Label>
            <Textarea id="notes" name="notes" rows={3} />
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Create job"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
