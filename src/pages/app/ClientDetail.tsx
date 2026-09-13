import { useEffect, useState } from "react"
import { Link, useParams, useSearchParams } from "react-router-dom"
import { ArrowLeft, Building2, Mail, Phone, Briefcase, ReceiptText, FileText, Inbox, Pencil, Plus } from "lucide-react"
import { toast } from "sonner"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
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
import type { Client, Job, Quote, Invoice, Enquiry, RateCard, ClientFinancials, AdminUser } from "@/lib/types"
import { shortDate, gbp, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Site = { id: number; name: string; address: string | null }
type Detail = { client: Client; sites: Site[]; rate_cards: RateCard[]; financials: ClientFinancials }

type Event = {
  date: string
  kind: "job" | "quote" | "invoice" | "enquiry"
  title: string
  sub: string
  amount?: number
  status?: string
}

const EVENT_ICON = {
  job: Briefcase,
  quote: FileText,
  invoice: ReceiptText,
  enquiry: Inbox,
} as const

// Activity timeline assembled from the module endpoints filtered to this client.
function ClientTimeline({ clientId, email }: { clientId: number; email: string | null }) {
  // Filtered by the server. This fetched every work order in the business and
  // filtered in the browser, which quietly computed against a truncated set once
  // the list passed the page size.
  const jobs = useApi<{ jobs: Job[] }>(`jobs.php?client_id=${clientId}`, [clientId])
  const quotes = useApi<{ quotes: Quote[] }>("quotes.php")
  const invoices = useApi<{ invoices: Invoice[] }>("invoices.php")
  const enquiries = useApi<{ enquiries: Enquiry[] }>("enquiries.php")

  const events: Event[] = [
    ...(jobs.data?.jobs ?? [])
      .filter((j) => j.client_id === clientId)
      .map((j) => ({ date: j.scheduled_for || j.created_at, kind: "job" as const, title: j.title, sub: DIVISION_LABELS[j.division] ?? j.division, status: j.status })),
    ...(quotes.data?.quotes ?? [])
      .filter((q) => q.client_id === clientId)
      .map((q) => ({ date: q.created_at, kind: "quote" as const, title: `${q.number} — ${q.title}`, sub: DIVISION_LABELS[q.division] ?? q.division, amount: q.total_pence, status: q.status })),
    ...(invoices.data?.invoices ?? [])
      .filter((i) => i.client_id === clientId)
      .map((i) => ({ date: i.created_at, kind: "invoice" as const, title: i.number, sub: DIVISION_LABELS[i.division] ?? i.division, amount: i.total_pence, status: i.status })),
    ...(enquiries.data?.enquiries ?? [])
      .filter((e) => email != null && e.email.toLowerCase() === email.toLowerCase())
      .map((e) => ({ date: e.created_at, kind: "enquiry" as const, title: "Website enquiry", sub: DIVISION_LABELS[e.division] ?? e.division, status: e.status })),
  ].sort((a, b) => (a.date < b.date ? 1 : -1))

  return (
    <Card>
      <CardHeader><CardTitle className="text-base">Activity</CardTitle></CardHeader>
      <CardContent>
        {events.length === 0 ? (
          <EmptyBlock title="No activity yet" hint="Jobs, quotes, invoices and enquiries for this client will appear here." />
        ) : (
          <ul className="space-y-4">
            {events.map((e, i) => {
              const Icon = EVENT_ICON[e.kind]
              return (
                <li key={i} className="flex gap-3">
                  <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <Icon className="size-4" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-baseline justify-between gap-2">
                      <p className="truncate font-medium">{e.title}</p>
                      <span className="shrink-0 text-xs text-muted-foreground">{shortDate(e.date)}</span>
                    </div>
                    <div className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                      <span className="capitalize">{e.kind}</span> · <span>{e.sub}</span>
                      {e.amount != null && <span className="font-medium text-foreground">{gbp(e.amount)}</span>}
                      {e.status && <Badge variant="secondary" className="capitalize">{e.status}</Badge>}
                    </div>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

export default function ClientDetail() {
  const { id } = useParams()
  const { data, loading, error, reload } = useApi<Detail>(
    id ? `clients.php?id=${id}` : null,
    [id],
  )
  const [editing, setEditing] = useState(false)

  return (
    <div className="space-y-6">
      <Link to="/app/clients" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" /> All clients
      </Link>

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h1 className="text-2xl font-bold tracking-tight">{data.client.name}</h1>
              <p className="text-sm capitalize text-muted-foreground">
                {data.client.type.replace("_", " ")}
              </p>
            </div>
            <div className="flex items-center gap-2">
              {data.client.stage && <Badge variant="secondary" className="capitalize">{data.client.stage}</Badge>}
              <Badge className="capitalize">{data.client.status}</Badge>
              <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                <Pencil className="mr-1 size-4" /> Edit
              </Button>
            </div>
          </div>

          <div className="grid gap-4 md:grid-cols-3">
            <Card className="md:col-span-1">
              <CardHeader><CardTitle className="text-base">Contact</CardTitle></CardHeader>
              <CardContent className="space-y-3 text-sm">
                <p className="text-muted-foreground">{data.client.contact_name || "No named contact"}</p>
                {data.client.email && (
                  <p className="flex items-center gap-2"><Mail className="size-4 text-primary" /> {data.client.email}</p>
                )}
                {data.client.phone && (
                  <p className="flex items-center gap-2"><Phone className="size-4 text-primary" /> {data.client.phone}</p>
                )}
                <p className="pt-2 text-xs text-muted-foreground">Client since {shortDate(data.client.created_at)}</p>
              </CardContent>
            </Card>

            <Card className="md:col-span-1">
              <CardHeader><CardTitle className="text-base">Divisions used</CardTitle></CardHeader>
              <CardContent className="flex flex-wrap gap-2">
                {data.client.divisions
                  ? data.client.divisions.split(",").filter(Boolean).map((d) => (
                      <Badge key={d} variant="secondary">{DIVISION_LABELS[d] ?? d}</Badge>
                    ))
                  : <span className="text-sm text-muted-foreground">None assigned yet.</span>}
              </CardContent>
            </Card>

            <Card className="md:col-span-1">
              <CardHeader><CardTitle className="text-base">Sites</CardTitle></CardHeader>
              <CardContent>
                {data.sites.length === 0 ? (
                  <EmptyBlock title="No sites yet" hint="Add the buildings or homes this client covers." />
                ) : (
                  <ul className="space-y-2 text-sm">
                    {data.sites.map((s) => (
                      <li key={s.id} className="flex items-start gap-2">
                        <Building2 className="mt-0.5 size-4 text-primary" />
                        <div>
                          <p className="font-medium">{s.name}</p>
                          {s.address && <p className="text-muted-foreground">{s.address}</p>}
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <Card>
              <CardHeader><CardTitle className="text-base">Financials</CardTitle></CardHeader>
              <CardContent className="grid grid-cols-2 gap-4 text-sm">
                <Fin label="Quoted (open)" value={gbp(data.financials.quoted_pence)} />
                <Fin label="Invoiced (net)" value={gbp(data.financials.invoiced_pence)} />
                <Fin label="Paid" value={gbp(data.financials.paid_pence)} tone="primary" />
                <Fin label="Outstanding" value={gbp(data.financials.outstanding_pence)} tone={data.financials.outstanding_pence > 0 ? "red" : undefined} />
                <div className="col-span-2 border-t pt-2 text-xs text-muted-foreground">
                  Account manager: {data.client.account_manager || "Unassigned"}
                  {data.client.sla_response_hours ? ` · SLA ${data.client.sla_response_hours}h response` : ""}
                </div>
              </CardContent>
            </Card>

            <RateCardsCard clientId={data.client.id} cards={data.rate_cards} onChanged={reload} />
          </div>

          <DirectDebitCard clientId={data.client.id} />

          <ClientTimeline clientId={data.client.id} email={data.client.email} />

          <EditClientDialog
            open={editing}
            onOpenChange={setEditing}
            client={data.client}
            onSaved={reload}
          />
        </>
      )}
    </div>
  )
}

function DirectDebitCard({ clientId }: { clientId: number }) {
  const [params, setParams] = useSearchParams()
  const { data, reload } = useApi<{ mandate: { id: number; status: string; gc_mandate_id: string | null } | null }>(
    `gocardless.php?action=status&client_id=${clientId}`,
    [clientId],
  )
  const [busy, setBusy] = useState(false)
  const [unavailable, setUnavailable] = useState(false)

  // Returning from the GoCardless hosted flow → finalise the mandate.
  useEffect(() => {
    if (params.get("dd") === "done" && params.get("m")) {
      const m = params.get("m")
      api("gocardless.php?action=complete", { method: "POST", body: { mandate: Number(m) } })
        .then(() => { toast.success("Direct Debit mandate set up."); reload() })
        .catch(() => {})
        .finally(() => { params.delete("dd"); params.delete("m"); setParams(params, { replace: true }) })
    } else if (params.get("dd") === "cancelled") {
      toast("Direct Debit setup cancelled.")
      params.delete("dd"); setParams(params, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function setup() {
    setBusy(true)
    try {
      const res = await api<{ url: string }>("gocardless.php?action=setup", { method: "POST", body: { client_id: clientId } })
      window.location.href = res.url
    } catch (err) {
      if (err instanceof ApiError && err.status === 503) setUnavailable(true)
      else toast.error(err instanceof ApiError ? err.message : "Could not start setup.")
      setBusy(false)
    }
  }

  const m = data?.mandate
  const active = m?.status === "active"

  return (
    <Card>
      <CardHeader><CardTitle className="text-base">Direct Debit</CardTitle></CardHeader>
      <CardContent className="flex flex-wrap items-center justify-between gap-3">
        <div className="text-sm">
          {unavailable ? (
            <span className="text-muted-foreground">Direct Debit isn't configured yet (add GoCardless keys).</span>
          ) : active ? (
            <span className="inline-flex items-center gap-2"><Badge variant="default">Active</Badge> Recurring collection enabled — charge invoices by DD.</span>
          ) : m ? (
            <span className="inline-flex items-center gap-2"><Badge variant="secondary" className="capitalize">{m.status}</Badge> Setup not finished.</span>
          ) : (
            <span className="text-muted-foreground">No mandate yet. Set one up to collect by Direct Debit.</span>
          )}
        </div>
        {!unavailable && !active && (
          <Button size="sm" onClick={setup} disabled={busy}>{busy ? "Starting…" : m ? "Resume setup" : "Set up Direct Debit"}</Button>
        )}
      </CardContent>
    </Card>
  )
}

function Fin({ label, value, tone }: { label: string; value: string; tone?: "primary" | "red" }) {
  const color = tone === "red" ? "text-destructive" : tone === "primary" ? "text-primary" : ""
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={`mt-0.5 text-lg font-bold ${color}`}>{value}</p>
    </div>
  )
}

const DIVISIONS = [
  { value: "care", label: "Care" },
  { value: "academy", label: "Academy" },
  { value: "cleaning", label: "Cleaning" },
  { value: "security", label: "Security" },
]

function RateCardsCard({ clientId, cards, onChanged }: { clientId: number; cards: RateCard[]; onChanged: () => void }) {
  const [division, setDivision] = useState("care")
  const [role, setRole] = useState("")
  const [charge, setCharge] = useState("")
  const [busy, setBusy] = useState(false)

  async function add() {
    if (!charge || Number(charge) <= 0) {
      toast.error("Enter a charge rate.")
      return
    }
    setBusy(true)
    try {
      await api(`clients.php?action=rate_card&id=${clientId}`, {
        method: "POST",
        body: { division, role: role.trim() || "standard", charge_pence: Math.round(Number(charge) * 100) },
      })
      toast.success("Rate card added.")
      setRole(""); setCharge("")
      onChanged()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not add rate.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader><CardTitle className="text-base">Rate cards</CardTitle></CardHeader>
      <CardContent className="space-y-3">
        {cards.length === 0 ? (
          <p className="text-sm text-muted-foreground">No agreed rates yet.</p>
        ) : (
          <ul className="space-y-1 text-sm">
            {cards.map((rc) => (
              <li key={rc.id} className="flex items-center justify-between border-b py-1 last:border-0">
                <span>{DIVISION_LABELS[rc.division] ?? rc.division} · <span className="text-muted-foreground">{rc.role}</span></span>
                <span className="font-medium">{gbp(rc.charge_pence)}/hr</span>
              </li>
            ))}
          </ul>
        )}
        <div className="grid grid-cols-3 gap-2 border-t pt-3">
          <Select value={division} onValueChange={setDivision}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>{DIVISIONS.map((d) => <SelectItem key={d.value} value={d.value}>{d.label}</SelectItem>)}</SelectContent>
          </Select>
          <Input placeholder="Role" value={role} onChange={(e) => setRole(e.target.value)} />
          <Input type="number" min="0" step="0.01" placeholder="£/hr" value={charge} onChange={(e) => setCharge(e.target.value)} />
        </div>
        <Button size="sm" variant="outline" onClick={add} disabled={busy} className="w-full">
          <Plus className="mr-1 size-4" /> Add rate
        </Button>
      </CardContent>
    </Card>
  )
}

const STAGES = ["lead", "qualified", "proposal", "won", "lost"]
const STATUSES = ["prospect", "active", "paused"]

function EditClientDialog({
  open,
  onOpenChange,
  client,
  onSaved,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  client: Client
  onSaved: () => void
}) {
  const { data: usersData } = useApi<{ users: AdminUser[] }>(open ? "users.php" : null, [open])
  const [stage, setStage] = useState(client.stage ?? "lead")
  const [status, setStatus] = useState(client.status)
  const [amId, setAmId] = useState(client.account_manager_id ? String(client.account_manager_id) : "none")
  const [sla, setSla] = useState(client.sla_response_hours ? String(client.sla_response_hours) : "")
  const [busy, setBusy] = useState(false)

  async function save() {
    setBusy(true)
    try {
      await api(`clients.php?action=update&id=${client.id}`, {
        method: "POST",
        body: {
          stage, status,
          account_manager_id: amId === "none" ? "" : amId,
          sla_response_hours: sla === "" ? "" : Number(sla),
        },
      })
      toast.success("Client updated.")
      onOpenChange(false)
      onSaved()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update client.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit {client.name}</DialogTitle>
          <DialogDescription>Pipeline stage, account manager and SLA.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4">
          <div className="grid grid-cols-2 gap-4">
            <div className="grid gap-2">
              <Label>Pipeline stage</Label>
              <Select value={stage} onValueChange={(v) => setStage(v as Client["stage"] & string)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>{STAGES.map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}</SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label>Status</Label>
              <Select value={status} onValueChange={setStatus}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>{STATUSES.map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}</SelectContent>
              </Select>
            </div>
          </div>
          <div className="grid gap-2">
            <Label>Account manager</Label>
            <Select value={amId} onValueChange={setAmId}>
              <SelectTrigger><SelectValue placeholder="Unassigned" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="none">Unassigned</SelectItem>
                {(usersData?.users ?? []).map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="sla">SLA response (hours)</Label>
            <Input id="sla" type="number" min="0" value={sla} onChange={(e) => setSla(e.target.value)} placeholder="e.g. 4" />
          </div>
        </div>
        <DialogFooter>
          <Button onClick={save} disabled={busy}>{busy ? "Saving…" : "Save"}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
