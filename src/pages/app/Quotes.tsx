import { useState } from "react"
import { Plus, FileText, CheckCircle2, FileDown } from "lucide-react"
import { quotePdf } from "@/lib/pdf"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent } from "@/components/ui/card"
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
import type { Quote, QuoteTotals, Client } from "@/lib/types"
import { gbp, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Data = { quotes: Quote[]; totals: QuoteTotals }

const STATUS_VARIANT: Record<Quote["status"], "default" | "secondary" | "outline" | "destructive"> = {
  draft: "outline",
  sent: "secondary",
  accepted: "default",
  declined: "destructive",
}

const DIVISIONS = [
  { value: "care", label: "Healthcare" },
  { value: "academy", label: "Training" },
  { value: "cleaning", label: "Cleaning" },
  { value: "security", label: "Security" },
]

export default function Quotes() {
  const { data, loading, error, reload } = useApi<Data>("quotes.php")
  const [open, setOpen] = useState(false)

  async function setStatus(id: number, status: "sent" | "declined") {
    try {
      await api(`quotes.php?action=status&id=${id}`, { method: "POST", body: { status } })
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update the quote.")
    }
  }

  async function accept(id: number) {
    try {
      const res = await api<{ invoice_number: string }>(`quotes.php?action=accept&id=${id}`, {
        method: "POST",
        body: {},
      })
      toast.success(`Accepted. Invoice ${res.invoice_number} raised.`)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not accept the quote.")
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Quoting & estimating</h1>
          <p className="text-sm text-muted-foreground">
            Build quotes with VAT. Accept one and it becomes an invoice automatically.
          </p>
        </div>
        <NewQuoteDialog open={open} onOpenChange={setOpen} onCreated={reload} />
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-2">
            <Card>
              <CardContent className="flex items-center justify-between pt-6">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Open pipeline</p>
                  <p className="mt-1 text-2xl font-bold">{gbp(data.totals.open_pence)}</p>
                </div>
                <FileText className="size-7 text-muted-foreground" />
              </CardContent>
            </Card>
            <Card>
              <CardContent className="flex items-center justify-between pt-6">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Won</p>
                  <p className="mt-1 text-2xl font-bold">{gbp(data.totals.won_pence)}</p>
                </div>
                <CheckCircle2 className="size-7 text-primary" />
              </CardContent>
            </Card>
          </div>

          {data.quotes.length === 0 ? (
            <EmptyBlock
              title="No quotes yet"
              hint="Build your first quote. Accepting it raises an invoice."
              action={<Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> New quote</Button>}
            />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Number</TableHead>
                    <TableHead>Title</TableHead>
                    <TableHead>Client</TableHead>
                    <TableHead>Division</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.quotes.map((q) => (
                    <TableRow key={q.id}>
                      <TableCell className="font-medium">{q.number}</TableCell>
                      <TableCell>{q.title}</TableCell>
                      <TableCell className="text-muted-foreground">{q.client || "—"}</TableCell>
                      <TableCell className="text-muted-foreground">{DIVISION_LABELS[q.division] ?? q.division}</TableCell>
                      <TableCell className="text-right font-medium">{gbp(q.total_pence)}</TableCell>
                      <TableCell><Badge variant={STATUS_VARIANT[q.status]} className="capitalize">{q.status}</Badge></TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-2">
                          <Button size="sm" variant="ghost" onClick={() => quotePdf(q)} aria-label="Download PDF">
                            <FileDown className="size-4" />
                          </Button>
                          {q.status === "draft" && (
                            <Button size="sm" variant="ghost" onClick={() => setStatus(q.id, "sent")}>Mark sent</Button>
                          )}
                          {(q.status === "draft" || q.status === "sent") && (
                            <>
                              <Button size="sm" variant="outline" onClick={() => accept(q.id)}>Accept → invoice</Button>
                              <Button size="sm" variant="ghost" onClick={() => setStatus(q.id, "declined")}>Decline</Button>
                            </>
                          )}
                        </div>
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

function NewQuoteDialog({
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
  const [clientId, setClientId] = useState("")
  const [net, setNet] = useState("")
  const [submitting, setSubmitting] = useState(false)

  const netNum = parseFloat(net) || 0
  const vat = netNum * 0.2
  const total = netNum + vat

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const title = String(form.get("title") || "").trim()
    if (!title) {
      toast.error("Add a quote title.")
      return
    }
    if (netNum <= 0) {
      toast.error("Enter a net amount.")
      return
    }
    setSubmitting(true)
    try {
      await api("quotes.php", {
        method: "POST",
        body: { title, division, client_id: clientId || null, net_pence: Math.round(netNum * 100) },
      })
      toast.success("Quote created.")
      onOpenChange(false)
      setNet("")
      setClientId("")
      onCreated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not create the quote.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> New quote</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>New quote</DialogTitle>
          <DialogDescription>VAT is added at the UK standard rate (20%).</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="title">Title</Label>
            <Input id="title" name="title" placeholder="e.g. Weekly office clean — 6 months" required />
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
          <div className="grid gap-2">
            <Label htmlFor="net">Net amount (£)</Label>
            <Input id="net" type="number" min="0" step="0.01" value={net} onChange={(e) => setNet(e.target.value)} placeholder="0.00" required />
          </div>
          <div className="rounded-md bg-muted p-3 text-sm">
            <div className="flex justify-between"><span className="text-muted-foreground">Net</span><span>£{netNum.toFixed(2)}</span></div>
            <div className="flex justify-between"><span className="text-muted-foreground">VAT (20%)</span><span>£{vat.toFixed(2)}</span></div>
            <div className="mt-1 flex justify-between border-t border-border pt-1 font-medium"><span>Total</span><span>£{total.toFixed(2)}</span></div>
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Create quote"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
