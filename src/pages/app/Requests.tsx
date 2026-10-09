import { useMemo, useState } from "react"
import { Link } from "react-router-dom"
import { CalendarPlus, Check, Inbox, LifeBuoy, Mail, Phone, Plus, Trash2 } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { EmptyBlock, ErrorBlock, LoadingBlock } from "@/components/app/state-block"
import { ServiceDot } from "@/components/rota/bits"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { shortDate } from "@/lib/format"
import { fromInput, toInput } from "@/lib/rota"
import { cn } from "@/lib/utils"
import type { RequestItem, Site } from "@/lib/types"

// Requests, under the Rota: website cover requests and enquiries in one list,
// each with a type, an owner and a status. A cover request becomes draft shifts
// at the right site here; the rota is where they are filled and published.
//
// /app/enquiries and /app/bookings still exist for anybody with them bookmarked.

const ALL = "all"
const NOBODY = "none"

const STATUS_WORD: Record<string, string> = {
  new: "New", quoted: "Quoted", confirmed: "Shifts created", declined: "Declined", handled: "Handled",
}

type Staff = { id: number; name: string; role: string }

// The server accepts an office role as owner (requests.php), so only those are offered.
const OWNER_ROLES = ["super_admin", "admin", "manager"]

export default function Requests() {
  const [type, setType] = useState(ALL)
  const [status, setStatus] = useState("open")
  const endpoint = `requests.php?${new URLSearchParams({ ...(type !== ALL ? { type } : {}), ...(status !== ALL ? { status } : {}) })}`
  const { data, loading, error, reload } = useApi<{ requests: RequestItem[] }>(endpoint, [endpoint])
  const staff = useApi<{ staff: Staff[] }>("users.php?action=assignable")
  const sites = useApi<{ sites: Site[] }>("sites.php")
  const [creating, setCreating] = useState<RequestItem | null>(null)

  async function setOwner(r: RequestItem, value: string) {
    try {
      await api(`requests.php?action=owner&type=${r.type}&id=${r.id}`, {
        method: "POST",
        body: { user_id: value === NOBODY ? null : Number(value) },
      })
      toast.success(value === NOBODY ? "Owner cleared." : "Owner set.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not set the owner.")
    }
  }

  async function close(r: RequestItem) {
    try {
      if (r.type === "enquiry") {
        await api(`enquiries.php?action=handled&id=${r.id}`, { method: "POST", body: {} })
      } else {
        await api(`bookings.php?action=status&id=${r.id}`, { method: "POST", body: { status: "declined" } })
      }
      toast.success(r.type === "enquiry" ? "Marked handled." : "Request declined.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update the request.")
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Requests</h1>
          <p className="text-sm text-muted-foreground">
            Cover requests and enquiries from the website. Give each one an owner; turn cover requests into draft shifts.
          </p>
        </div>
        <Button variant="outline" asChild>
          <Link to="/app/scheduling">Open the rota</Link>
        </Button>
      </div>

      <div className="flex flex-wrap gap-2">
        <Select value={type} onValueChange={setType}>
          <SelectTrigger className="h-9 w-44" aria-label="Type"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All requests</SelectItem>
            <SelectItem value="cover">Cover requests</SelectItem>
            <SelectItem value="enquiry">Enquiries</SelectItem>
          </SelectContent>
        </Select>
        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger className="h-9 w-40" aria-label="Status"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="open">Still to deal with</SelectItem>
            <SelectItem value="closed">Dealt with</SelectItem>
            <SelectItem value={ALL}>Everything</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {loading && !data && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && data.requests.length === 0 && (
        <EmptyBlock title="Nothing waiting" hint="New cover requests and enquiries from the website appear here." />
      )}
      {data && data.requests.length > 0 && (
        <div className="space-y-2">
          {data.requests.map((r) => (
            <Card key={`${r.type}-${r.id}`}>
              <CardContent className="grid gap-3 p-4 md:grid-cols-[1fr_14rem_auto] md:items-start">
                <div className="min-w-0 space-y-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className={cn("inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium",
                      r.type === "cover" ? "border-primary/40 bg-accent" : "border-border bg-muted")}>
                      {r.type === "cover" ? <LifeBuoy className="size-3.5" aria-hidden /> : <Inbox className="size-3.5" aria-hidden />}
                      {r.type === "cover" ? "Cover request" : "Enquiry"}
                    </span>
                    <span className="rounded-full border border-border px-2 py-0.5 text-xs">{STATUS_WORD[r.status] ?? r.status}</span>
                    {r.division !== "other" && <ServiceDot service={r.division} />}
                    <span className="text-xs tabular-nums text-muted-foreground">{shortDate(r.created_at)}</span>
                  </div>
                  <p className="font-medium">{r.name}{r.client ? `, ${r.client}` : ""}</p>
                  <p className="line-clamp-3 text-sm text-muted-foreground">{r.summary}</p>
                  {r.type === "cover" && (r.preferred_start || r.site_name) && (
                    <p className="text-xs tabular-nums text-muted-foreground">
                      {r.site_name ? `${r.site_name}. ` : ""}
                      {r.preferred_start ? `Wanted from ${r.preferred_start.slice(0, 16)}` : ""}
                      {r.preferred_end ? ` to ${r.preferred_end.slice(0, 16)}` : ""}
                    </p>
                  )}
                  <p className="flex flex-wrap gap-3 text-xs">
                    <a className="inline-flex items-center gap-1 text-primary underline-offset-2 hover:underline" href={`mailto:${r.email}`}>
                      <Mail className="size-3.5" aria-hidden />{r.email}
                    </a>
                    {r.phone && (
                      <a className="inline-flex items-center gap-1 text-primary underline-offset-2 hover:underline" href={`tel:${r.phone}`}>
                        <Phone className="size-3.5" aria-hidden />{r.phone}
                      </a>
                    )}
                  </p>
                </div>
                <div className="grid gap-1.5">
                  <Label className="text-xs text-muted-foreground" htmlFor={`owner-${r.type}-${r.id}`}>Owner</Label>
                  <Select value={r.owner_id !== null ? String(r.owner_id) : NOBODY} onValueChange={(v) => void setOwner(r, v)}>
                    <SelectTrigger id={`owner-${r.type}-${r.id}`} className="h-9"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NOBODY}>Nobody yet</SelectItem>
                      {(staff.data?.staff ?? []).filter((u) => OWNER_ROLES.includes(u.role)).map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex flex-wrap gap-2 md:flex-col md:items-stretch">
                  {r.type === "cover" && r.open && (
                    <Button size="sm" onClick={() => setCreating(r)}>
                      <CalendarPlus className="size-4" /> Create shifts
                    </Button>
                  )}
                  {r.type === "cover" && r.shift_id && (
                    <Button size="sm" variant="outline" asChild>
                      <Link to="/app/scheduling">See on the rota</Link>
                    </Button>
                  )}
                  {r.open && (
                    <Button size="sm" variant="ghost" onClick={() => void close(r)}>
                      <Check className="size-4" /> {r.type === "enquiry" ? "Mark handled" : "Decline"}
                    </Button>
                  )}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <CreateShiftsDialog request={creating} sites={sites.data?.sites ?? []} onClose={() => setCreating(null)} onDone={reload} />
    </div>
  )
}

/** Turn a cover request into one or more draft shifts at a site. */
function CreateShiftsDialog({ request, sites, onClose, onDone }: {
  request: RequestItem | null
  sites: Site[]
  onClose: () => void
  onDone: () => void
}) {
  return (
    <Dialog open={request !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto">
        {request && <CreateShiftsForm key={request.id} request={request} sites={sites} onClose={onClose} onDone={onDone} />}
      </DialogContent>
    </Dialog>
  )
}

function CreateShiftsForm({ request, sites, onClose, onDone }: {
  request: RequestItem
  sites: Site[]
  onClose: () => void
  onDone: () => void
}) {
  const forClient = useMemo(
    () => sites.filter((s) => s.active && (request.client_id === null || s.client_id === request.client_id)),
    [sites, request.client_id],
  )
  const guess = forClient.find((s) => request.site_name && s.name.toLowerCase() === request.site_name.toLowerCase())
  const [siteId, setSiteId] = useState(guess ? String(guess.id) : "")
  const [title, setTitle] = useState(`Cover: ${request.name}`)
  const first = request.preferred_start ? toInput(request.preferred_start) : ""
  const firstEnd = request.preferred_end ? toInput(request.preferred_end) : ""
  const [slots, setSlots] = useState([{ starts: first, ends: firstEnd }])
  const [busy, setBusy] = useState(false)

  async function submit() {
    setBusy(true)
    try {
      const r = await api<{ shift_ids: number[] }>(`bookings.php?action=confirm&id=${request.id}`, {
        method: "POST",
        body: {
          title,
          ...(siteId ? { site_id: Number(siteId) } : {}),
          slots: slots.map((s) => ({ starts_at: fromInput(s.starts), ends_at: fromInput(s.ends) })),
        },
      })
      toast.success(`${r.shift_ids.length} draft ${r.shift_ids.length === 1 ? "shift" : "shifts"} added to the rota. The client has been sent a confirmation.`)
      onDone()
      onClose()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not create the shifts.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Create shifts from this request</DialogTitle>
        <DialogDescription>They are added as drafts. Fill and publish them from the rota.</DialogDescription>
      </DialogHeader>
      <div className="grid gap-3">
        <div className="grid gap-1.5">
          <Label htmlFor="cs-title">Role</Label>
          <Input id="cs-title" value={title} onChange={(e) => setTitle(e.target.value)} />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="cs-site">Site</Label>
          <Select value={siteId} onValueChange={setSiteId}>
            <SelectTrigger id="cs-site"><SelectValue placeholder={request.site_name ?? "Choose a site"} /></SelectTrigger>
            <SelectContent>
              {forClient.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}{s.client ? ` (${s.client})` : ""}</SelectItem>)}
            </SelectContent>
          </Select>
          {forClient.length === 0 && (
            <p className="text-xs text-muted-foreground">No sites on file for this client yet. Add one on the client&apos;s Sites tab, or leave it blank.</p>
          )}
        </div>
        {slots.map((s, i) => (
          <div key={i} className="grid gap-2 rounded-md border border-border p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <div className="grid gap-1.5">
              <Label htmlFor={`cs-s-${i}`}>Shift {i + 1} starts</Label>
              <Input id={`cs-s-${i}`} type="datetime-local" value={s.starts}
                onChange={(e) => setSlots((all) => all.map((x, j) => (j === i ? { ...x, starts: e.target.value } : x)))} />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor={`cs-e-${i}`}>Ends</Label>
              <Input id={`cs-e-${i}`} type="datetime-local" value={s.ends}
                onChange={(e) => setSlots((all) => all.map((x, j) => (j === i ? { ...x, ends: e.target.value } : x)))} />
            </div>
            {slots.length > 1 && (
              <Button variant="ghost" size="icon" aria-label={`Remove shift ${i + 1}`} onClick={() => setSlots((all) => all.filter((_, j) => j !== i))}>
                <Trash2 className="size-4" />
              </Button>
            )}
          </div>
        ))}
        <Button variant="outline" size="sm" className="justify-self-start"
          onClick={() => setSlots((all) => [...all, { starts: "", ends: "" }])}>
          <Plus className="size-4" /> Another shift
        </Button>
      </div>
      <DialogFooter>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} disabled={busy || slots.some((s) => !s.starts || !s.ends)}>
          {busy ? "Creating…" : `Create ${slots.length} draft ${slots.length === 1 ? "shift" : "shifts"}`}
        </Button>
      </DialogFooter>
    </>
  )
}
