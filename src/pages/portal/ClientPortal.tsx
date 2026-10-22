import { useState } from "react"
import { useNavigate } from "react-router-dom"
import { useDocumentTitle } from "@/hooks/use-document-title"
import { LogOut, ShieldCheck, ShieldAlert, Plus, MessageSquareWarning } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger,
} from "@/components/ui/dialog"
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select"
import { useAuth } from "@/providers/auth-provider"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { gbp, shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type PortalData = {
  client: { id: number; name: string; type: string; divisions: string }
  requests: { id: number; division: string; detail: string; status: string; preferred_start: string | null; created_at: string }[]
  invoices: { number: string; division: string; total_pence: number; status: string; due_date: string | null; created_at: string }[]
  workers: { id: number; name: string; role: string; division: string; cleared: boolean }[]
  contracts: { title: string; division: string; frequency: string; sla_hours: number | null; start_date: string; end_date: string; status: string }[]
}

const DIVS = ["care", "academy", "cleaning", "security"]

export default function ClientPortal() {
  useDocumentTitle("Client portal — RitaGreen Services")
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const { data, loading, error, reload } = useApi<PortalData>("portal.php")

  return (
    <div className="min-h-svh bg-muted/40">
      <header className="flex h-16 items-center justify-between border-b bg-background px-6">
        <span className="text-lg font-bold"><span className="text-foreground">Rita</span><span className="text-primary">Green</span> <span className="text-sm font-normal text-muted-foreground">· Client portal</span></span>
        <div className="flex items-center gap-3">
          <span className="hidden text-sm text-muted-foreground sm:inline">{user?.name}</span>
          <Button variant="ghost" size="sm" onClick={() => { logout(); navigate("/login", { replace: true }) }}>
            <LogOut className="mr-1 size-4" /> Sign out
          </Button>
        </div>
      </header>

      <main className="mx-auto max-w-5xl space-y-6 p-6">
        {loading && <LoadingBlock rows={5} />}
        {error && <ErrorBlock message={error} onRetry={reload} />}

        {data && !loading && (
          <>
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h1 className="text-2xl font-bold tracking-tight">{data.client.name}</h1>
                <p className="text-sm text-muted-foreground">Your cover, your people, your invoices.</p>
              </div>
              <div className="flex gap-2">
                <RequestDialog onDone={reload} />
                <IssueDialog onDone={reload} />
              </div>
            </div>

            <div className="grid gap-6 md:grid-cols-2">
              <Card>
                <CardHeader><CardTitle className="text-base">Your assigned team</CardTitle></CardHeader>
                <CardContent>
                  {data.workers.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No workers on upcoming shifts yet.</p>
                  ) : (
                    <ul className="space-y-2 text-sm">
                      {data.workers.map((w) => (
                        <li key={w.id} className="flex items-center justify-between">
                          <span>{w.name} · <span className="text-muted-foreground capitalize">{w.role}</span></span>
                          {w.cleared
                            ? <Badge variant="default" className="gap-1"><ShieldCheck className="size-3" /> Checked</Badge>
                            : <Badge variant="destructive" className="gap-1"><ShieldAlert className="size-3" /> Review</Badge>}
                        </li>
                      ))}
                    </ul>
                  )}
                </CardContent>
              </Card>

              <Card>
                <CardHeader><CardTitle className="text-base">Cover requests</CardTitle></CardHeader>
                <CardContent>
                  {data.requests.length === 0 ? (
                    <EmptyBlock title="No requests yet" hint="Raise a cover request and we'll arrange it." />
                  ) : (
                    <ul className="space-y-2 text-sm">
                      {data.requests.map((r) => (
                        <li key={r.id} className="flex items-center justify-between gap-2">
                          <span className="min-w-0 truncate">{DIVISION_LABELS[r.division] ?? r.division}: {r.detail}</span>
                          <Badge variant="secondary" className="capitalize">{r.status}</Badge>
                        </li>
                      ))}
                    </ul>
                  )}
                </CardContent>
              </Card>
            </div>

            <Card>
              <CardHeader><CardTitle className="text-base">Invoices</CardTitle></CardHeader>
              <CardContent>
                {data.invoices.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No invoices yet.</p>
                ) : (
                  <ul className="divide-y text-sm">
                    {data.invoices.map((i) => (
                      <li key={i.number} className="flex items-center justify-between py-2">
                        <span>{i.number} · <span className="text-muted-foreground">{DIVISION_LABELS[i.division] ?? i.division}</span></span>
                        <span className="flex items-center gap-3">
                          <span className="text-muted-foreground">{i.due_date ? `due ${shortDate(i.due_date)}` : shortDate(i.created_at)}</span>
                          <span className="font-medium">{gbp(i.total_pence)}</span>
                          <Badge variant={i.status === "paid" ? "default" : i.status === "overdue" ? "destructive" : "secondary"} className="capitalize">{i.status}</Badge>
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>

            {data.contracts.length > 0 && (
              <Card>
                <CardHeader><CardTitle className="text-base">Contracts</CardTitle></CardHeader>
                <CardContent>
                  <ul className="divide-y text-sm">
                    {data.contracts.map((c, i) => (
                      <li key={i} className="flex items-center justify-between py-2">
                        <span>{c.title} · <span className="text-muted-foreground capitalize">{c.frequency}</span></span>
                        <span className="text-muted-foreground">{shortDate(c.start_date)} → {shortDate(c.end_date)}</span>
                      </li>
                    ))}
                  </ul>
                </CardContent>
              </Card>
            )}
          </>
        )}
      </main>
    </div>
  )
}

function RequestDialog({ onDone }: { onDone: () => void }) {
  const [open, setOpen] = useState(false)
  const [division, setDivision] = useState("care")
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const payload = {
      division,
      detail: String(form.get("detail") || "").trim(),
      site_name: String(form.get("site_name") || "").trim(),
      preferred_start: String(form.get("preferred_start") || ""),
    }
    if (!payload.detail) { toast.error("Tell us what you need."); return }
    setBusy(true)
    try {
      await api("portal.php?action=request", { method: "POST", body: payload })
      toast.success("Request sent.")
      setOpen(false); onDone()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not send.")
    } finally { setBusy(false) }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild><Button><Plus className="mr-1 size-4" /> Request cover</Button></DialogTrigger>
      <DialogContent>
        <DialogHeader><DialogTitle>Request cover</DialogTitle><DialogDescription>We'll match a checked worker and confirm fast.</DialogDescription></DialogHeader>
        <form onSubmit={submit} className="grid gap-4">
          <div className="grid gap-2"><Label>Service</Label>
            <Select value={division} onValueChange={setDivision}><SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>{DIVS.map((d) => <SelectItem key={d} value={d} className="capitalize">{d}</SelectItem>)}</SelectContent></Select>
          </div>
          <div className="grid gap-2"><Label htmlFor="site_name">Site</Label><Input id="site_name" name="site_name" /></div>
          <div className="grid gap-2"><Label htmlFor="preferred_start">Preferred start</Label><Input id="preferred_start" name="preferred_start" type="datetime-local" /></div>
          <div className="grid gap-2"><Label htmlFor="detail">What you need</Label><Textarea id="detail" name="detail" rows={4} required /></div>
          <DialogFooter><Button type="submit" disabled={busy}>{busy ? "Sending…" : "Send request"}</Button></DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function IssueDialog({ onDone }: { onDone: () => void }) {
  const [open, setOpen] = useState(false)
  const [division, setDivision] = useState("care")
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const payload = { division, title: String(form.get("title") || "").trim(), detail: String(form.get("detail") || "").trim() }
    if (!payload.title || !payload.detail) { toast.error("Add a subject and detail."); return }
    setBusy(true)
    try {
      await api("portal.php?action=issue", { method: "POST", body: payload })
      toast.success("Issue raised — our team will follow up.")
      setOpen(false); onDone()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not raise the issue.")
    } finally { setBusy(false) }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild><Button variant="outline"><MessageSquareWarning className="mr-1 size-4" /> Raise an issue</Button></DialogTrigger>
      <DialogContent>
        <DialogHeader><DialogTitle>Raise an issue</DialogTitle><DialogDescription>Tell us what's wrong and we'll resolve it.</DialogDescription></DialogHeader>
        <form onSubmit={submit} className="grid gap-4">
          <div className="grid gap-2"><Label>Service</Label>
            <Select value={division} onValueChange={setDivision}><SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>{DIVS.map((d) => <SelectItem key={d} value={d} className="capitalize">{d}</SelectItem>)}</SelectContent></Select>
          </div>
          <div className="grid gap-2"><Label htmlFor="title">Subject</Label><Input id="title" name="title" required /></div>
          <div className="grid gap-2"><Label htmlFor="detail">Detail</Label><Textarea id="detail" name="detail" rows={4} required /></div>
          <DialogFooter><Button type="submit" disabled={busy}>{busy ? "Sending…" : "Raise issue"}</Button></DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
