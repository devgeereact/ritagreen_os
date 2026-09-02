import { useEffect, useState } from "react"
import { Plus, Search, ShieldAlert, ShieldCheck, ShieldQuestion, Pencil } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import { Card } from "@/components/ui/card"
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
import { Checkbox } from "@/components/ui/checkbox"
import type { Worker, Skill } from "@/lib/types"
import { shortDate, gbp } from "@/lib/format"
import { EligibilityDialog } from "@/components/app/eligibility-dialog"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const ROLES = [
  { value: "carer", label: "Carer", division: "care" },
  { value: "nurse", label: "Nurse", division: "care" },
  { value: "cleaner", label: "Cleaner", division: "cleaning" },
  { value: "guard", label: "Security officer", division: "security" },
  { value: "trainer", label: "Trainer", division: "academy" },
]

export default function Workers() {
  const { data, loading, error, reload } = useApi<{ workers: Worker[] }>("workers.php")
  const [q, setQ] = useState("")
  const [open, setOpen] = useState(false)
  const [payWorker, setPayWorker] = useState<Worker | null>(null)
  // Which worker's per-service eligibility is being shown.
  const [eligibilityFor, setEligibilityFor] = useState<{ id: number; name: string } | null>(null)

  const workers = data?.workers ?? []
  const filtered = workers.filter((w) =>
    [w.name, w.email, w.role].some((f) => f?.toLowerCase().includes(q.toLowerCase())),
  )

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Workers</h1>
          <p className="text-sm text-muted-foreground">
            One workforce across four divisions, fully checked before first shift.
          </p>
        </div>
        <NewWorkerDialog open={open} onOpenChange={setOpen} onCreated={reload} />
      </div>

      <div className="relative max-w-sm">
        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input placeholder="Search workers…" value={q} onChange={(e) => setQ(e.target.value)} className="pl-9" />
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && workers.length === 0 && (
        <EmptyBlock
          title="No workers yet"
          hint="Onboard your first worker. Right-to-work and DBS are captured up front."
          action={<Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> Onboard worker</Button>}
        />
      )}

      {data && !loading && workers.length > 0 && (
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Worker</TableHead>
                <TableHead>Role</TableHead>
                <TableHead>Division</TableHead>
                <TableHead>Right to work</TableHead>
                <TableHead>DBS</TableHead>
                <TableHead>SIA</TableHead>
                <TableHead>Pay rate</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((w) => (
                <TableRow key={w.id}>
                  <TableCell>
                    <div className="font-medium">{w.name}</div>
                    {w.email && <div className="text-xs text-muted-foreground">{w.email}</div>}
                  </TableCell>
                  <TableCell className="capitalize">{w.role}</TableCell>
                  <TableCell className="capitalize">{w.division}</TableCell>
                  <TableCell>
                    {w.right_to_work ? (
                      <span className="inline-flex items-center gap-1 text-sm text-primary">
                        <ShieldCheck className="size-4" /> Verified
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 text-sm text-destructive">
                        <ShieldAlert className="size-4" /> Missing
                      </span>
                    )}
                  </TableCell>
                  <TableCell><ComplianceCell status={w.dbs_status} expiry={w.dbs_expiry} /></TableCell>
                  <TableCell>
                    {w.sia_number ? <ComplianceCell status="valid" expiry={w.sia_expiry} /> : <span className="text-muted-foreground">—</span>}
                  </TableCell>
                  <TableCell>
                    <button
                      type="button"
                      onClick={() => setPayWorker(w)}
                      className="inline-flex items-center gap-1 text-sm hover:text-primary"
                    >
                      {w.pay_rate_pence ? (
                        <span>{gbp(w.pay_rate_pence)}/hr</span>
                      ) : (
                        <span className="text-muted-foreground">Set rate</span>
                      )}
                      <Pencil className="size-3 text-muted-foreground" />
                    </button>
                    {w.pay_type && <div className="text-xs uppercase text-muted-foreground">{w.pay_type}</div>}
                  </TableCell>
                  <TableCell>
                    {/* The stored status is what the office intends. Eligibility is
                        what the rules and the evidence actually allow, per service,
                        and it is only ever calculated. */}
                    <button
                      type="button"
                      onClick={() => setEligibilityFor({ id: w.id, name: w.name })}
                      className="inline-flex items-center gap-1 hover:opacity-80"
                      title="Show eligibility for each service"
                    >
                      <Badge variant={w.status === "active" ? "default" : "secondary"} className="capitalize">
                        {w.status}
                      </Badge>
                      <ShieldQuestion className="size-3.5 text-muted-foreground" />
                    </button>
                  </TableCell>
                </TableRow>
              ))}
              {filtered.length === 0 && (
                <TableRow>
                  <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                    No workers match "{q}".
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </Card>
      )}

      <EligibilityDialog
        workerId={eligibilityFor?.id ?? null}
        workerName={eligibilityFor?.name}
        onOpenChange={(o) => !o && setEligibilityFor(null)}
      />
      <SetPayDialog worker={payWorker} onOpenChange={(o) => !o && setPayWorker(null)} onSaved={reload} />
    </div>
  )
}

// Full worker editor: pay, availability + travel, and skills — all the inputs
// the scheduler's smart match relies on. Saved in one go.
function SetPayDialog({
  worker,
  onOpenChange,
  onSaved,
}: {
  worker: Worker | null
  onOpenChange: (o: boolean) => void
  onSaved: () => void
}) {
  const [rate, setRate] = useState("")
  const [type, setType] = useState("paye")
  const [days, setDays] = useState(true)
  const [nights, setNights] = useState(false)
  const [weekends, setWeekends] = useState(false)
  const [radius, setRadius] = useState("10")
  const [postcode, setPostcode] = useState("")
  const [allSkills, setAllSkills] = useState<Skill[]>([])
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [submitting, setSubmitting] = useState(false)

  // Seed when a worker opens; pull their skills.
  useEffect(() => {
    if (!worker) return
    setRate(worker.pay_rate_pence ? (worker.pay_rate_pence / 100).toString() : "")
    setType(worker.pay_type ?? "paye")
    setDays(worker.avail_days ?? true)
    setNights(worker.avail_nights ?? false)
    setWeekends(worker.avail_weekends ?? false)
    setRadius(String(worker.travel_radius_mi ?? 10))
    setPostcode(worker.home_postcode ?? "")
    api<{ skills: Skill[]; assigned: number[] }>(`skills.php?worker_id=${worker.id}`)
      .then((r) => {
        setAllSkills(r.skills)
        setSelected(new Set(r.assigned))
      })
      .catch(() => {})
  }, [worker])

  function toggleSkill(id: number) {
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    if (!worker) return
    setSubmitting(true)
    try {
      await Promise.all([
        api(`workers.php?action=pay&id=${worker.id}`, {
          method: "POST",
          body: { pay_rate_pence: Math.round(Number(rate || 0) * 100), pay_type: type },
        }),
        api(`workers.php?action=availability&id=${worker.id}`, {
          method: "POST",
          body: {
            avail_days: days, avail_nights: nights, avail_weekends: weekends,
            travel_radius_mi: Number(radius || 0), home_postcode: postcode,
          },
        }),
        api(`skills.php?action=set&worker_id=${worker.id}`, {
          method: "POST",
          body: { skill_ids: Array.from(selected) },
        }),
      ])
      toast.success("Worker updated.")
      onOpenChange(false)
      onSaved()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update worker.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={!!worker} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Edit worker — {worker?.name}</DialogTitle>
          <DialogDescription>Pay, availability and skills. The scheduler matches on all three.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-5">
          <section className="grid gap-3">
            <h3 className="text-sm font-semibold">Pay</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="grid gap-2">
                <Label htmlFor="pay-rate">Base rate (£/hr)</Label>
                <Input id="pay-rate" type="number" min="0" step="0.01" value={rate} onChange={(e) => setRate(e.target.value)} placeholder="13.50" />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="pay-type">Pay type</Label>
                <Select value={type} onValueChange={setType}>
                  <SelectTrigger id="pay-type"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="paye">PAYE</SelectItem>
                    <SelectItem value="umbrella">Umbrella</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </section>

          <section className="grid gap-3">
            <h3 className="text-sm font-semibold">Availability & travel</h3>
            <div className="flex flex-wrap gap-4">
              <label className="flex items-center gap-2 text-sm"><Checkbox checked={days} onCheckedChange={(v) => setDays(!!v)} /> Days</label>
              <label className="flex items-center gap-2 text-sm"><Checkbox checked={nights} onCheckedChange={(v) => setNights(!!v)} /> Nights</label>
              <label className="flex items-center gap-2 text-sm"><Checkbox checked={weekends} onCheckedChange={(v) => setWeekends(!!v)} /> Weekends</label>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="grid gap-2">
                <Label htmlFor="postcode">Home postcode</Label>
                <Input id="postcode" value={postcode} onChange={(e) => setPostcode(e.target.value)} placeholder="CR0 1AB" />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="radius">Travel radius (miles)</Label>
                <Input id="radius" type="number" min="0" value={radius} onChange={(e) => setRadius(e.target.value)} />
              </div>
            </div>
          </section>

          <section className="grid gap-3">
            <h3 className="text-sm font-semibold">Skills</h3>
            <div className="grid grid-cols-2 gap-2">
              {allSkills.map((s) => (
                <label key={s.id} className="flex items-center gap-2 text-sm">
                  <Checkbox checked={selected.has(s.id)} onCheckedChange={() => toggleSkill(s.id)} />
                  {s.name}
                </label>
              ))}
              {allSkills.length === 0 && <p className="text-sm text-muted-foreground">No skills in the catalogue yet.</p>}
            </div>
          </section>

          <DialogFooter>
            <Button type="submit" disabled={submitting}>{submitting ? "Saving…" : "Save worker"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function ComplianceCell({ status, expiry }: { status: string | null; expiry: string | null }) {
  if (!status) return <span className="text-muted-foreground">—</span>
  const expired = expiry ? new Date(expiry) < new Date() : false
  const variant = expired || status === "expired" ? "destructive" : status === "pending" ? "secondary" : "default"
  return (
    <div className="text-sm">
      <Badge variant={variant} className="capitalize">{expired ? "expired" : status}</Badge>
      {expiry && <div className="mt-0.5 text-xs text-muted-foreground">{shortDate(expiry)}</div>}
    </div>
  )
}

function NewWorkerDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  onCreated: () => void
}) {
  const [role, setRole] = useState("carer")
  const [rtw, setRtw] = useState(false)
  const [payType, setPayType] = useState("paye")
  const [submitting, setSubmitting] = useState(false)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const data = new FormData(e.currentTarget)
    const roleDef = ROLES.find((r) => r.value === role)
    const payload = {
      name: String(data.get("name") || "").trim(),
      email: String(data.get("email") || "").trim(),
      phone: String(data.get("phone") || "").trim(),
      role,
      division: roleDef?.division ?? "care",
      right_to_work: rtw,
      pay_rate_pence: Math.round(Number(data.get("pay_rate") || 0) * 100),
      pay_type: payType,
    }
    if (!payload.name) {
      toast.error("Add the worker's name.")
      return
    }
    setSubmitting(true)
    try {
      await api("workers.php", { method: "POST", body: payload })
      toast.success("Worker onboarded.")
      onOpenChange(false)
      onCreated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not onboard worker.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> Onboard worker</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Onboard worker</DialogTitle>
          <DialogDescription>Capture the basics and right-to-work. Certificates come next.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="name">Full name</Label>
            <Input id="name" name="name" required />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="email">Email</Label>
              <Input id="email" name="email" type="email" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="phone">Phone</Label>
              <Input id="phone" name="phone" type="tel" />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="role">Role</Label>
            <Select value={role} onValueChange={setRole}>
              <SelectTrigger id="role"><SelectValue /></SelectTrigger>
              <SelectContent>
                {ROLES.map((r) => (
                  <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="pay_rate">Base rate (£/hr)</Label>
              <Input id="pay_rate" name="pay_rate" type="number" min="0" step="0.01" placeholder="13.50" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="onboard-pay-type">Pay type</Label>
              <Select value={payType} onValueChange={setPayType}>
                <SelectTrigger id="onboard-pay-type"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="paye">PAYE</SelectItem>
                  <SelectItem value="umbrella">Umbrella</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={rtw}
              onChange={(e) => setRtw(e.target.checked)}
              className="size-4 rounded border-border accent-primary"
            />
            Right to work verified
          </label>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Onboard worker"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
