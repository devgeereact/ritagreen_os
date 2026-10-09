import { useState } from "react"
import { Plus, RefreshCw, FileSignature } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent } from "@/components/ui/card"
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
import type { Contract, Client } from "@/lib/types"
import { gbp, shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Data = { contracts: Contract[]; totals: { annual_active_pence: number } }

const DIVISIONS = [
  { value: "care", label: "Healthcare" },
  { value: "academy", label: "Training" },
  { value: "cleaning", label: "Cleaning" },
  { value: "security", label: "Security" },
]
const FREQ = ["weekly", "fortnightly", "monthly", "quarterly"]

function renewalBadge(c: Contract) {
  if (c.status !== "active") return <Badge variant="outline" className="capitalize">{c.status}</Badge>
  if (c.days_to_renewal < 0) return <Badge variant="destructive">Expired</Badge>
  if (c.days_to_renewal <= 30) return <Badge variant="destructive">{c.days_to_renewal}d left</Badge>
  if (c.days_to_renewal <= 60) return <Badge variant="secondary">{c.days_to_renewal}d left</Badge>
  return <Badge variant="default">Active</Badge>
}

export default function Contracts() {
  const { data, loading, error, reload } = useApi<Data>("contracts.php")
  const [open, setOpen] = useState(false)

  async function renew(id: number) {
    try {
      await api(`contracts.php?action=renew&id=${id}`, { method: "POST", body: { months: 12 } })
      toast.success("Contract renewed by 12 months.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not renew the contract.")
    }
  }

  const contracts = data?.contracts ?? []

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Contracts</h1>
          <p className="text-sm text-muted-foreground">
            Recurring care, cleaning and guard cover. Renewals tracked so nothing lapses.
          </p>
        </div>
        <NewContractDialog open={open} onOpenChange={setOpen} onCreated={reload} />
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <Card className="max-w-sm">
            <CardContent className="flex items-center justify-between pt-6">
              <div>
                <p className="text-xs font-medium text-muted-foreground">Recurring revenue (annualised)</p>
                <p className="mt-1 text-2xl font-bold">{gbp(data.totals.annual_active_pence)}</p>
              </div>
              <FileSignature className="size-7 text-primary" />
            </CardContent>
          </Card>

          {contracts.length === 0 ? (
            <EmptyBlock
              title="No contracts yet"
              hint="Add recurring service agreements to protect repeat revenue."
              action={<Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> New contract</Button>}
            />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Contract</TableHead>
                    <TableHead>Client</TableHead>
                    <TableHead>Division</TableHead>
                    <TableHead>Rate</TableHead>
                    <TableHead>Renews</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {contracts.map((c) => (
                    <TableRow key={c.id}>
                      <TableCell className="font-medium">{c.title}</TableCell>
                      <TableCell className="text-muted-foreground">{c.client || "—"}</TableCell>
                      <TableCell className="text-muted-foreground">{DIVISION_LABELS[c.division] ?? c.division}</TableCell>
                      <TableCell className="whitespace-nowrap">{gbp(c.rate_pence)} <span className="text-xs text-muted-foreground">/ {c.frequency}</span></TableCell>
                      <TableCell className="text-muted-foreground">{shortDate(c.end_date)}</TableCell>
                      <TableCell>{renewalBadge(c)}</TableCell>
                      <TableCell className="text-right">
                        <Button size="sm" variant="outline" onClick={() => renew(c.id)}>
                          <RefreshCw className="mr-1 size-3.5" /> Renew
                        </Button>
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

function NewContractDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  onCreated: () => void
}) {
  const { data } = useApi<{ clients: Client[] }>(open ? "clients.php" : null, [open])
  const [division, setDivision] = useState("care")
  const [frequency, setFrequency] = useState("monthly")
  const [clientId, setClientId] = useState("")
  const [submitting, setSubmitting] = useState(false)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const rate = parseFloat(String(form.get("rate") || "0")) || 0
    const payload = {
      title: String(form.get("title") || "").trim(),
      division,
      frequency,
      client_id: clientId || null,
      scope: String(form.get("scope") || "").trim(),
      rate_pence: Math.round(rate * 100),
      sla_hours: String(form.get("sla_hours") || ""),
      start_date: String(form.get("start_date") || ""),
      end_date: String(form.get("end_date") || ""),
    }
    if (!payload.title || !payload.start_date || !payload.end_date) {
      toast.error("Add a title, start and end date.")
      return
    }
    setSubmitting(true)
    try {
      await api("contracts.php", { method: "POST", body: payload })
      toast.success("Contract created.")
      onOpenChange(false)
      setClientId("")
      onCreated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not create the contract.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> New contract</Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>New contract</DialogTitle>
          <DialogDescription>Scope, rate, term and SLA for a recurring service.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="title">Title</Label>
            <Input id="title" name="title" placeholder="e.g. Daily care — Rosewood House" required />
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
              <Label htmlFor="division">Division</Label>
              <Select value={division} onValueChange={setDivision}>
                <SelectTrigger id="division"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {DIVISIONS.map((d) => <SelectItem key={d.value} value={d.value}>{d.label}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="grid gap-2">
              <Label htmlFor="rate">Rate (£)</Label>
              <Input id="rate" name="rate" type="number" min="0" step="0.01" placeholder="0.00" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="frequency">Frequency</Label>
              <Select value={frequency} onValueChange={setFrequency}>
                <SelectTrigger id="frequency"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {FREQ.map((f) => <SelectItem key={f} value={f} className="capitalize">{f}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="sla_hours">SLA (hrs)</Label>
              <Input id="sla_hours" name="sla_hours" type="number" min="0" placeholder="e.g. 4" />
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="start_date">Start</Label>
              <Input id="start_date" name="start_date" type="date" required />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="end_date">End</Label>
              <Input id="end_date" name="end_date" type="date" required />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="scope">Scope (optional)</Label>
            <Textarea id="scope" name="scope" rows={3} />
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Create contract"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
