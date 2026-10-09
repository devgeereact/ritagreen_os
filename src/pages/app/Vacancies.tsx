import { useState } from "react"
import { ExternalLink, Pencil, Plus } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
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
import { SERVICE_OPTIONS, serviceLabel, type ServiceId } from "@/lib/services"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"
import type { AssignableStaff, Vacancy, VacancyStatus, WebsiteForm } from "@/lib/types"

// Website area: Vacancies (section S7, file 06). What is published here is what
// /careers and each role page show, within five minutes. Drafts are never public.
// A role with a linked Google Form takes applications through that form only;
// without one, the role page shows the short native form.

type Draft = {
  id: number
  slug: string
  slug_editable: boolean
  title: string
  service: ServiceId
  location: string
  hours_text: string
  pay_text: string
  summary: string
  requirements: string
  checks: string
  form_id: string
  owner_id: string
  status: VacancyStatus
}

const NONE = "none"

const STATUS_LABEL: Record<VacancyStatus, string> = { draft: "Draft", published: "Published", closed: "Closed" }
const STATUS_VARIANT: Record<VacancyStatus, "default" | "secondary" | "outline"> = {
  published: "default",
  draft: "secondary",
  closed: "outline",
}

export default function Vacancies() {
  const { data, loading, error, reload } = useApi<{ vacancies: Vacancy[]; default_checks: Record<ServiceId, string> }>(
    "vacancies.php?action=all",
  )
  const forms = useApi<{ forms: WebsiteForm[] }>("website-forms.php")
  // Office roles only: an editor manages roles without choosing an owner.
  const staff = useApi<{ staff: AssignableStaff[] }>("users.php?action=assignable")
  const [draft, setDraft] = useState<Draft | null>(null)
  const [saving, setSaving] = useState(false)

  const vacancies = data?.vacancies ?? []
  const defaults = data?.default_checks

  function startNew() {
    setDraft({
      id: 0, slug: "", slug_editable: true, title: "", service: "care", location: "", hours_text: "", pay_text: "",
      summary: "", requirements: "", checks: defaults?.care ?? "", form_id: NONE, owner_id: NONE, status: "draft",
    })
  }

  async function startEdit(v: Vacancy) {
    try {
      const res = await api<{ vacancy: Vacancy }>(`vacancies.php?action=get&id=${v.id}`)
      const full = res.vacancy
      setDraft({
        id: full.id, slug: full.slug, slug_editable: full.slug_editable, title: full.title, service: full.service,
        location: full.location, hours_text: full.hours_text, pay_text: full.pay_text ?? "", summary: full.summary ?? "",
        requirements: full.requirements ?? "", checks: full.checks ?? "",
        form_id: full.form_id ? String(full.form_id) : NONE, owner_id: full.owner_id ? String(full.owner_id) : NONE,
        status: full.status,
      })
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not open that vacancy.")
    }
  }

  function set<K extends keyof Draft>(key: K, value: Draft[K]) {
    setDraft((d) => (d ? { ...d, [key]: value } : d))
  }

  function changeService(next: ServiceId) {
    setDraft((d) => {
      if (!d) return d
      // Swap the prefilled checks with the service, but never overwrite checks
      // somebody has edited.
      const untouched = !d.checks.trim() || (defaults && Object.values(defaults).includes(d.checks))
      return { ...d, service: next, checks: untouched && defaults ? defaults[next] : d.checks }
    })
  }

  async function save() {
    if (!draft) return
    setSaving(true)
    const body = {
      title: draft.title, service: draft.service, location: draft.location, hours_text: draft.hours_text,
      pay_text: draft.pay_text, summary: draft.summary, requirements: draft.requirements, checks: draft.checks,
      form_id: draft.form_id === NONE ? null : Number(draft.form_id),
      owner_id: draft.owner_id === NONE ? null : Number(draft.owner_id),
      ...(draft.slug_editable && draft.slug.trim() ? { slug: draft.slug.trim() } : {}),
    }
    try {
      if (draft.id) {
        await api(`vacancies.php?action=update&id=${draft.id}`, { method: "POST", body })
        toast.success("Vacancy saved.")
      } else {
        await api("vacancies.php?action=create", { method: "POST", body })
        toast.success("Vacancy created as a draft. Publish it when it is ready.")
      }
      setDraft(null)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not save the vacancy.")
    } finally {
      setSaving(false)
    }
  }

  async function move(v: Vacancy, status: VacancyStatus) {
    try {
      await api(`vacancies.php?action=status&id=${v.id}`, { method: "POST", body: { status } })
      toast.success(status === "published" ? "Published. It will show on the website within five minutes." : status === "closed" ? "Closed. It has left the careers page." : "Saved.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not change the vacancy.")
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Vacancies</h1>
          <p className="text-sm text-muted-foreground">
            Published roles appear on the careers page within five minutes. Drafts are never public.
          </p>
        </div>
        <Button onClick={startNew} disabled={!data}>
          <Plus className="mr-1 size-4" /> New vacancy
        </Button>
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && vacancies.length === 0 && (
        <EmptyBlock title="No vacancies yet" hint="Create a role, link its Google Form, then publish it." />
      )}

      {vacancies.length > 0 && (
        <div className="surface overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Role</TableHead>
                <TableHead>Service</TableHead>
                <TableHead>Applications</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Owner</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {vacancies.map((v) => (
                <TableRow key={v.id}>
                  <TableCell>
                    <div className="font-medium">{v.title}</div>
                    <div className="text-xs text-muted-foreground">/careers/{v.slug}</div>
                  </TableCell>
                  <TableCell>{serviceLabel(v.service)}</TableCell>
                  <TableCell className="text-sm">
                    {v.form_id ? (
                      <>
                        {v.form_title}
                        {v.form_active === false && <Badge variant="destructive" className="ml-2">Form off</Badge>}
                      </>
                    ) : (
                      <span className="text-muted-foreground">Short form on the website</span>
                    )}
                  </TableCell>
                  <TableCell>
                    <Badge variant={STATUS_VARIANT[v.status]}>{STATUS_LABEL[v.status]}</Badge>
                    {v.published_at && (
                      <div className="mt-0.5 text-xs text-muted-foreground">since {shortDate(v.published_at)}</div>
                    )}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">{v.owner_name ?? "Nobody"}</TableCell>
                  <TableCell className="text-right">
                    <div className="flex flex-wrap justify-end gap-1">
                      {v.status === "published" && (
                        <Button asChild variant="ghost" size="icon" aria-label={`View ${v.title} on the website`}>
                          <a href={`/careers/${v.slug}`} target="_blank" rel="noopener noreferrer">
                            <ExternalLink className="size-4" />
                          </a>
                        </Button>
                      )}
                      <Button variant="ghost" size="icon" onClick={() => startEdit(v)} aria-label={`Edit ${v.title}`}>
                        <Pencil className="size-4" />
                      </Button>
                      {v.status !== "published" && (
                        <Button size="sm" onClick={() => move(v, "published")}>
                          {v.status === "closed" ? "Reopen" : "Publish"}
                        </Button>
                      )}
                      {v.status === "published" && (
                        <Button size="sm" variant="outline" onClick={() => move(v, "closed")}>
                          Close
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      <Dialog open={!!draft} onOpenChange={(o) => !o && setDraft(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>{draft?.id ? "Edit vacancy" : "New vacancy"}</DialogTitle>
            <DialogDescription>
              Saved as a draft. Publish it from the list when it is ready.
            </DialogDescription>
          </DialogHeader>

          {draft && (
            <div className="space-y-4">
              <div className="grid gap-2">
                <Label htmlFor="v-title">Title</Label>
                <Input id="v-title" value={draft.title} maxLength={160} placeholder="Support worker, Croydon" onChange={(e) => set("title", e.target.value)} />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="v-slug">Web address</Label>
                {draft.slug_editable ? (
                  <Input id="v-slug" value={draft.slug} placeholder="made from the title" onChange={(e) => set("slug", e.target.value.toLowerCase())} />
                ) : (
                  <Input id="v-slug" value={draft.slug} disabled />
                )}
                <p className="text-xs text-muted-foreground">
                  {draft.slug_editable
                    ? "ritagreenservices.com/careers/... Fixed once the role is published."
                    : "This role has been published, so its address is fixed and existing links keep working."}
                </p>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                  <Label htmlFor="v-service">Service</Label>
                  <Select value={draft.service} onValueChange={(x) => changeService(x as ServiceId)}>
                    <SelectTrigger id="v-service"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {SERVICE_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="v-location">Location</Label>
                  <Input id="v-location" value={draft.location} maxLength={160} placeholder="Croydon" onChange={(e) => set("location", e.target.value)} />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="v-hours">Hours or pattern</Label>
                  <Input id="v-hours" value={draft.hours_text} maxLength={160} placeholder="Days and nights, 36 hours a week" onChange={(e) => set("hours_text", e.target.value)} />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="v-pay">Pay (optional)</Label>
                  <Input id="v-pay" value={draft.pay_text} maxLength={160} placeholder="Leave empty if not confirmed" onChange={(e) => set("pay_text", e.target.value)} />
                </div>
              </div>
              <div className="grid gap-2">
                <Label htmlFor="v-summary">Summary</Label>
                <Textarea id="v-summary" rows={3} value={draft.summary} onChange={(e) => set("summary", e.target.value)} />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="v-req">What you need (one per line)</Label>
                <Textarea id="v-req" rows={4} value={draft.requirements} onChange={(e) => set("requirements", e.target.value)} />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="v-checks">Checks we will do (one per line)</Label>
                <Textarea id="v-checks" rows={5} value={draft.checks} onChange={(e) => set("checks", e.target.value)} />
                <p className="text-xs text-muted-foreground">Prefilled for the service. Shown to applicants; it does not change who may work.</p>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                  <Label htmlFor="v-form">Application form</Label>
                  <Select value={draft.form_id} onValueChange={(x) => set("form_id", x)}>
                    <SelectTrigger id="v-form"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NONE}>None: the short form on the website</SelectItem>
                      {(forms.data?.forms ?? []).map((f) => (
                        <SelectItem key={f.id} value={String(f.id)}>
                          {f.title}{f.active ? "" : " (off)"}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="v-owner">Owner</Label>
                  <Select value={draft.owner_id} onValueChange={(x) => set("owner_id", x)} disabled={!staff.data}>
                    <SelectTrigger id="v-owner"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NONE}>Nobody</SelectItem>
                      {(staff.data?.staff ?? []).map((s) => (
                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => setDraft(null)} disabled={saving}>Cancel</Button>
            <Button onClick={save} disabled={saving}>{saving ? "Saving..." : draft?.id ? "Save changes" : "Create draft"}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
