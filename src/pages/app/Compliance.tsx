import { useState } from "react"
import { Plus, ShieldCheck, ShieldAlert, ShieldX, HelpCircle, FileDown, Trash2 } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import type {
  ComplianceItem,
  ComplianceSummary,
  Worker,
  Insurance,
  InsuranceSummary,
  WorkerReference,
  CompliancePack,
} from "@/lib/types"
import { shortDate, gbp } from "@/lib/format"
import { compliancePackPdf } from "@/lib/pdf"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Data = { summary: ComplianceSummary; items: ComplianceItem[] }

const STATUS_META: Record<
  ComplianceItem["status"],
  { label: string; variant: "default" | "secondary" | "destructive" | "outline" }
> = {
  valid: { label: "Valid", variant: "default" },
  expiring: { label: "Expiring", variant: "secondary" },
  expired: { label: "Expired", variant: "destructive" },
  unknown: { label: "No date", variant: "outline" },
}

export default function Compliance() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Compliance & certification</h1>
        <p className="text-sm text-muted-foreground">
          Every check, insurance policy and reference across the group — qualified, checked and ready.
        </p>
      </div>

      <Tabs defaultValue="credentials">
        <TabsList>
          <TabsTrigger value="credentials">Credentials</TabsTrigger>
          <TabsTrigger value="insurance">Insurance</TabsTrigger>
          <TabsTrigger value="references">References</TabsTrigger>
        </TabsList>
        <TabsContent value="credentials" className="mt-6">
          <CredentialsTab />
        </TabsContent>
        <TabsContent value="insurance" className="mt-6">
          <InsuranceTab />
        </TabsContent>
        <TabsContent value="references" className="mt-6">
          <ReferencesTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}

/* ------------------------------- Credentials ------------------------------- */

function CredentialsTab() {
  const { data, loading, error, reload } = useApi<Data>("compliance.php")
  const [open, setOpen] = useState(false)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <AuditPackControl />
        <AddCertificationDialog open={open} onOpenChange={setOpen} onAdded={reload} />
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <RagCard icon={ShieldCheck} tone="green" label="Valid" value={data.summary.valid} />
            <RagCard icon={ShieldAlert} tone="amber" label="Expiring (≤30 days)" value={data.summary.expiring} />
            <RagCard icon={ShieldX} tone="red" label="Expired" value={data.summary.expired} />
            <RagCard icon={HelpCircle} tone="muted" label="No expiry date" value={data.summary.unknown} />
          </div>

          {data.items.length === 0 ? (
            <EmptyBlock
              title="No compliance records yet"
              hint="Add DBS, SIA or training certificates against workers to start tracking renewals."
              action={<Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> Add certification</Button>}
            />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Worker</TableHead>
                    <TableHead>Division</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Reference</TableHead>
                    <TableHead>Expiry</TableHead>
                    <TableHead>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.items.map((it, i) => (
                    <TableRow key={i}>
                      <TableCell className="font-medium">{it.worker}</TableCell>
                      <TableCell className="capitalize text-muted-foreground">{it.division}</TableCell>
                      <TableCell>{it.type}</TableCell>
                      <TableCell className="text-muted-foreground">{it.identifier || "—"}</TableCell>
                      <TableCell className="text-muted-foreground">{shortDate(it.expiry)}</TableCell>
                      <TableCell>
                        <Badge variant={STATUS_META[it.status].variant}>
                          {STATUS_META[it.status].label}
                          {it.status === "expiring" && it.days !== null ? ` · ${it.days}d` : ""}
                        </Badge>
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

// One-click audit pack: pick a worker, pull their full evidence bundle, render a PDF.
function AuditPackControl() {
  const { data } = useApi<{ workers: Worker[] }>("workers.php")
  const [workerId, setWorkerId] = useState("")
  const [busy, setBusy] = useState(false)

  async function download() {
    if (!workerId) {
      toast.error("Pick a worker first.")
      return
    }
    setBusy(true)
    try {
      const pack = await api<CompliancePack>(`compliance.php?action=pack&worker_id=${workerId}`)
      compliancePackPdf(pack)
      toast.success("Compliance pack generated.")
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not generate the pack.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex items-end gap-2">
      <div className="grid gap-2">
        <Label htmlFor="pack-worker" className="text-xs text-muted-foreground">Audit-ready pack</Label>
        <Select value={workerId} onValueChange={setWorkerId}>
          <SelectTrigger id="pack-worker" className="w-56"><SelectValue placeholder="Choose a worker" /></SelectTrigger>
          <SelectContent>
            {(data?.workers ?? []).map((w) => (
              <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <Button variant="outline" onClick={download} disabled={busy}>
        <FileDown className="mr-1 size-4" /> {busy ? "Building…" : "Download pack"}
      </Button>
    </div>
  )
}

function RagCard({
  icon: Icon,
  tone,
  label,
  value,
}: {
  icon: React.ComponentType<{ className?: string }>
  tone: "green" | "amber" | "red" | "muted"
  label: string
  value: number
}) {
  const color =
    tone === "red" ? "text-destructive" : tone === "amber" ? "text-warning" : tone === "green" ? "text-primary" : "text-muted-foreground"
  return (
    <Card>
      <CardContent className="flex items-center justify-between pt-6">
        <div>
          <p className="text-xs font-medium text-muted-foreground">{label}</p>
          <p className="mt-1 text-2xl font-bold">{value}</p>
        </div>
        <Icon className={`size-7 ${color}`} />
      </CardContent>
    </Card>
  )
}

function AddCertificationDialog({
  open,
  onOpenChange,
  onAdded,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  onAdded: () => void
}) {
  const { data } = useApi<{ workers: Worker[] }>(open ? "workers.php" : null, [open])
  const [workerId, setWorkerId] = useState("")
  const [submitting, setSubmitting] = useState(false)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const payload = {
      worker_id: Number(workerId),
      type: String(form.get("type") || "").trim(),
      number: String(form.get("number") || "").trim(),
      expiry: String(form.get("expiry") || ""),
    }
    if (!payload.worker_id || !payload.type) {
      toast.error("Pick a worker and a certification type.")
      return
    }
    setSubmitting(true)
    try {
      await api("compliance.php", { method: "POST", body: payload })
      toast.success("Certification added.")
      onOpenChange(false)
      setWorkerId("")
      onAdded()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not add certification.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> Add certification</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add certification</DialogTitle>
          <DialogDescription>Record a certificate or check against a worker, with its expiry.</DialogDescription>
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
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="type">Type</Label>
              <Input id="type" name="type" placeholder="e.g. Moving & handling" required />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="number">Reference (optional)</Label>
              <Input id="number" name="number" />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="expiry">Expiry date</Label>
            <Input id="expiry" name="expiry" type="date" />
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Add certification"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/* -------------------------------- Insurance -------------------------------- */

type InsuranceData = { policies: Insurance[]; summary: InsuranceSummary }

const INS_TYPES = [
  { value: "employers_liability", label: "Employers' liability" },
  { value: "public_liability", label: "Public liability" },
  { value: "professional_indemnity", label: "Professional indemnity" },
  { value: "other", label: "Other" },
]
const INS_LABEL: Record<string, string> = Object.fromEntries(INS_TYPES.map((t) => [t.value, t.label]))

function InsuranceTab() {
  const { data, loading, error, reload } = useApi<InsuranceData>("insurance.php")
  const [open, setOpen] = useState(false)

  async function remove(id: number) {
    try {
      await api(`insurance.php?action=delete&id=${id}`, { method: "POST" })
      toast.success("Policy removed.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not remove policy.")
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          {data && (
            <Badge variant={data.summary.el_minimum_met ? "default" : "destructive"}>
              {data.summary.el_minimum_met
                ? "Employers' liability ≥ £5m — met"
                : "Employers' liability under £5m — at risk"}
            </Badge>
          )}
        </div>
        <AddInsuranceDialog open={open} onOpenChange={setOpen} onAdded={reload} />
      </div>

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        data.policies.length === 0 ? (
          <EmptyBlock
            title="No insurance on file"
            hint="Add employers' liability (min £5m) and public liability so contract bids have the proof."
            action={<Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> Add policy</Button>}
          />
        ) : (
          <Card>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Type</TableHead>
                  <TableHead>Insurer</TableHead>
                  <TableHead>Policy no.</TableHead>
                  <TableHead>Cover</TableHead>
                  <TableHead>Expiry</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.policies.map((p) => (
                  <TableRow key={p.id}>
                    <TableCell className="font-medium">{INS_LABEL[p.type] ?? p.type}</TableCell>
                    <TableCell>{p.insurer}</TableCell>
                    <TableCell className="text-muted-foreground">{p.policy_no || "—"}</TableCell>
                    <TableCell>{gbp(p.cover_pence)}</TableCell>
                    <TableCell className="text-muted-foreground">{shortDate(p.expiry)}</TableCell>
                    <TableCell>
                      <Badge variant={STATUS_META[p.status].variant}>
                        {STATUS_META[p.status].label}
                        {p.status === "expiring" && p.days !== null ? ` · ${p.days}d` : ""}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <Button variant="ghost" size="icon" onClick={() => remove(p.id)} aria-label="Remove policy">
                        <Trash2 className="size-4 text-muted-foreground" />
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        )
      )}
    </div>
  )
}

function AddInsuranceDialog({
  open,
  onOpenChange,
  onAdded,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  onAdded: () => void
}) {
  const [type, setType] = useState("public_liability")
  const [submitting, setSubmitting] = useState(false)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const coverPounds = Number(form.get("cover") || 0)
    const payload = {
      type,
      insurer: String(form.get("insurer") || "").trim(),
      policy_no: String(form.get("policy_no") || "").trim(),
      cover_pence: Math.round(coverPounds * 100),
      start_date: String(form.get("start_date") || ""),
      expiry: String(form.get("expiry") || ""),
      notes: String(form.get("notes") || "").trim(),
    }
    if (!payload.insurer) {
      toast.error("Add the insurer.")
      return
    }
    setSubmitting(true)
    try {
      await api("insurance.php", { method: "POST", body: payload })
      toast.success("Policy added.")
      onOpenChange(false)
      setType("public_liability")
      onAdded()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not add policy.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> Add policy</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add insurance policy</DialogTitle>
          <DialogDescription>Company-level cover. EL must be at least £5m for most contracts.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="ins-type">Type</Label>
            <Select value={type} onValueChange={setType}>
              <SelectTrigger id="ins-type"><SelectValue /></SelectTrigger>
              <SelectContent>
                {INS_TYPES.map((t) => (
                  <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="insurer">Insurer</Label>
              <Input id="insurer" name="insurer" required />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="policy_no">Policy number</Label>
              <Input id="policy_no" name="policy_no" />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="cover">Cover (£)</Label>
            <Input id="cover" name="cover" type="number" min="0" step="1000" placeholder="5000000" />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="start_date">Start date</Label>
              <Input id="start_date" name="start_date" type="date" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="ins-expiry">Expiry date</Label>
              <Input id="ins-expiry" name="expiry" type="date" />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="notes">Notes (optional)</Label>
            <Input id="notes" name="notes" />
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Add policy"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/* ------------------------------- References -------------------------------- */

const REF_STATUS = [
  { value: "requested", label: "Requested" },
  { value: "received", label: "Received" },
  { value: "verified", label: "Verified" },
  { value: "gap_explained", label: "Gap explained" },
]
const REF_VARIANT: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  requested: "outline",
  received: "secondary",
  verified: "default",
  gap_explained: "secondary",
}

function ReferencesTab() {
  const { data: workersData } = useApi<{ workers: Worker[] }>("workers.php")
  const [workerId, setWorkerId] = useState("")
  const refs = useApi<{ references: WorkerReference[] }>(
    workerId ? `references.php?worker_id=${workerId}` : null,
    [workerId],
  )
  const [open, setOpen] = useState(false)

  const verifiedCount =
    refs.data?.references.filter((r) => r.status === "verified").length ?? 0

  async function setStatus(id: number, status: string) {
    try {
      await api(`references.php?action=status&id=${id}`, { method: "POST", body: { status } })
      refs.reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update reference.")
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="grid gap-2">
          <Label htmlFor="ref-worker" className="text-xs text-muted-foreground">Worker</Label>
          <Select value={workerId} onValueChange={setWorkerId}>
            <SelectTrigger id="ref-worker" className="w-64"><SelectValue placeholder="Choose a worker" /></SelectTrigger>
            <SelectContent>
              {(workersData?.workers ?? []).map((w) => (
                <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        {workerId && (
          <AddReferenceDialog workerId={Number(workerId)} open={open} onOpenChange={setOpen} onAdded={refs.reload} />
        )}
      </div>

      {!workerId && (
        <EmptyBlock title="Pick a worker" hint="Choose a worker to view and record their reference checks (2+ required)." />
      )}

      {workerId && (
        <>
          <Badge variant={verifiedCount >= 2 ? "default" : "destructive"}>
            {verifiedCount >= 2 ? `${verifiedCount} references verified — minimum met` : `${verifiedCount} verified — 2 required`}
          </Badge>

          {refs.loading && <LoadingBlock rows={3} />}
          {refs.error && <ErrorBlock message={refs.error} onRetry={refs.reload} />}
          {refs.data && !refs.loading && (
            refs.data.references.length === 0 ? (
              <EmptyBlock
                title="No references yet"
                hint="Record at least two references. Explain any employment gaps."
                action={<Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> Add reference</Button>}
              />
            ) : (
              <Card>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Referee</TableHead>
                      <TableHead>Organisation</TableHead>
                      <TableHead>Relationship</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Set status</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {refs.data.references.map((r) => (
                      <TableRow key={r.id}>
                        <TableCell className="font-medium">
                          {r.referee_name}
                          {r.gap_note && <div className="text-xs text-muted-foreground">Gap: {r.gap_note}</div>}
                        </TableCell>
                        <TableCell className="text-muted-foreground">{r.referee_org || "—"}</TableCell>
                        <TableCell className="text-muted-foreground">{r.relationship || "—"}</TableCell>
                        <TableCell>
                          <Badge variant={REF_VARIANT[r.status]} className="capitalize">{r.status.replace("_", " ")}</Badge>
                        </TableCell>
                        <TableCell>
                          <Select value={r.status} onValueChange={(v) => setStatus(r.id, v)}>
                            <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                            <SelectContent>
                              {REF_STATUS.map((s) => (
                                <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </Card>
            )
          )}
        </>
      )}
    </div>
  )
}

function AddReferenceDialog({
  workerId,
  open,
  onOpenChange,
  onAdded,
}: {
  workerId: number
  open: boolean
  onOpenChange: (o: boolean) => void
  onAdded: () => void
}) {
  const [submitting, setSubmitting] = useState(false)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const payload = {
      worker_id: workerId,
      referee_name: String(form.get("referee_name") || "").trim(),
      referee_org: String(form.get("referee_org") || "").trim(),
      relationship: String(form.get("relationship") || "").trim(),
      email: String(form.get("email") || "").trim(),
      phone: String(form.get("phone") || "").trim(),
    }
    if (!payload.referee_name) {
      toast.error("Add the referee's name.")
      return
    }
    setSubmitting(true)
    try {
      await api("references.php", { method: "POST", body: payload })
      toast.success("Reference added.")
      onOpenChange(false)
      onAdded()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not add reference.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> Add reference</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add reference</DialogTitle>
          <DialogDescription>Record a referee. At least two verified references are required.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="referee_name">Referee name</Label>
            <Input id="referee_name" name="referee_name" required />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="referee_org">Organisation</Label>
              <Input id="referee_org" name="referee_org" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="relationship">Relationship</Label>
              <Input id="relationship" name="relationship" placeholder="e.g. Former manager" />
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="ref-email">Email</Label>
              <Input id="ref-email" name="email" type="email" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="ref-phone">Phone</Label>
              <Input id="ref-phone" name="phone" type="tel" />
            </div>
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Add reference"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
