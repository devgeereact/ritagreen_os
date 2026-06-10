import { useState } from "react"
import { Plus, Search, ShieldAlert, ShieldCheck } from "lucide-react"
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
import type { Worker } from "@/lib/types"
import { shortDate } from "@/lib/format"
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
                    <Badge variant={w.status === "active" ? "default" : "secondary"} className="capitalize">
                      {w.status}
                    </Badge>
                  </TableCell>
                </TableRow>
              ))}
              {filtered.length === 0 && (
                <TableRow>
                  <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                    No workers match "{q}".
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
