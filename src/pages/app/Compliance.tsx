import { useState } from "react"
import { Plus, ShieldCheck, ShieldAlert, ShieldX, HelpCircle } from "lucide-react"
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
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import type { ComplianceItem, ComplianceSummary, Worker } from "@/lib/types"
import { shortDate } from "@/lib/format"
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
  const { data, loading, error, reload } = useApi<Data>("compliance.php")
  const [open, setOpen] = useState(false)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Compliance & certification</h1>
          <p className="text-sm text-muted-foreground">
            Every check and certificate across the group, with what's expiring next.
          </p>
        </div>
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
    tone === "red" ? "text-destructive" : tone === "amber" ? "text-amber-600" : tone === "green" ? "text-primary" : "text-muted-foreground"
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
