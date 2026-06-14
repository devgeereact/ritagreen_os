import { useEffect, useState } from "react"
import { Plus, Banknote, CheckCircle2, CreditCard, Loader2, FileDown, Download, Undo2 } from "lucide-react"
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import type { Invoice, InvoiceTotals, AgedDebt, MarginData, Client } from "@/lib/types"
import { gbp, shortDate, DIVISION_LABELS } from "@/lib/format"
import { exportCsv } from "@/lib/csv"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Data = { invoices: Invoice[]; totals: InvoiceTotals; aged: AgedDebt }

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

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Invoicing & payments</h1>
        <p className="text-sm text-muted-foreground">
          Raise invoices with UK VAT, chase what's owed and see the margin on every division.
        </p>
      </div>

      <Tabs defaultValue="invoices">
        <TabsList>
          <TabsTrigger value="invoices">Invoices</TabsTrigger>
          <TabsTrigger value="aged">Aged debt</TabsTrigger>
          <TabsTrigger value="margin">Margin</TabsTrigger>
        </TabsList>

        <TabsContent value="invoices" className="mt-6">
          <InvoicesTab data={data} loading={loading} error={error} reload={reload} />
        </TabsContent>
        <TabsContent value="aged" className="mt-6">
          <AgedDebtTab data={data} loading={loading} error={error} reload={reload} />
        </TabsContent>
        <TabsContent value="margin" className="mt-6">
          <MarginTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}

/* -------------------------------- Invoices -------------------------------- */

function InvoicesTab({
  data,
  loading,
  error,
  reload,
}: {
  data: Data | null
  loading: boolean
  error: string | null
  reload: () => void
}) {
  const [open, setOpen] = useState(false)
  const [paying, setPaying] = useState<number | null>(null)
  const [creditFor, setCreditFor] = useState<Invoice | null>(null)

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

  function exportInvoices() {
    if (!data || data.invoices.length === 0) return
    exportCsv(
      "ritagreen-invoices.csv",
      data.invoices.map((i) => ({
        number: i.number,
        client: i.client ?? "",
        division: DIVISION_LABELS[i.division] ?? i.division,
        net: (i.net_pence / 100).toFixed(2),
        vat: (i.vat_pence / 100).toFixed(2),
        total: (i.total_pence / 100).toFixed(2),
        credited: ((i.credited_pence ?? 0) / 100).toFixed(2),
        status: i.status,
        raised: i.created_at?.slice(0, 10) ?? "",
        due: i.due_date ?? "",
      })),
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-end gap-2">
        {data && data.invoices.length > 0 && (
          <Button variant="outline" onClick={exportInvoices}>
            <Download className="mr-1 size-4" /> Export CSV
          </Button>
        )}
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
                  <p className="mt-1 text-2xl font-bold tabular-nums">{gbp(data.totals.outstanding_pence)}</p>
                </div>
                <Banknote className="size-7 text-destructive" />
              </CardContent>
            </Card>
            <Card>
              <CardContent className="flex items-center justify-between pt-6">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Paid</p>
                  <p className="mt-1 text-2xl font-bold tabular-nums">{gbp(data.totals.paid_pence)}</p>
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
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Due</TableHead>
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
                      <TableCell className="text-right font-medium">
                        {gbp(inv.total_pence)}
                        {(inv.credited_pence ?? 0) > 0 && (
                          <div className="text-xs text-muted-foreground">−{gbp(inv.credited_pence ?? 0)} credited</div>
                        )}
                      </TableCell>
                      <TableCell><Badge variant={STATUS_VARIANT[inv.status]} className="capitalize">{inv.status}</Badge></TableCell>
                      <TableCell className="text-muted-foreground">{shortDate(inv.due_date ?? null)}</TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-1">
                          <Button size="sm" variant="ghost" onClick={() => invoicePdf(inv)} aria-label="Download PDF">
                            <FileDown className="size-4" />
                          </Button>
                          {inv.status !== "paid" && inv.status !== "void" && (
                            <>
                              <Button size="sm" variant="ghost" onClick={() => setCreditFor(inv)} aria-label="Credit note">
                                <Undo2 className="size-4" />
                              </Button>
                              <Button size="sm" onClick={() => payByCard(inv.id)} disabled={paying === inv.id}>
                                {paying === inv.id ? <Loader2 className="mr-1 size-4 animate-spin" /> : <CreditCard className="mr-1 size-4" />}
                                Pay
                              </Button>
                              <Button size="sm" variant="outline" onClick={() => markPaid(inv.id)}>Mark paid</Button>
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

      <CreditNoteDialog invoice={creditFor} onOpenChange={(o) => !o && setCreditFor(null)} onDone={reload} />
    </div>
  )
}

function CreditNoteDialog({
  invoice,
  onOpenChange,
  onDone,
}: {
  invoice: Invoice | null
  onOpenChange: (o: boolean) => void
  onDone: () => void
}) {
  const [amount, setAmount] = useState("")
  const [reason, setReason] = useState("")
  const [submitting, setSubmitting] = useState(false)
  const remaining = invoice ? (invoice.total_pence - (invoice.credited_pence ?? 0)) / 100 : 0

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    if (!invoice) return
    const amt = Number(amount || 0)
    if (amt <= 0) {
      toast.error("Enter a credit amount.")
      return
    }
    setSubmitting(true)
    try {
      await api("credit-notes.php", {
        method: "POST",
        body: { invoice_id: invoice.id, amount_pence: Math.round(amt * 100), reason },
      })
      toast.success("Credit note raised.")
      onOpenChange(false)
      setAmount(""); setReason("")
      onDone()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not raise the credit note.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={!!invoice} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Credit note — {invoice?.number}</DialogTitle>
          <DialogDescription>
            Credit some or all of this invoice. Remaining balance: £{remaining.toFixed(2)}. A full credit voids the invoice.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="cn-amount">Amount (£)</Label>
            <Input id="cn-amount" type="number" min="0" step="0.01" max={remaining} value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={remaining.toFixed(2)} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="cn-reason">Reason</Label>
            <Input id="cn-reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Service not delivered" />
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>{submitting ? "Saving…" : "Raise credit note"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/* -------------------------------- Aged debt ------------------------------- */

const BUCKET_LABELS: { key: keyof AgedDebt["buckets"]; label: string }[] = [
  { key: "current", label: "Not yet due" },
  { key: "d1_30", label: "1–30 days" },
  { key: "d31_60", label: "31–60 days" },
  { key: "d61_90", label: "61–90 days" },
  { key: "d90_plus", label: "90+ days" },
]

function AgedDebtTab({
  data,
  loading,
  error,
  reload,
}: {
  data: Data | null
  loading: boolean
  error: string | null
  reload: () => void
}) {
  if (loading) return <LoadingBlock rows={5} />
  if (error) return <ErrorBlock message={error} onRetry={reload} />
  if (!data) return null

  const { buckets, items } = data.aged

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
        {BUCKET_LABELS.map((b) => {
          const overdue = b.key !== "current"
          return (
            <Card key={b.key}>
              <CardContent className="pt-6">
                <p className="text-xs font-medium text-muted-foreground">{b.label}</p>
                <p className={`mt-1 text-xl font-bold ${overdue && buckets[b.key] > 0 ? "text-destructive" : ""}`}>
                  {gbp(buckets[b.key])}
                </p>
              </CardContent>
            </Card>
          )
        })}
      </div>

      {items.length === 0 ? (
        <EmptyBlock title="Nothing outstanding" hint="Every sent invoice is paid or credited. Good chasing." />
      ) : (
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Invoice</TableHead>
                <TableHead>Client</TableHead>
                <TableHead className="text-right">Balance</TableHead>
                <TableHead className="text-right">Days overdue</TableHead>
                <TableHead>Bucket</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {items.map((it) => (
                <TableRow key={it.id}>
                  <TableCell className="font-medium">{it.number}</TableCell>
                  <TableCell className="text-muted-foreground">{it.client || "—"}</TableCell>
                  <TableCell className="text-right">{gbp(it.balance_pence)}</TableCell>
                  <TableCell className="text-right">
                    {it.days_overdue > 0 ? <span className="text-destructive">{it.days_overdue}</span> : "—"}
                  </TableCell>
                  <TableCell>{BUCKET_LABELS.find((b) => b.key === it.bucket)?.label ?? it.bucket}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}
    </div>
  )
}

/* --------------------------------- Margin --------------------------------- */

function monthRange(): { from: string; to: string } {
  const now = new Date()
  const first = new Date(now.getFullYear(), now.getMonth(), 1)
  const last = new Date(now.getFullYear(), now.getMonth() + 1, 0)
  const iso = (d: Date) => d.toISOString().slice(0, 10)
  return { from: iso(first), to: iso(last) }
}

function MarginTab() {
  const init = monthRange()
  const [from, setFrom] = useState(init.from)
  const [to, setTo] = useState(init.to)
  const { data, loading, error, reload } = useApi<MarginData>(`margin.php?from=${from}&to=${to}`, [from, to])

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-end gap-2">
        <div className="grid gap-1.5">
          <Label htmlFor="m-from" className="text-xs text-muted-foreground">From</Label>
          <Input id="m-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-40" />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="m-to" className="text-xs text-muted-foreground">To</Label>
          <Input id="m-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-40" />
        </div>
      </div>

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <Card><CardContent className="pt-6"><p className="text-xs text-muted-foreground">Revenue (net)</p><p className="mt-1 text-2xl font-bold tabular-nums">{gbp(data.totals.revenue_pence)}</p></CardContent></Card>
            <Card><CardContent className="pt-6"><p className="text-xs text-muted-foreground">Labour cost</p><p className="mt-1 text-2xl font-bold tabular-nums">{gbp(data.totals.cost_pence)}</p></CardContent></Card>
            <Card><CardContent className="pt-6"><p className="text-xs text-muted-foreground">Margin</p><p className={`mt-1 text-2xl font-bold tabular-nums ${data.totals.margin_pence < 0 ? "text-destructive" : "text-primary"}`}>{gbp(data.totals.margin_pence)}{data.totals.margin_pct !== null ? ` · ${data.totals.margin_pct}%` : ""}</p></CardContent></Card>
          </div>

          <Card>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Division</TableHead>
                  <TableHead className="text-right">Revenue (net)</TableHead>
                  <TableHead className="text-right">Labour cost</TableHead>
                  <TableHead className="text-right">Margin</TableHead>
                  <TableHead className="text-right">Margin %</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.rows.map((r) => (
                  <TableRow key={r.division}>
                    <TableCell className="font-medium">{DIVISION_LABELS[r.division] ?? r.division}</TableCell>
                    <TableCell className="text-right">{gbp(r.revenue_pence)}</TableCell>
                    <TableCell className="text-right text-muted-foreground">{gbp(r.cost_pence)}</TableCell>
                    <TableCell className={`text-right font-medium ${r.margin_pence < 0 ? "text-destructive" : ""}`}>{gbp(r.margin_pence)}</TableCell>
                    <TableCell className="text-right">{r.margin_pct !== null ? `${r.margin_pct}%` : "—"}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
          <p className="text-xs text-muted-foreground">
            Revenue = net invoiced in the period (less credits). Labour cost = approved-timesheet gross including night/weekend/bank-holiday and overtime uplifts.
          </p>
        </>
      )}
    </div>
  )
}

/* ------------------------------ New invoice ------------------------------- */

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
