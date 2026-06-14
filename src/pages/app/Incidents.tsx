import { useState } from "react"
import { Plus, ShieldAlert, AlertTriangle, FileWarning, Flame } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from "@/components/ui/table"
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger,
} from "@/components/ui/dialog"
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import type { Incident, IncidentSummary, Client, Worker } from "@/lib/types"
import { shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Data = { incidents: Incident[]; summary: IncidentSummary }

const SEV_VARIANT: Record<Incident["severity"], "default" | "secondary" | "destructive" | "outline"> = {
  low: "outline", medium: "secondary", high: "destructive", critical: "destructive",
}
const STATUS_VARIANT: Record<Incident["status"], "default" | "secondary" | "destructive" | "outline"> = {
  open: "destructive", investigating: "secondary", actioned: "secondary", closed: "default",
}
const TYPES = [
  { value: "incident", label: "Incident" },
  { value: "accident", label: "Accident" },
  { value: "near_miss", label: "Near miss" },
  { value: "safeguarding", label: "Safeguarding" },
  { value: "complaint", label: "Complaint" },
]
const DIVS = ["care", "academy", "cleaning", "security"]

export default function Incidents() {
  const { data, loading, error, reload } = useApi<Data>("incidents.php")
  const [open, setOpen] = useState(false)

  async function setStatus(id: number, status: string) {
    try {
      await api(`incidents.php?action=status&id=${id}`, { method: "POST", body: { status } })
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update.")
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Incidents & safeguarding</h1>
          <p className="text-sm text-muted-foreground">Report, escalate and track to resolution. Serious flags alert the office.</p>
        </div>
        <NewIncidentDialog open={open} onOpenChange={setOpen} onCreated={reload} />
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Stat icon={AlertTriangle} tone="amber" label="Open" value={data.summary.open} />
            <Stat icon={ShieldAlert} tone="red" label="Safeguarding" value={data.summary.safeguarding} />
            <Stat icon={FileWarning} tone="red" label="RIDDOR" value={data.summary.riddor} />
            <Stat icon={Flame} tone="red" label="Critical" value={data.summary.critical} />
          </div>

          {data.incidents.length === 0 ? (
            <EmptyBlock title="No incidents logged" hint="Report accidents, near-misses, safeguarding concerns and complaints here." />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Ref</TableHead>
                    <TableHead>Title</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Division</TableHead>
                    <TableHead>Severity</TableHead>
                    <TableHead>Flags</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.incidents.map((i) => (
                    <TableRow key={i.id}>
                      <TableCell className="font-mono text-xs">{i.ref}</TableCell>
                      <TableCell className="font-medium">
                        {i.title}
                        <div className="text-xs text-muted-foreground">{i.worker || i.client || ""} · {shortDate(i.created_at)}</div>
                      </TableCell>
                      <TableCell className="capitalize text-muted-foreground">{i.type.replace("_", " ")}</TableCell>
                      <TableCell className="text-muted-foreground">{DIVISION_LABELS[i.division] ?? i.division}</TableCell>
                      <TableCell><Badge variant={SEV_VARIANT[i.severity]} className="capitalize">{i.severity}</Badge></TableCell>
                      <TableCell className="space-x-1">
                        {i.safeguarding ? <Badge variant="destructive">SG</Badge> : null}
                        {i.riddor ? <Badge variant="destructive">RIDDOR</Badge> : null}
                      </TableCell>
                      <TableCell>
                        <Select value={i.status} onValueChange={(v) => setStatus(i.id, v)}>
                          <SelectTrigger className="h-8 w-36"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            {["open", "investigating", "actioned", "closed"].map((s) => (
                              <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </TableCell>
                      <TableCell>
                        <Badge variant={STATUS_VARIANT[i.status]} className="capitalize">{i.status}</Badge>
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

function Stat({ icon: Icon, tone, label, value }: { icon: React.ComponentType<{ className?: string }>; tone: "amber" | "red"; label: string; value: number }) {
  const color = tone === "red" ? "text-destructive" : "text-warning"
  return (
    <Card><CardContent className="flex items-center justify-between pt-6">
      <div><p className="text-xs font-medium text-muted-foreground">{label}</p><p className="mt-1 text-2xl font-bold">{value}</p></div>
      <Icon className={`size-7 ${color}`} />
    </CardContent></Card>
  )
}

function NewIncidentDialog({ open, onOpenChange, onCreated }: { open: boolean; onOpenChange: (o: boolean) => void; onCreated: () => void }) {
  const { data: clientsData } = useApi<{ clients: Client[] }>(open ? "clients.php" : null, [open])
  const { data: workersData } = useApi<{ workers: Worker[] }>(open ? "workers.php" : null, [open])
  const [type, setType] = useState("incident")
  const [severity, setSeverity] = useState("low")
  const [division, setDivision] = useState("care")
  const [clientId, setClientId] = useState("none")
  const [workerId, setWorkerId] = useState("none")
  const [riddor, setRiddor] = useState(false)
  const [safeguarding, setSafeguarding] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const payload = {
      type, severity, division,
      title: String(form.get("title") || "").trim(),
      detail: String(form.get("detail") || "").trim(),
      site_name: String(form.get("site_name") || "").trim(),
      client_id: clientId === "none" ? "" : clientId,
      worker_id: workerId === "none" ? "" : workerId,
      occurred_at: String(form.get("occurred_at") || ""),
      riddor, safeguarding,
    }
    if (!payload.title || !payload.detail) {
      toast.error("Add a title and detail.")
      return
    }
    setSubmitting(true)
    try {
      const r = await api<{ ref: string }>("incidents.php", { method: "POST", body: payload })
      toast.success(`Logged ${r.ref}.`)
      onOpenChange(false)
      onCreated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not log the incident.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild><Button><Plus className="mr-1 size-4" /> Report incident</Button></DialogTrigger>
      <DialogContent className="max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Report an incident</DialogTitle>
          <DialogDescription>Safeguarding, RIDDOR or critical reports alert the office immediately.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2"><Label htmlFor="title">Title</Label><Input id="title" name="title" required /></div>
          <div className="grid gap-2"><Label htmlFor="detail">What happened</Label><Textarea id="detail" name="detail" rows={4} required /></div>
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="grid gap-2"><Label>Type</Label>
              <Select value={type} onValueChange={setType}><SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>{TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}</SelectContent></Select>
            </div>
            <div className="grid gap-2"><Label>Severity</Label>
              <Select value={severity} onValueChange={setSeverity}><SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>{["low", "medium", "high", "critical"].map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}</SelectContent></Select>
            </div>
            <div className="grid gap-2"><Label>Division</Label>
              <Select value={division} onValueChange={setDivision}><SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>{DIVS.map((d) => <SelectItem key={d} value={d} className="capitalize">{d}</SelectItem>)}</SelectContent></Select>
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2"><Label>Client (optional)</Label>
              <Select value={clientId} onValueChange={setClientId}><SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                <SelectContent><SelectItem value="none">None</SelectItem>{(clientsData?.clients ?? []).map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent></Select>
            </div>
            <div className="grid gap-2"><Label>Worker (optional)</Label>
              <Select value={workerId} onValueChange={setWorkerId}><SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                <SelectContent><SelectItem value="none">None</SelectItem>{(workersData?.workers ?? []).map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>)}</SelectContent></Select>
            </div>
          </div>
          <div className="grid gap-2"><Label htmlFor="occurred_at">When it happened</Label><Input id="occurred_at" name="occurred_at" type="datetime-local" /></div>
          <div className="flex gap-6">
            <label className="flex items-center gap-2 text-sm"><Checkbox checked={safeguarding} onCheckedChange={(v) => setSafeguarding(!!v)} /> Safeguarding concern</label>
            <label className="flex items-center gap-2 text-sm"><Checkbox checked={riddor} onCheckedChange={(v) => setRiddor(!!v)} /> RIDDOR-reportable</label>
          </div>
          <DialogFooter><Button type="submit" disabled={submitting}>{submitting ? "Logging…" : "Log incident"}</Button></DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
