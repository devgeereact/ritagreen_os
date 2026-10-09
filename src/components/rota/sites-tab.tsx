import { useState } from "react"
import { Building2, KeyRound, MapPin, Pencil, Phone, Plus, ShieldCheck } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { EmptyBlock, ErrorBlock, LoadingBlock } from "@/components/app/state-block"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import type { RequirementOption, Site } from "@/lib/types"

type Data = { sites: Site[]; requirements: RequirementOption[] }

/**
 * A client's sites: where the work happens, how to get in, who to ask for, and
 * what training the site insists on. That last list is part of eligibility: a
 * shift at the site is assessed against the service's rules plus these
 * (server: rg_load_rules), so the rota refuses somebody without them and says why.
 */
export function SitesTab({ clientId }: { clientId: number }) {
  const { data, loading, error, reload } = useApi<Data>(`sites.php?client_id=${clientId}`, [clientId])
  const [editing, setEditing] = useState<Site | "new" | null>(null)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          Shifts are booked at a site. Workers see the address, access notes and contact on their shift.
        </p>
        <Button size="sm" onClick={() => setEditing("new")}>
          <Plus className="size-4" /> Add site
        </Button>
      </div>

      {loading && !data && <LoadingBlock rows={3} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && data.sites.length === 0 && (
        <EmptyBlock title="No sites yet" hint="Add the homes, buildings or venues this client needs covering."
          action={<Button onClick={() => setEditing("new")}><Plus className="size-4" /> Add site</Button>} />
      )}
      {data && data.sites.length > 0 && (
        <div className="grid gap-3 lg:grid-cols-2">
          {data.sites.map((s) => (
            <article key={s.id} className="space-y-2 rounded-lg border border-border bg-card p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="flex items-start gap-2">
                  <Building2 className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden />
                  <div>
                    <p className="font-medium">{s.name}</p>
                    {!s.active && <p className="text-xs font-medium text-muted-foreground">Closed: no new shifts</p>}
                  </div>
                </div>
                <Button size="sm" variant="ghost" onClick={() => setEditing(s)} aria-label={`Edit ${s.name}`}>
                  <Pencil className="size-4" />
                </Button>
              </div>
              {(s.address || s.postcode) && (
                <p className="flex items-start gap-2 text-sm text-muted-foreground">
                  <MapPin className="mt-0.5 size-4 shrink-0" aria-hidden />
                  {[s.address, s.postcode].filter(Boolean).join(", ")}
                </p>
              )}
              {(s.contact_name || s.contact_phone) && (
                <p className="flex items-start gap-2 text-sm">
                  <Phone className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
                  {[s.contact_name, s.contact_phone].filter(Boolean).join(", ")}
                </p>
              )}
              {s.access_notes && (
                <p className="flex items-start gap-2 text-sm">
                  <KeyRound className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
                  <span className="whitespace-pre-line">{s.access_notes}</span>
                </p>
              )}
              <div className="flex flex-wrap items-center gap-1.5 pt-1">
                <ShieldCheck className="size-4 text-muted-foreground" aria-hidden />
                {s.requirements.length === 0 ? (
                  <span className="text-xs text-muted-foreground">No extra requirements beyond the service&apos;s own.</span>
                ) : (
                  s.requirements.map((r) => (
                    <span key={r.id} className="rounded-full border border-border px-2 py-0.5 text-xs">{r.label}</span>
                  ))
                )}
              </div>
            </article>
          ))}
        </div>
      )}

      <SiteDialog
        clientId={clientId}
        site={editing}
        requirements={data?.requirements ?? []}
        onClose={() => setEditing(null)}
        onSaved={reload}
      />
    </div>
  )
}

function SiteDialog({ clientId, site, requirements, onClose, onSaved }: {
  clientId: number
  site: Site | "new" | null
  requirements: RequirementOption[]
  onClose: () => void
  onSaved: () => void
}) {
  return (
    <Dialog open={site !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto">
        {site !== null && (
          <SiteForm key={site === "new" ? "new" : site.id} clientId={clientId} site={site === "new" ? null : site}
            requirements={requirements} onClose={onClose} onSaved={onSaved} />
        )}
      </DialogContent>
    </Dialog>
  )
}

function SiteForm({ clientId, site, requirements, onClose, onSaved }: {
  clientId: number
  site: Site | null
  requirements: RequirementOption[]
  onClose: () => void
  onSaved: () => void
}) {
  const [name, setName] = useState(site?.name ?? "")
  const [address, setAddress] = useState(site?.address ?? "")
  const [postcode, setPostcode] = useState(site?.postcode ?? "")
  const [notes, setNotes] = useState(site?.access_notes ?? "")
  const [contact, setContact] = useState(site?.contact_name ?? "")
  const [phone, setPhone] = useState(site?.contact_phone ?? "")
  const [active, setActive] = useState(site?.active ?? true)
  const [reqs, setReqs] = useState<number[]>(site?.requirements.map((r) => r.id) ?? [])
  const [busy, setBusy] = useState(false)

  async function save() {
    setBusy(true)
    const body = {
      name, address, postcode, access_notes: notes, contact_name: contact, contact_phone: phone,
      active, requirement_ids: reqs,
    }
    try {
      if (site) {
        await api(`sites.php?action=update&id=${site.id}`, { method: "POST", body })
      } else {
        await api("sites.php", { method: "POST", body: { client_id: clientId, ...body } })
      }
      toast.success(site ? "Site saved." : "Site added.")
      onSaved()
      onClose()
    } catch (err) {
      // What was typed stays in the form.
      toast.error(err instanceof ApiError ? err.message : "Could not save the site.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{site ? `Edit ${site.name}` : "Add site"}</DialogTitle>
        <DialogDescription>Workers booked here see the address, access notes and contact on their shift.</DialogDescription>
      </DialogHeader>
      <div className="grid gap-3">
        <div className="grid gap-1.5">
          <Label htmlFor="site-name">Name</Label>
          <Input id="site-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="For example: Oakleaf, Garden Wing" />
        </div>
        <div className="grid gap-3 sm:grid-cols-[1fr_8rem]">
          <div className="grid gap-1.5">
            <Label htmlFor="site-address">Address</Label>
            <Input id="site-address" value={address} onChange={(e) => setAddress(e.target.value)} />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="site-postcode">Postcode</Label>
            <Input id="site-postcode" value={postcode} onChange={(e) => setPostcode(e.target.value)} />
          </div>
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="site-notes">Access notes</Label>
          <Textarea id="site-notes" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)}
            placeholder="How to get in, where to sign in, parking" />
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="grid gap-1.5">
            <Label htmlFor="site-contact">Site contact</Label>
            <Input id="site-contact" value={contact} onChange={(e) => setContact(e.target.value)} />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="site-phone">Contact phone</Label>
            <Input id="site-phone" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
          </div>
        </div>
        <fieldset className="grid gap-2">
          <legend className="mb-1 text-sm font-medium">Required here, on top of the service&apos;s own checks</legend>
          <div className="grid max-h-48 gap-1.5 overflow-y-auto rounded-md border border-border p-2 sm:grid-cols-2">
            {requirements.map((r) => (
              <label key={r.id} className="flex items-center gap-2 text-sm">
                <Checkbox checked={reqs.includes(r.id)}
                  onCheckedChange={(v) => setReqs((c) => (v === true ? [...c, r.id] : c.filter((x) => x !== r.id)))} />
                {r.label}
              </label>
            ))}
          </div>
          <p className="text-xs text-muted-foreground">Somebody without these cannot be booked at this site. The rota says which one is missing.</p>
        </fieldset>
        {site && (
          <label className="flex items-center justify-between gap-2 rounded-md border border-border p-3 text-sm">
            <span>
              <span className="font-medium">Open for new shifts</span>
              <span className="block text-xs text-muted-foreground">Closing keeps the site and its history.</span>
            </span>
            <Switch checked={active} onCheckedChange={setActive} />
          </label>
        )}
      </div>
      <DialogFooter>
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button onClick={save} disabled={busy || name.trim() === ""}>{busy ? "Saving…" : "Save site"}</Button>
      </DialogFooter>
    </>
  )
}
