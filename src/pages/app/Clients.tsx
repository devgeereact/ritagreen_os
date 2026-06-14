import { useState } from "react"
import { Link } from "react-router-dom"
import { Plus, Search, Download } from "lucide-react"
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
import type { Client } from "@/lib/types"
import { shortDate } from "@/lib/format"
import { exportCsv } from "@/lib/csv"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const CLIENT_TYPES = [
  { value: "care_home", label: "Care home" },
  { value: "nhs", label: "NHS" },
  { value: "landlord", label: "Landlord / agent" },
  { value: "event", label: "Event organiser" },
  { value: "private", label: "Private" },
]

const STATUS_VARIANT: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  active: "default",
  prospect: "secondary",
  paused: "outline",
}

const STAGE_VARIANT: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  lead: "outline",
  qualified: "secondary",
  proposal: "secondary",
  won: "default",
  lost: "destructive",
}

type ClientKpi = {
  name: string
  type: string
  stage: string
  account_manager: string | null
  invoiced_pence: number
  paid_pence: number
  outstanding_pence: number
  jobs: number
}

export default function Clients() {
  const { data, loading, error, reload } = useApi<{ clients: Client[] }>("clients.php")
  const [q, setQ] = useState("")
  const [open, setOpen] = useState(false)

  const clients = data?.clients ?? []
  const filtered = clients.filter((c) =>
    [c.name, c.contact_name, c.email].some((f) => f?.toLowerCase().includes(q.toLowerCase())),
  )

  function exportClients() {
    if (clients.length === 0) return
    exportCsv(
      "ritagreen-clients.csv",
      clients.map((c) => ({
        name: c.name,
        type: c.type,
        stage: c.stage ?? "",
        status: c.status,
        account_manager: c.account_manager ?? "",
        contact: c.contact_name ?? "",
        email: c.email ?? "",
        phone: c.phone ?? "",
        sites: c.sites_count ?? 0,
      })),
    )
  }

  async function exportKpi() {
    try {
      const r = await api<{ clients: ClientKpi[] }>("reports.php?action=client_kpi")
      if (r.clients.length === 0) {
        toast("No client data to export.")
        return
      }
      exportCsv(
        "ritagreen-client-kpi.csv",
        r.clients.map((c) => ({
          name: c.name,
          type: c.type,
          stage: c.stage,
          account_manager: c.account_manager ?? "",
          invoiced: (c.invoiced_pence / 100).toFixed(2),
          paid: (c.paid_pence / 100).toFixed(2),
          outstanding: (c.outstanding_pence / 100).toFixed(2),
          jobs: c.jobs,
        })),
      )
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not export KPIs.")
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Clients</h1>
          <p className="text-sm text-muted-foreground">
            Care homes, landlords, event organisers and more, in one place.
          </p>
        </div>
        <div className="flex gap-2">
          {clients.length > 0 && (
            <>
              <Button variant="outline" onClick={exportClients}><Download className="mr-1 size-4" /> Clients CSV</Button>
              <Button variant="outline" onClick={exportKpi}><Download className="mr-1 size-4" /> KPI report</Button>
            </>
          )}
          <NewClientDialog open={open} onOpenChange={setOpen} onCreated={reload} />
        </div>
      </div>

      <div className="relative max-w-sm">
        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          placeholder="Search clients…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          className="pl-9"
        />
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && clients.length === 0 && (
        <EmptyBlock
          title="No clients yet"
          hint="Add your first client to start tracking sites, services and invoices."
          action={
            <Button onClick={() => setOpen(true)}>
              <Plus className="mr-1 size-4" /> Add client
            </Button>
          }
        />
      )}

      {data && !loading && clients.length > 0 && (
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Client</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Stage</TableHead>
                <TableHead>Contact</TableHead>
                <TableHead>Sites</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Added</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((c) => (
                <TableRow key={c.id}>
                  <TableCell>
                    <Link to={`/app/clients/${c.id}`} className="font-medium text-primary hover:underline">
                      {c.name}
                    </Link>
                  </TableCell>
                  <TableCell className="capitalize">{c.type.replace("_", " ")}</TableCell>
                  <TableCell>
                    {c.stage ? <Badge variant={STAGE_VARIANT[c.stage] ?? "outline"} className="capitalize">{c.stage}</Badge> : "—"}
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {c.contact_name || "—"}
                    {c.email && <div className="text-xs">{c.email}</div>}
                  </TableCell>
                  <TableCell>{c.sites_count ?? 0}</TableCell>
                  <TableCell>
                    <Badge variant={STATUS_VARIANT[c.status] ?? "secondary"} className="capitalize">
                      {c.status}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{shortDate(c.created_at)}</TableCell>
                </TableRow>
              ))}
              {filtered.length === 0 && (
                <TableRow>
                  <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                    No clients match "{q}".
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

function NewClientDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  onCreated: () => void
}) {
  const [type, setType] = useState("care_home")
  const [submitting, setSubmitting] = useState(false)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const data = new FormData(e.currentTarget)
    const payload = {
      name: String(data.get("name") || "").trim(),
      type,
      contact_name: String(data.get("contact_name") || "").trim(),
      email: String(data.get("email") || "").trim(),
      phone: String(data.get("phone") || "").trim(),
    }
    if (!payload.name) {
      toast.error("Add a client name.")
      return
    }
    setSubmitting(true)
    try {
      await api("clients.php", { method: "POST", body: payload })
      toast.success("Client added.")
      onOpenChange(false)
      onCreated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not add client.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button>
          <Plus className="mr-1 size-4" /> Add client
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>New client</DialogTitle>
          <DialogDescription>Add the basics now; sites and documents come next.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="name">Client name</Label>
            <Input id="name" name="name" required />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="type">Type</Label>
            <Select value={type} onValueChange={setType}>
              <SelectTrigger id="type"><SelectValue /></SelectTrigger>
              <SelectContent>
                {CLIENT_TYPES.map((t) => (
                  <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="contact_name">Contact name</Label>
              <Input id="contact_name" name="contact_name" />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="phone">Phone</Label>
              <Input id="phone" name="phone" type="tel" />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="email">Email</Label>
            <Input id="email" name="email" type="email" />
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Add client"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
