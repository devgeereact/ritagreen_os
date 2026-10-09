import { useState } from "react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { api, ApiError } from "@/lib/api"
import { RTW_METHOD_LABEL, RTW_OUTCOME_LABEL } from "@/lib/people"
import { FileLink, UploadButton } from "@/components/people/shared"

const today = () => new Date().toISOString().slice(0, 10)

/**
 * Record a right-to-work check: how it was done, when, what it showed and when
 * it must be looked at again.
 *
 * Recording and signing off are separate on the server (right-to-work.php),
 * because the person who collects a document is often not the person who
 * reviews it. The tick box does both in one go for the common case where they
 * are the same person, and the sign-off names whoever is signed in.
 */
export function RightToWorkDialog({ personId, personName, open, onOpenChange, onSaved }: {
  personId: number
  personName?: string
  open: boolean
  onOpenChange: (open: boolean) => void
  onSaved: () => void
}) {
  const [method, setMethod] = useState("online_share_code")
  const [outcome, setOutcome] = useState("valid")
  const [checkedOn, setCheckedOn] = useState(today())
  const [recheckDue, setRecheckDue] = useState("")
  const [reference, setReference] = useState("")
  const [notes, setNotes] = useState("")
  const [followUp, setFollowUp] = useState(false)
  const [signOff, setSignOff] = useState(true)
  const [fileId, setFileId] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)

  async function save(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      const res = await api<{ id: number }>("right-to-work.php", {
        method: "POST",
        body: {
          person_id: personId,
          method,
          outcome,
          checked_on: checkedOn,
          recheck_due: recheckDue,
          reference,
          notes,
          follow_up_required: followUp,
          evidence_file_id: fileId,
        },
      })
      if (signOff && outcome !== "pending") {
        await api(`right-to-work.php?action=verify&id=${res.id}`, { method: "POST", body: {} })
        toast.success("Right-to-work check recorded and signed off.")
      } else {
        toast.success("Right-to-work check recorded. It still needs signing off.")
      }
      onOpenChange(false)
      onSaved()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not record the check.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Record a right-to-work check</DialogTitle>
          <DialogDescription>
            {personName ? `${personName}. ` : ""}Say how the check was done and what it showed. An uploaded
            document is not proof on its own that the prescribed check took place.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={save} className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="rtw-method">Method</Label>
              <Select value={method} onValueChange={setMethod}>
                <SelectTrigger id="rtw-method"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {Object.entries(RTW_METHOD_LABEL).map(([v, l]) => <SelectItem key={v} value={v}>{l}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="rtw-outcome">Outcome</Label>
              <Select value={outcome} onValueChange={setOutcome}>
                <SelectTrigger id="rtw-outcome"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {Object.entries(RTW_OUTCOME_LABEL).map(([v, l]) => <SelectItem key={v} value={v}>{l}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="rtw-date">Date checked</Label>
              <Input id="rtw-date" type="date" required value={checkedOn} max={today()} onChange={(e) => setCheckedOn(e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="rtw-recheck">Follow-up date</Label>
              <Input id="rtw-recheck" type="date" value={recheckDue} onChange={(e) => setRecheckDue(e.target.value)} />
              <p className="text-xs text-muted-foreground">When the permission ends or must be checked again. Leave empty for none.</p>
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="rtw-ref">Share code or document reference</Label>
            <Input id="rtw-ref" value={reference} maxLength={190} onChange={(e) => setReference(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="rtw-notes">Notes</Label>
            <Textarea id="rtw-notes" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <UploadButton personId={personId} category="id" label={fileId ? "Replace evidence" : "Upload evidence"} onUploaded={(id) => setFileId(id)} />
            {fileId && <FileLink id={fileId} name="Uploaded evidence" />}
          </div>
          <label className="flex items-start gap-2 text-sm">
            <Checkbox checked={followUp} onCheckedChange={(v) => setFollowUp(v === true)} className="mt-0.5" />
            <span>A follow-up check is required</span>
          </label>
          <label className="flex items-start gap-2 text-sm">
            <Checkbox checked={signOff} onCheckedChange={(v) => setSignOff(v === true)} className="mt-0.5" />
            <span>I reviewed this check and sign it off. Your name is recorded as the reviewer.</span>
          </label>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" disabled={busy}>{busy ? "Saving…" : "Record check"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
