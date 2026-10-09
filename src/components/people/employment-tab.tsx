import { useState } from "react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { api, ApiError } from "@/lib/api"
import { gbp } from "@/lib/format"
import { usePostAction } from "@/hooks/use-post-action"
import { CONTRACT_LABEL, dateOnly, isRestricted } from "@/lib/people"
import type { PersonProfile, ProfileContract } from "@/lib/types"
import { Fact, FileLink, RestrictedBlock, Section, UploadButton } from "@/components/people/shared"
import { ReasonDialog } from "@/components/people/action-dialogs"

const ENGAGEMENTS = [
  ["worker", "Worker"], ["employee", "Employee"], ["bank", "Bank"], ["agency", "Agency"],
  ["self_employed", "Self-employed"], ["apprentice", "Apprentice"],
] as const

/**
 * Contract evidence, pay rate history and leave.
 *
 * Contracts are prepared and signed outside the system. What is kept here is
 * the record: the agreed version, when it was sent, when it was accepted, the
 * signed copy, and who reviewed it. Superseded versions are kept, never edited.
 */
export function EmploymentTab({ profile, onChanged }: { profile: PersonProfile; onChanged: () => void }) {
  return (
    <div className="space-y-4">
      <ContractsSection profile={profile} onChanged={onChanged} />
      <PayRatesSection profile={profile} />
      <LeaveSection profile={profile} onChanged={onChanged} />
    </div>
  )
}

function ContractsSection({ profile, onChanged }: { profile: PersonProfile; onChanged: () => void }) {
  const contracts = profile.employment.contracts
  const [drafting, setDrafting] = useState(false)
  if (isRestricted(contracts)) return <Section title="Contract"><RestrictedBlock what="contract records" /></Section>

  const current = contracts.filter((c) => c.status !== "superseded")
  const earlier = contracts.filter((c) => c.status === "superseded")
  const canDraft = profile.can.manage && profile.workers.length > 0

  return (
    <Section
      title="Contract"
      description="The agreed version, sent, accepted, signed and reviewed. Approval needs a signed contract that has been reviewed."
      action={canDraft && current.length === 0 ? <Button size="sm" onClick={() => setDrafting(true)}>Draft contract</Button> : undefined}
    >
      {contracts.length === 0 && (
        <p className="text-sm text-muted-foreground">
          {profile.workers.length === 0 ? "A contract is drafted once onboarding has started." : "No contract yet."}
        </p>
      )}
      {current.map((c) => (
        <ContractCard key={c.id} contract={c} profile={profile} onChanged={onChanged} />
      ))}
      {earlier.length > 0 && (
        <details className="rounded-md border border-border p-3 text-sm">
          <summary className="cursor-pointer font-medium">Earlier versions ({earlier.length})</summary>
          <ul className="mt-2 space-y-2">
            {earlier.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center justify-between gap-2">
                <span>{c.reference}, version {c.version}{c.signed_at ? `, signed ${dateOnly(c.signed_at)}` : ""}</span>
                <FileLink id={c.signed_file_id ?? c.document_file_id} name={c.signed_file_name ?? c.document_file_name} />
              </li>
            ))}
          </ul>
        </details>
      )}
      <DraftContractDialog open={drafting} onOpenChange={setDrafting} profile={profile} onChanged={onChanged} />
    </Section>
  )
}

function ContractCard({ contract: c, profile, onChanged }: { contract: ProfileContract; profile: PersonProfile; onChanged: () => void }) {
  const { run, busy } = usePostAction(onChanged)
  const [signing, setSigning] = useState(false)
  const [reviewing, setReviewing] = useState(false)
  const manage = profile.can.manage
  const can = (to: string) => manage && c.next.includes(to as ProfileContract["status"])
  const personId = profile.person.id

  const stateLine = c.status === "signed"
    ? (c.reviewed_at ? `Signed and reviewed` : "Signed, waiting for review")
    : CONTRACT_LABEL[c.status]

  return (
    <div className="space-y-3 rounded-md border border-border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-medium">{c.reference}, version {c.version}{c.job_title ? `: ${c.job_title}` : ""}</p>
        <span className="rounded-full border border-border bg-muted px-2 py-0.5 text-xs font-medium">{stateLine}</span>
      </div>
      <dl className="grid gap-3 sm:grid-cols-3">
        <Fact label="Engagement"><span className="capitalize">{c.engagement_type.replace("_", " ")}</span></Fact>
        <Fact label="Start date">{c.start_date ? dateOnly(c.start_date) : "Not set"}</Fact>
        <Fact label="Pay">{c.pay_rate_pence !== null ? `${gbp(c.pay_rate_pence)}${c.pay_frequency ? ` ${c.pay_frequency}` : ""}` : "Not shown"}</Fact>
        <Fact label="Agreed version"><FileLink id={c.document_file_id} name={c.document_file_name} /></Fact>
        <Fact label="Sent">{c.sent_at ? dateOnly(c.sent_at) : "Not yet"}</Fact>
        <Fact label="Accepted">{c.accepted_at ? dateOnly(c.accepted_at) : "Not yet"}</Fact>
        <Fact label="Signed copy">
          {c.signed_file_id ? <FileLink id={c.signed_file_id} name={c.signed_file_name} /> : "Not yet"}
          {c.signed_name && <span className="block text-xs text-muted-foreground">Signed by {c.signed_name}, {dateOnly(c.signed_at)}</span>}
        </Fact>
        <Fact label="Reviewed">
          {c.reviewed_at ? `${c.reviewed_by_name ?? "Somebody"}, ${dateOnly(c.reviewed_at)}` : "Not yet"}
          {c.review_note && <span className="block text-xs text-muted-foreground">{c.review_note}</span>}
        </Fact>
      </dl>

      {manage && (
        <div className="flex flex-wrap gap-2 border-t border-border pt-3">
          {c.status === "draft" && (
            <UploadButton personId={personId} category="contract" label={c.document_file_id ? "Replace agreed version" : "Upload agreed version"}
              onUploaded={(fileId) => { void run(`employment-contracts.php?action=document&id=${c.id}`, { document_file_id: fileId }, "Agreed version attached.") }} />
          )}
          {can("sent") && <Button size="sm" disabled={busy} onClick={() => run(`employment-contracts.php?action=sent&id=${c.id}`, {}, "Recorded as sent.")}>Mark sent</Button>}
          {can("accepted") && <Button size="sm" variant="outline" disabled={busy} onClick={() => run(`employment-contracts.php?action=accepted&id=${c.id}`, {}, "Acceptance recorded.")}>Record acceptance</Button>}
          {can("signed") && <Button size="sm" disabled={busy} onClick={() => setSigning(true)}>Attach signed copy</Button>}
          {c.status === "signed" && !c.reviewed_at && <Button size="sm" disabled={busy} onClick={() => setReviewing(true)}>Review contract</Button>}
          {can("superseded") && (
            <Button size="sm" variant="outline" disabled={busy}
              onClick={() => run(`employment-contracts.php?action=supersede&id=${c.id}`, {}, "New version drafted. The old one is kept.")}>
              New version
            </Button>
          )}
        </div>
      )}

      <SignedCopyDialog open={signing} onOpenChange={setSigning} contract={c} profile={profile} onChanged={onChanged} />
      <ReasonDialog
        open={reviewing}
        onOpenChange={setReviewing}
        title="Review the signed contract"
        description="Confirm you have read the signed copy and it matches what was agreed. Your name is recorded as the reviewer."
        label="Note (optional)"
        confirm="Mark reviewed"
        optional
        busy={busy}
        onConfirm={(note) => run(`employment-contracts.php?action=review&id=${c.id}`, { note }, "Contract reviewed.")}
      />
    </div>
  )
}

function SignedCopyDialog({ open, onOpenChange, contract, profile, onChanged }: {
  open: boolean
  onOpenChange: (o: boolean) => void
  contract: ProfileContract
  profile: PersonProfile
  onChanged: () => void
}) {
  const { run, busy } = usePostAction(onChanged)
  const [name, setName] = useState(profile.person.full_name)
  const [fileId, setFileId] = useState<number | null>(null)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Attach the signed copy</DialogTitle>
          <DialogDescription>Upload the copy signed outside the system and record who signed it.</DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="signed-name">Signed by</Label>
            <Input id="signed-name" value={name} maxLength={160} onChange={(e) => setName(e.target.value)} />
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <UploadButton personId={profile.person.id} category="contract" label={fileId ? "Replace file" : "Upload signed copy"} onUploaded={(id) => setFileId(id)} />
            {fileId && <FileLink id={fileId} name="Signed copy" />}
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button
            disabled={busy || fileId === null || name.trim() === ""}
            onClick={async () => {
              if (await run(`employment-contracts.php?action=signed&id=${contract.id}`, { signed_file_id: fileId, signed_name: name.trim() }, "Signed copy recorded.")) {
                setFileId(null)
                onOpenChange(false)
              }
            }}
          >
            Record signature
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function DraftContractDialog({ open, onOpenChange, profile, onChanged }: {
  open: boolean
  onOpenChange: (o: boolean) => void
  profile: PersonProfile
  onChanged: () => void
}) {
  const [engagement, setEngagement] = useState("worker")
  const [jobTitle, setJobTitle] = useState("")
  const [start, setStart] = useState("")
  const [rate, setRate] = useState("")
  const [fileId, setFileId] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      await api("employment-contracts.php", {
        method: "POST",
        body: {
          person_id: profile.person.id,
          engagement_type: engagement,
          job_title: jobTitle,
          start_date: start,
          // Pounds typed, pence stored. Money is never a float on the server.
          pay_rate_pence: rate === "" ? null : Math.round(Number(rate) * 100),
          pay_frequency: rate === "" ? "" : "hourly",
          document_file_id: fileId,
        },
      })
      toast.success("Contract drafted.")
      onOpenChange(false)
      onChanged()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not draft the contract.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Draft a contract</DialogTitle>
          <DialogDescription>Record the terms. The document itself is prepared outside the system and uploaded here.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="c-engagement">Engagement</Label>
            <Select value={engagement} onValueChange={setEngagement}>
              <SelectTrigger id="c-engagement"><SelectValue /></SelectTrigger>
              <SelectContent>{ENGAGEMENTS.map(([v, l]) => <SelectItem key={v} value={v}>{l}</SelectItem>)}</SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="c-title">Job title</Label>
            <Input id="c-title" maxLength={120} value={jobTitle} onChange={(e) => setJobTitle(e.target.value)} />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="c-start">Start date</Label>
              <Input id="c-start" type="date" value={start} onChange={(e) => setStart(e.target.value)} />
            </div>
            {profile.zones.payroll && (
              <div className="space-y-1.5">
                <Label htmlFor="c-rate">Hourly rate (£)</Label>
                <Input id="c-rate" type="number" min="0" step="0.01" inputMode="decimal" value={rate} onChange={(e) => setRate(e.target.value)} />
              </div>
            )}
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <UploadButton personId={profile.person.id} category="contract" label={fileId ? "Replace agreed version" : "Upload agreed version"} onUploaded={(id) => setFileId(id)} />
            {fileId && <FileLink id={fileId} name="Agreed version" />}
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" disabled={busy}>{busy ? "Saving…" : "Draft contract"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function PayRatesSection({ profile }: { profile: PersonProfile }) {
  const rates = profile.employment.pay_rates
  return (
    <Section title="Pay rate history" description="Read only. Each rate applies from its date until the next one.">
      {isRestricted(rates) ? (
        <RestrictedBlock what="pay information" />
      ) : rates.length === 0 ? (
        <p className="text-sm text-muted-foreground">No rate recorded.</p>
      ) : (
        <ul className="divide-y divide-border">
          {rates.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
              <span className="tabular-nums font-medium">{gbp(r.rate_pence)} an hour <span className="font-normal uppercase text-muted-foreground">{r.pay_type}</span></span>
              <span className="text-muted-foreground">From {dateOnly(r.effective_from)}{r.set_by_name ? `, set by ${r.set_by_name}` : ""}{r.note ? `. ${r.note}` : ""}</span>
            </li>
          ))}
        </ul>
      )}
    </Section>
  )
}

function LeaveSection({ profile, onChanged }: { profile: PersonProfile; onChanged: () => void }) {
  const { run, busy } = usePostAction(onChanged)
  const { balance, records, leave_year } = profile.employment.leave
  const hours = (n: number | null) => (n === null ? "Not set" : `${n} h`)

  return (
    <Section title={`Leave, ${leave_year}`} description="Counted in hours. Requests are decided here.">
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Fact label="Entitlement">{balance.entitlement_known ? hours(balance.entitlement) : "Not set"}</Fact>
        <Fact label="Taken">{hours(balance.taken)}</Fact>
        <Fact label="Approved, to come">{hours(balance.approved)}</Fact>
        <Fact label="Remaining">{hours(balance.remaining)}</Fact>
      </dl>
      {records.length === 0 ? (
        <p className="text-sm text-muted-foreground">No leave recorded this year.</p>
      ) : (
        <ul className="divide-y divide-border">
          {records.map((l) => (
            <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
              <span>
                <span className="font-medium capitalize">{l.kind.replace("_", " ")}</span>{" "}
                {dateOnly(l.starts_on)} to {dateOnly(l.ends_on)}, {l.hours} h
                {l.reason && <span className="block text-xs text-muted-foreground">{l.reason}</span>}
                {l.health_redacted && <span className="block text-xs text-muted-foreground">Reason withheld: health information.</span>}
              </span>
              {l.status === "requested" && profile.can.manage ? (
                <span className="flex gap-2">
                  <Button size="sm" disabled={busy} onClick={() => run(`leave.php?action=decide&id=${l.id}`, { status: "approved" }, "Leave approved.")}>Approve</Button>
                  <Button size="sm" variant="outline" disabled={busy} onClick={() => run(`leave.php?action=decide&id=${l.id}`, { status: "declined" }, "Leave declined.")}>Decline</Button>
                </span>
              ) : (
                <span className="capitalize text-muted-foreground">{l.status}</span>
              )}
            </li>
          ))}
        </ul>
      )}
    </Section>
  )
}
