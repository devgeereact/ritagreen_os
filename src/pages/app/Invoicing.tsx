import { useEffect, useState } from "react"
import { Plus, Banknote, CheckCircle2, CreditCard, Loader2, FileDown } from "lucide-react"
import { invoicePdf } from "@/lib/pdf"
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
import type { Invoice, InvoiceTotals, Client } from "@/lib/types"
import { gbp, shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Data = { invoices: Invoice[]; totals: InvoiceTotals }

const STATUS_VARIANT: Record<Invoice["status"], "default" | "secondary" | "destructive" | "outline"> = {
  draft: "outline",
  sent: "secondary",
  paid: "default",
  overdue: "destructive",
  void: "outline",
}

const DIVISIONS = [
  { value: "care", label: "Care" },
  { value: "academy", label: "Academy" },
  { value: "cleaning", label: "Cleaning" },
  { value: "security", label: "Security" },
]

export default function Invoicing() {
  const { data, loading, error, reload } = useApi<Data>("invoices.php")
  const [open, setOpen] = useState(false)
  const [paying, setPaying] = useState<number | null>(null)

  // Returning from Stripe Checkout. The webhook is the source of truth for
  // "paid"; this just confirms to the user and refreshes the list.
  useEffect(() => {
    const p = new URLSearchParams(window.location.search)
    if (p.get("paid")) {
      toast.success("Payment received — invoice marked paid.")
      reload()
      window.history.replaceState({}, "", "/app/invoicing")
    } else if (p.get("cancelled")) {
      toast("Card payment cancelled.")
      window.history.replaceState({}, "", "/app/invoicing")
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function markPaid(id: number) {
    try {
      await api(`invoices.php?action=pay&id=${id}`, { method: "POST", body: {} })
      toast.success("Invoice marked as paid.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update the invoice.")
    }
  }

  async function payByCard(id: number) {
    setPaying(id)
    try {
      const res = await api<{ url: string }>("payments.php?action=checkout", {
        method: "POST",
        body: { invoice_id: id },
      })
      window.location.href = res.url
    } catch (err) {
      setPaying(null)
      toast.error(err instanceof ApiError ? err.message : "Could not start the card payment.")
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Invoicing & payments</h1>
          <p className="text-sm text-muted-foreground">
            Raise invoices with UK VAT and reconcile payments across the group.
          </p>
        </div>
        <NewInvoiceDialog open={open} onOpenChange={setOpen} onCreated={reload} />
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-2">
            <Card>
              <CardContent className="flex items-center justify-between pt-6">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Outstanding</p>
                  <p className="mt-1 text-2xl font-bold">{gbp(data.totals.outstanding_pence)}</p>
                </div>
                <Banknote className="size-7 text-destructive" />
              </CardContent>
            </Card>
            <Card>
              <CardContent className="flex items-center justify-between pt-6">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Paid</p>
                  <p className="mt-1 text-2xl font-bold">{gbp(data.totals.paid_pence)}</p>
                </div>
                <CheckCircle2 className="size-7 text-primary" />
              </CardContent>
            </Card>
          </div>

          {data.invoices.length === 0 ? (
            <EmptyBlock
              title="No invoices yet"
              hint="Raise your first invoice. VAT is added at the UK standard rate."
              action={<Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> New invoice</Button>}
            />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Number</TableHead>
                    <TableHead>Client</TableHead>
                    <TableHead>Division</TableHead>
                    <TableHead className="text-right">Net</TableHead>
                    <TableHead className="text-right">VAT</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Raised</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.invoices.map((inv) => (
                    <TableRow key={inv.id}>
                      <TableCell className="font-medium">{inv.number}</TableCell>
                      <TableCell className="text-muted-foreground">{inv.client || "—"}</TableCell>
                      <TableCell className="text-muted-foreground">{DIVISION_LABELS[inv.division] ?? inv.division}</TableCell>
                      <TableCell className="text-right">{gbp(inv.net_pence)}</TableCell>
                      <TableCell className="text-right text-muted-foreground">{gbp(inv.vat_pence)}</TableCell>
                      <TableCell className="text-right font-medium">{gbp(inv.total_pence)}</TableCell>
                      <TableCell><Badge variant={STATUS_VARIANT[inv.status]} className="capitalize">{inv.status}</Badge></TableCell>
                      <TableCell className="text-muted-foreground">{shortDate(inv.created_at)}</TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-2">
                          <Button size="sm" variant="ghost" onClick={() => invoicePdf(inv)} aria-label="Download PDF">
                            <FileDown className="size-4" />
                          </Button>
                          {inv.status !== "paid" && (
                            <>
                              <Button size="sm" onClick={() => payByCard(inv.id)} disabled={paying === inv.id}>
                                {paying === inv.id ? (
                                  <Loader2 className="mr-1 size-4 animate-spin" />
                                ) : (
                                  <CreditCard className="mr-1 size-4" />
                                )}
                                Pay by card
                              </Button>
                              <Button size="sm" variant="outline" onClick={() => markPaid(inv.id)}>
                                Mark paid
                              </Button>
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

function NewInvoiceDialog({
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
    if (netNum <= 0) {
      toast.error("Enter a net amount.")
      return
    }
    setSubmitting(true)
    try {
      await api("invoices.php", {
        method: "POST",
        body: { division, client_id: clientId || null, net_pence: Math.round(netNum * 100) },
      })
      toast.success("Invoice raised.")
      onOpenChange(false)
      setNet("")
      setClientId("")
      onCreated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not raise the invoice.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> New invoice</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Raise an invoice</DialogTitle>
          <DialogDescription>VAT is added at the UK standard rate (20%).</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
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
            <Input
              id="net"
              type="number"
              min="0"
              step="0.01"
              value={net}
              onChange={(e) => setNet(e.target.value)}
              placeholder="0.00"
              required
            />
          </div>
          <div className="rounded-md bg-muted p-3 text-sm">
            <div className="flex justify-between"><span className="text-muted-foreground">Net</span><span>£{netNum.toFixed(2)}</span></div>
            <div className="flex justify-between"><span className="text-muted-foreground">VAT (20%)</span><span>£{vat.toFixed(2)}</span></div>
            <div className="mt-1 flex justify-between border-t border-border pt-1 font-medium"><span>Total</span><span>£{total.toFixed(2)}</span></div>
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Raising…" : "Raise invoice"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
