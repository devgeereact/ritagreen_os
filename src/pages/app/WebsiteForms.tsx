import { useState } from "react"
import { CheckCircle2, ExternalLink, History, Pencil, Plus } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { shortDate } from "@/lib/format"
import { SERVICE_OPTIONS, serviceLabel } from "@/lib/services"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"
import { IntakeBadge } from "@/components/app/intake-badge"
import type { AssignableStaff, WebsiteForm } from "@/lib/types"

// Website area: the forms directory (section S7, file 06). Only an approved
// Google Forms address is accepted (the server refuses anything else) and only
// its id is kept; the website builds the embed from the id. Changing the address
// keeps the form, so every role page linked to it keeps working, and the
// previous address stays in the history.

type Draft = {
  id: number
  title: string
  purpose: WebsiteForm["purpose"]
  service: string
  url: string
  display: WebsiteForm["display"]
  active: boolean
  owner_id: string
  integration_id: string
}

type HistoryRow = {
  action: string
  created_at: string
  user_name: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
}

const NONE = "none"
const PURPOSE_LABEL: Record<WebsiteForm["purpose"], string> = {
  recruitment: "Recruitment",
  training: "Training booking",
  other: "Other",
}

export default function WebsiteForms() {
  const { data, loading, error, reload } = useApi<{ forms: WebsiteForm[]; integrations: { id: number; name: string; integration_key: string }[] }>(
    "website-forms.php",
  )
  const staff = useApi<{ staff: AssignableStaff[] }>("users.php?action=assignable")
  const [draft, setDraft] = useState<Draft | null>(null)
  const [saving, setSaving] = useState(false)
  const [history, setHistory] = useState<{ title: string; rows: HistoryRow[] } | null>(null)

  const forms = data?.forms ?? []
  const due = forms.filter((f) => f.active && f.review_due)

  function set<K extends keyof Draft>(key: K, value: Draft[K]) {
    setDraft((d) => (d ? { ...d, [key]: value } : d))
  }

  function edit(f: WebsiteForm) {
    setDraft({
      id: f.id, title: f.title, purpose: f.purpose, service: f.service ?? NONE, url: f.url, display: f.display,
      active: f.active, owner_id: f.owner_id ? String(f.owner_id) : NONE,
      integration_id: f.integration_id ? String(f.integration_id) : NONE,
    })
  }

  async function save() {
    if (!draft) return
    setSaving(true)
    const body = {
      title: draft.title, purpose: draft.purpose, service: draft.service === NONE ? null : draft.service,
      url: draft.url, display: draft.display, active: draft.active,
      owner_id: draft.owner_id === NONE ? null : Number(draft.owner_id),
      integration_id: draft.integration_id === NONE ? null : Number(draft.integration_id),
    }
    try {
      if (draft.id) await api(`website-forms.php?action=update&id=${draft.id}`, { method: "POST", body })
      else await api("website-forms.php?action=create", { method: "POST", body })
      toast.success("Form saved.")
      setDraft(null)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not save the form.")
    } finally {
      setSaving(false)
    }
  }

  async function markReviewed(f: WebsiteForm) {
    try {
      await api(`website-forms.php?action=reviewed&id=${f.id}`, { method: "POST", body: {} })
      toast.success("Marked as reviewed today.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not mark it reviewed.")
    }
  }

  async function showHistory(f: WebsiteForm) {
    try {
      const res = await api<{ history: HistoryRow[] }>(`website-forms.php?action=history&id=${f.id}`)
      setHistory({ title: f.title, rows: res.history })
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not load the history.")
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Forms</h1>
          <p className="text-sm text-muted-foreground">
            The Google Forms the website may show. Link a form to a vacancy in Vacancies.
          </p>
        </div>
        <Button
          onClick={() =>
            setDraft({ id: 0, title: "", purpose: "recruitment", service: NONE, url: "", display: "embedded", active: true, owner_id: NONE, integration_id: NONE })
          }
        >
          <Plus className="mr-1 size-4" /> Add a form
        </Button>
      </div>

      {due.length > 0 && (
        <p className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
          {due.length === 1 ? "One form has" : `${due.length} forms have`} not been reviewed for 90 days. Open each one,
          check the questions and the privacy link still match, then mark it reviewed.
        </p>
      )}

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && forms.length === 0 && (
        <EmptyBlock title="No forms yet" hint="Add the Google Form a role should use. Paste the address from Send, then the link tab." />
      )}

      {forms.length > 0 && (
        <div className="surface overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Form</TableHead>
                <TableHead>Used by</TableHead>
                <TableHead>Intake</TableHead>
                <TableHead>Reviewed</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {forms.map((f) => (
                <TableRow key={f.id}>
                  <TableCell>
                    <div className="font-medium">
                      {f.title} {!f.active && <Badge variant="secondary" className="ml-1">Off</Badge>}
                    </div>
                    <div className="text-xs text-muted-foreground">
                      {PURPOSE_LABEL[f.purpose]}
                      {f.service ? `, ${serviceLabel(f.service)}` : ""} · {f.display === "embedded" ? "Embedded" : "Link only"}
                      {f.owner_name ? ` · ${f.owner_name}` : ""}
                    </div>
                  </TableCell>
                  <TableCell className="text-sm">
                    {f.vacancies.length ? f.vacancies.join(", ") : <span className="text-muted-foreground">No vacancy</span>}
                  </TableCell>
                  <TableCell>
                    <IntakeBadge status={f.intake_status} />
                    {f.last_success_at && <div className="mt-0.5 text-xs text-muted-foreground">last {shortDate(f.last_success_at)}</div>}
                    {f.last_failure_reason && <div className="mt-0.5 text-xs text-destructive">{f.last_failure_reason}</div>}
                  </TableCell>
                  <TableCell className="text-sm">
                    {f.last_reviewed_at ? shortDate(f.last_reviewed_at) : "Never"}
                    {f.review_due && f.active && <Badge variant="destructive" className="ml-2">Due</Badge>}
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex flex-wrap justify-end gap-1">
                      <Button asChild variant="ghost" size="icon" aria-label={`Open ${f.title} in Google Forms`}>
                        <a href={f.url} target="_blank" rel="noopener noreferrer">
                          <ExternalLink className="size-4" />
                        </a>
                      </Button>
                      <Button variant="ghost" size="icon" onClick={() => showHistory(f)} aria-label={`History of ${f.title}`}>
                        <History className="size-4" />
                      </Button>
                      <Button variant="ghost" size="icon" onClick={() => edit(f)} aria-label={`Edit ${f.title}`}>
                        <Pencil className="size-4" />
                      </Button>
                      <Button variant="outline" size="sm" onClick={() => markReviewed(f)}>
                        <CheckCircle2 className="mr-1 size-3.5" /> Reviewed
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      <Dialog open={!!draft} onOpenChange={(o) => !o && setDraft(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
          <DialogHeader>
            <DialogTitle>{draft?.id ? "Edit form" : "Add a form"}</DialogTitle>
            <DialogDescription>
              In Google Forms choose Send, then the link tab, untick "Shorten URL" and paste the address here.
            </DialogDescription>
          </DialogHeader>
          {draft && (
            <div className="space-y-4">
              <div className="grid gap-2">
                <Label htmlFor="f-title">Title</Label>
                <Input id="f-title" value={draft.title} maxLength={160} placeholder="Support worker application" onChange={(e) => set("title", e.target.value)} />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="f-url">Google Forms address</Label>
                <Input id="f-url" value={draft.url} inputMode="url" placeholder="https://docs.google.com/forms/d/e/.../viewform" onChange={(e) => set("url", e.target.value)} />
                <p className="text-xs text-muted-foreground">Only a docs.google.com/forms address is accepted.</p>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                  <Label htmlFor="f-purpose">For</Label>
                  <Select value={draft.purpose} onValueChange={(x) => set("purpose", x as Draft["purpose"])}>
                    <SelectTrigger id="f-purpose"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {(Object.keys(PURPOSE_LABEL) as Draft["purpose"][]).map((p) => <SelectItem key={p} value={p}>{PURPOSE_LABEL[p]}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="f-service">Service</Label>
                  <Select value={draft.service} onValueChange={(x) => set("service", x)}>
                    <SelectTrigger id="f-service"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NONE}>Any</SelectItem>
                      {SERVICE_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="f-display">On the role page</Label>
                  <Select value={draft.display} onValueChange={(x) => set("display", x as Draft["display"])}>
                    <SelectTrigger id="f-display"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="embedded">Embedded, after the visitor chooses to load it</SelectItem>
                      <SelectItem value="link">A link that opens Google Forms</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="f-owner">Checks intake daily</Label>
                  <Select value={draft.owner_id} onValueChange={(x) => set("owner_id", x)} disabled={!staff.data}>
                    <SelectTrigger id="f-owner"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NONE}>Nobody</SelectItem>
                      {(staff.data?.staff ?? []).map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="grid gap-2">
                <Label htmlFor="f-int">Sends responses through</Label>
                <Select value={draft.integration_id} onValueChange={(x) => set("integration_id", x)}>
                  <SelectTrigger id="f-int"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NONE}>Not connected yet</SelectItem>
                    {(data?.integrations ?? []).map((i) => <SelectItem key={i.id} value={String(i.id)}>{i.name} ({i.integration_key})</SelectItem>)}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">The intake key the form's Apps Script posts to. See Settings, Form intake.</p>
              </div>
              <label className="flex items-center gap-3 text-sm">
                <Switch checked={draft.active} onCheckedChange={(x) => set("active", x)} />
                Active. When off, roles using this form say applications are closed.
              </label>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setDraft(null)} disabled={saving}>Cancel</Button>
            <Button onClick={save} disabled={saving}>{saving ? "Saving..." : "Save form"}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={!!history} onOpenChange={(o) => !o && setHistory(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
          <DialogHeader>
            <DialogTitle>History: {history?.title}</DialogTitle>
            <DialogDescription>Every change, with the previous value.</DialogDescription>
          </DialogHeader>
          {history && history.rows.length === 0 && <p className="text-sm text-muted-foreground">No changes recorded.</p>}
          <ul className="space-y-3 text-sm">
            {history?.rows.map((h, i) => (
              <li key={i} className="rounded-md border p-3">
                <div className="text-xs text-muted-foreground">
                  {shortDate(h.created_at)} · {h.user_name ?? "System"} · {h.action.replace("website_form.", "")}
                </div>
                {h.after &&
                  Object.keys(h.after).map((k) => (
                    <div key={k} className="mt-1 break-all">
                      <span className="font-medium">{k}</span>: {h.before && k in h.before ? `${String(h.before[k] ?? "empty")} → ` : ""}
                      {String(h.after?.[k] ?? "empty")}
                    </div>
                  ))}
              </li>
            ))}
          </ul>
        </DialogContent>
      </Dialog>
    </div>
  )
}
