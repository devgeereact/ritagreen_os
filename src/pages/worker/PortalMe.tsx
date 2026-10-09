import { useState, type FormEvent } from "react"
import { useNavigate } from "react-router-dom"
import { LogOut, Mail, Phone, Trash2 } from "lucide-react"
import { useDocumentTitle } from "@/hooks/use-document-title"
import { useApi } from "@/hooks/use-api"
import { useAuth } from "@/providers/auth-provider"
import { api } from "@/lib/api"
import { COMPANY } from "@/lib/company"
import { DIVISION_LABELS } from "@/lib/format"
import { dateOnly } from "@/lib/people"
import { addDays } from "@/lib/rota"
import { londonNow } from "@/lib/portal"
import type { PortalLeave, PortalPerson, PortalProfile } from "@/lib/types"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Chip, Empty, PortalCard, PortalHeader, PortalSection, PortalState } from "@/components/worker/portal-bits"
import { act, fieldClass } from "@/components/worker/portal-utils"

export default function PortalMe() {
  useDocumentTitle("Me, RitaGreen staff portal")
  const { logout } = useAuth()
  const navigate = useNavigate()
  const { data, loading, error, reload } = useApi<PortalProfile>("me.php?action=profile")

  return (
    <>
      <PortalHeader title="Me" sub={data ? data.person.full_name : undefined} />
      <PortalState loading={loading} error={error} onRetry={reload} hasData={!!data} rows={4}>
        {data && (
          <>
            <PortalCard>
              <dl className="grid grid-cols-2 gap-3 text-sm">
                <div><dt className="text-muted-foreground">Role</dt><dd className="capitalize">{data.worker.role}</dd></div>
                <div><dt className="text-muted-foreground">Service</dt><dd>{DIVISION_LABELS[data.worker.division] ?? data.worker.division}</dd></div>
                <div><dt className="text-muted-foreground">Payroll number</dt><dd className="tabular-nums">{data.worker.payroll_no ?? "Not set"}</dd></div>
                <div className="min-w-0"><dt className="text-muted-foreground">Sign-in email</dt><dd className="break-words">{data.login_email}</dd></div>
              </dl>
              <p className="mt-3 text-xs text-muted-foreground">To change your name or email, contact the office.</p>
            </PortalCard>
            <ContactForm person={data.person} onSaved={reload} />
            <EmergencyForm person={data.person} onSaved={reload} />
          </>
        )}
      </PortalState>
      <Availability />
      <Leave />

      <PortalSection title="Contact the office" id="contact">
        <PortalCard className="space-y-2">
          <Button asChild variant="outline" className="h-11 w-full justify-start">
            <a href={COMPANY.phoneHref}><Phone className="mr-2 size-4" aria-hidden /> Call {COMPANY.phone}</a>
          </Button>
          <Button asChild variant="outline" className="h-11 w-full justify-start">
            <a href={COMPANY.emergencyPhoneHref}><Phone className="mr-2 size-4" aria-hidden /> Urgent line {COMPANY.emergencyPhone}</a>
          </Button>
          <Button asChild variant="outline" className="h-11 w-full justify-start">
            <a href={`mailto:${COMPANY.email}`}><Mail className="mr-2 size-4" aria-hidden /> {COMPANY.email}</a>
          </Button>
          <p className="text-xs text-muted-foreground">{COMPANY.hours}.</p>
        </PortalCard>
      </PortalSection>

      <Button
        variant="outline"
        className="h-12 w-full"
        onClick={() => {
          logout()
          navigate("/login", { replace: true })
        }}
      >
        <LogOut className="mr-2 size-4" aria-hidden /> Log out
      </Button>
    </>
  )
}

function Field({ id, label, value, onChange, type = "text", autoComplete, inputMode, required }: {
  id: string
  label: string
  value: string
  onChange: (v: string) => void
  type?: string
  autoComplete?: string
  inputMode?: "tel" | "text" | "numeric"
  required?: boolean
}) {
  return (
    <div className="space-y-1">
      <Label htmlFor={id}>{label}</Label>
      <input id={id} className={fieldClass} type={type} value={value} autoComplete={autoComplete} inputMode={inputMode}
        required={required} onChange={(e) => onChange(e.target.value)} />
    </div>
  )
}

function ContactForm({ person, onSaved }: { person: PortalPerson; onSaved: () => void }) {
  const initial = {
    phone: person.phone ?? "", address_line1: person.address_line1 ?? "", address_line2: person.address_line2 ?? "",
    city: person.city ?? "", postcode: person.postcode ?? "",
  }
  const [form, setForm] = useState(initial)
  const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof initial) => (v: string) => setForm((f) => ({ ...f, [k]: v }))

  async function save(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    const ok = await act(() => api("me.php?action=profile", { method: "POST", body: form }), "Your details are saved.")
    setBusy(false)
    if (ok) onSaved()
  }

  return (
    <PortalSection title="My contact details" id="contact-details">
      <PortalCard>
        <form className="space-y-3" onSubmit={save}>
          <Field id="phone" label="Mobile number" type="tel" inputMode="tel" autoComplete="tel" value={form.phone} onChange={set("phone")} required />
          <Field id="addr1" label="Address" autoComplete="address-line1" value={form.address_line1} onChange={set("address_line1")} />
          <Field id="addr2" label="Address line 2 (optional)" autoComplete="address-line2" value={form.address_line2} onChange={set("address_line2")} />
          <div className="grid grid-cols-2 gap-3">
            <Field id="city" label="Town or city" autoComplete="address-level2" value={form.city} onChange={set("city")} />
            <Field id="postcode" label="Postcode" autoComplete="postal-code" value={form.postcode} onChange={set("postcode")} />
          </div>
          <Button type="submit" className="h-11 w-full" disabled={busy}>{busy ? "Saving…" : "Save my details"}</Button>
        </form>
      </PortalCard>
    </PortalSection>
  )
}

function EmergencyForm({ person, onSaved }: { person: PortalPerson; onSaved: () => void }) {
  const initial = {
    emergency_contact_name: person.emergency_contact_name ?? "",
    emergency_contact_phone: person.emergency_contact_phone ?? "",
    emergency_contact_relationship: person.emergency_contact_relationship ?? "",
    emergency_contact2_name: person.emergency_contact2_name ?? "",
    emergency_contact2_phone: person.emergency_contact2_phone ?? "",
    emergency_contact2_relationship: person.emergency_contact2_relationship ?? "",
  }
  const [form, setForm] = useState(initial)
  const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof initial) => (v: string) => setForm((f) => ({ ...f, [k]: v }))

  async function save(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    const ok = await act(() => api("me.php?action=emergency", { method: "POST", body: form }), "Emergency contacts saved.")
    setBusy(false)
    if (ok) onSaved()
  }

  return (
    <PortalSection title="Emergency contacts" id="emergency">
      <PortalCard>
        <form className="space-y-4" onSubmit={save}>
          {(["", "2"] as const).map((n) => (
            <fieldset key={n} className="space-y-3">
              <legend className="text-sm font-semibold">{n === "" ? "First contact" : "Second contact (optional)"}</legend>
              <Field id={`ec${n}-name`} label="Name" value={form[`emergency_contact${n}_name`]} onChange={set(`emergency_contact${n}_name`)} />
              <div className="grid grid-cols-2 gap-3">
                <Field id={`ec${n}-phone`} label="Phone" type="tel" inputMode="tel" value={form[`emergency_contact${n}_phone`]} onChange={set(`emergency_contact${n}_phone`)} />
                <Field id={`ec${n}-rel`} label="Relationship" value={form[`emergency_contact${n}_relationship`]} onChange={set(`emergency_contact${n}_relationship`)} />
              </div>
            </fieldset>
          ))}
          <Button type="submit" className="h-11 w-full" disabled={busy}>{busy ? "Saving…" : "Save emergency contacts"}</Button>
        </form>
      </PortalCard>
    </PortalSection>
  )
}

type Unavailable = { id: number; starts_at: string; ends_at: string; note: string | null }

function Availability() {
  const { data, loading, error, reload } = useApi<{ unavailable: Unavailable[] }>("me.php?action=availability")
  const today = londonNow().slice(0, 10)
  const [from, setFrom] = useState(today)
  const [to, setTo] = useState(today)
  const [note, setNote] = useState("")
  const [busy, setBusy] = useState(false)

  async function add(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    // Whole days: from the start of the first to the start of the day after the last.
    const ok = await act(
      () => api("me.php?action=availability", { method: "POST", body: { starts_at: `${from} 00:00`, ends_at: `${addDays(to, 1)} 00:00`, note } }),
      "Saved. The office will see you are not available.",
    )
    setBusy(false)
    if (ok) {
      setNote("")
      reload()
    }
  }

  return (
    <PortalSection title="When I cannot work" id="availability">
      <PortalState loading={loading} error={error} onRetry={reload} hasData={!!data} rows={2}>
        {data && (
          <div className="space-y-2">
            {data.unavailable.length === 0 ? (
              <Empty>You have not marked any days.</Empty>
            ) : data.unavailable.map((u) => (
              <PortalCard key={u.id} className="flex items-center justify-between gap-3">
                <div className="min-w-0 text-sm">
                  <p className="font-medium tabular-nums">{dateOnly(u.starts_at.slice(0, 10))} to {dateOnly(addDays(u.ends_at.slice(0, 10), u.ends_at.slice(11, 16) === "00:00" ? -1 : 0))}</p>
                  {u.note && <p className="text-muted-foreground">{u.note}</p>}
                </div>
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-11 shrink-0"
                  aria-label={`Remove ${dateOnly(u.starts_at.slice(0, 10))}`}
                  onClick={() => act(() => api(`me.php?action=availability_remove&id=${u.id}`, { method: "POST" }), "Removed.").then((ok) => ok && reload())}
                >
                  <Trash2 className="size-4" aria-hidden />
                </Button>
              </PortalCard>
            ))}
          </div>
        )}
      </PortalState>
      <PortalCard>
        <form className="space-y-3" onSubmit={add}>
          <div className="grid grid-cols-2 gap-3">
            <Field id="un-from" label="From" type="date" value={from} onChange={(v) => { setFrom(v); if (to < v) setTo(v) }} required />
            <Field id="un-to" label="To" type="date" value={to} onChange={setTo} required />
          </div>
          <Field id="un-note" label="Note for the office (optional)" value={note} onChange={setNote} />
          <Button type="submit" variant="outline" className="h-11 w-full" disabled={busy}>{busy ? "Saving…" : "Mark as not available"}</Button>
          <p className="text-xs text-muted-foreground">This is not a holiday request. For paid time off, use Leave below.</p>
        </form>
      </PortalCard>
    </PortalSection>
  )
}

const LEAVE_KINDS = [
  ["annual", "Holiday"],
  ["unpaid", "Unpaid leave"],
  ["compassionate", "Compassionate leave"],
  ["other", "Other"],
] as const

const LEAVE_STATUS: Record<string, [tone: "ok" | "wait" | "bad" | "quiet", label: string]> = {
  requested: ["wait", "Waiting"],
  approved: ["ok", "Approved"],
  taken: ["ok", "Taken"],
  declined: ["bad", "Declined"],
  cancelled: ["quiet", "Cancelled"],
}

function Leave() {
  const { data, loading, error, reload } = useApi<PortalLeave>("leave.php")
  const today = londonNow().slice(0, 10)
  const [kind, setKind] = useState<string>("annual")
  const [from, setFrom] = useState(today)
  const [to, setTo] = useState(today)
  const [hours, setHours] = useState("")
  const [reason, setReason] = useState("")
  const [busy, setBusy] = useState(false)

  async function request(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    const ok = await act(
      () => api("leave.php", { method: "POST", body: { kind, starts_on: from, ends_on: to, hours: Number(hours) || 0, reason } }),
      "Leave requested. The office will approve or decline it.",
    )
    setBusy(false)
    if (ok) {
      setReason("")
      setHours("")
      reload()
    }
  }

  return (
    <PortalSection title="Leave" id="leave">
      <PortalState loading={loading} error={error} onRetry={reload} hasData={!!data} rows={2}>
        {data && (
          <>
            <PortalCard>
              <p className="text-sm text-muted-foreground">Holiday this leave year ({data.leave_year})</p>
              {data.balance.entitlement_known ? (
                <p className="mt-1 text-lg font-semibold tabular-nums">{data.balance.remaining ?? 0} hours left
                  <span className="ml-1 text-sm font-normal text-muted-foreground">of {data.balance.entitlement}</span></p>
              ) : (
                <p className="mt-1 text-sm">Your holiday allowance has not been set yet. Ask the office.</p>
              )}
              {data.balance.requested > 0 && <p className="text-sm text-muted-foreground tabular-nums">{data.balance.requested} hours waiting for a decision</p>}
            </PortalCard>
            {data.records.length === 0 ? <Empty>No leave this year.</Empty> : data.records.map((r) => {
              const [tone, label] = LEAVE_STATUS[r.status] ?? ["quiet", r.status]
              return (
                <PortalCard key={r.id} className="flex items-start justify-between gap-3">
                  <div className="min-w-0 text-sm">
                    <p className="font-medium tabular-nums">{dateOnly(r.starts_on)} to {dateOnly(r.ends_on)}</p>
                    <p className="text-muted-foreground">
                      {(LEAVE_KINDS.find(([k]) => k === r.kind)?.[1] ?? r.kind.replace("_", " "))}{r.hours ? `, ${r.hours} hours` : ""}
                    </p>
                  </div>
                  <Chip tone={tone}>{label}</Chip>
                </PortalCard>
              )
            })}
          </>
        )}
      </PortalState>
      <PortalCard>
        <form className="space-y-3" onSubmit={request}>
          <div className="space-y-1">
            <Label htmlFor="lv-kind">Type</Label>
            <select id="lv-kind" className={fieldClass} value={kind} onChange={(e) => setKind(e.target.value)}>
              {LEAVE_KINDS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field id="lv-from" label="First day" type="date" value={from} onChange={(v) => { setFrom(v); if (to < v) setTo(v) }} required />
            <Field id="lv-to" label="Last day" type="date" value={to} onChange={setTo} required />
          </div>
          <Field id="lv-hours" label="Hours (optional)" inputMode="numeric" value={hours} onChange={setHours} />
          <Field id="lv-reason" label="Note for the office (optional)" value={reason} onChange={setReason} />
          <Button type="submit" className="h-11 w-full" disabled={busy}>{busy ? "Sending…" : "Request leave"}</Button>
          <p className="text-xs text-muted-foreground">Off sick? Call the office instead, so your shifts can be covered.</p>
        </form>
      </PortalCard>
    </PortalSection>
  )
}
