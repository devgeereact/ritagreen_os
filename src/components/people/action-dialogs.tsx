import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"

/**
 * Ask for a reason, then act. Used wherever the server refuses an action
 * without one: rejecting evidence, withdrawing an approval, suspending.
 */
export function ReasonDialog({ open, onOpenChange, title, description, label = "Reason", confirm, destructive, busy, onConfirm, optional }: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description: string
  label?: string
  confirm: string
  destructive?: boolean
  busy?: boolean
  onConfirm: (reason: string) => Promise<boolean> | boolean
  /** A note rather than a reason: may be left empty. */
  optional?: boolean
}) {
  const [reason, setReason] = useState("")

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (await onConfirm(reason.trim())) {
      setReason("")
      onOpenChange(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="reason-text">{label}</Label>
            <Textarea id="reason-text" required={!optional} rows={3} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" variant={destructive ? "destructive" : "default"} disabled={busy || (!optional && reason.trim() === "")}>
              {busy ? "Saving…" : confirm}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/**
 * Sign off a piece of evidence. What was checked is required: "checked the SIA
 * register" and "saw a photocopy" are different assurances, and only this
 * field can tell them apart later.
 */
export function VerifyDialog({ open, onOpenChange, requirement, busy, onConfirm }: {
  open: boolean
  onOpenChange: (open: boolean) => void
  requirement: string
  busy?: boolean
  onConfirm: (body: { method: string; expires_on: string; outcome_note: string }) => Promise<boolean> | boolean
}) {
  const [method, setMethod] = useState("")
  const [expires, setExpires] = useState("")
  const [note, setNote] = useState("")

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (await onConfirm({ method: method.trim(), expires_on: expires, outcome_note: note.trim() })) {
      setMethod("")
      setExpires("")
      setNote("")
      onOpenChange(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Verify {requirement}</DialogTitle>
          <DialogDescription>Your name and today's date are recorded against this check.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="verify-method">What did you check?</Label>
            <Input id="verify-method" required maxLength={190} placeholder="For example: checked the SIA public register"
              value={method} onChange={(e) => setMethod(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="verify-expiry">Expiry date</Label>
            <Input id="verify-expiry" type="date" value={expires} onChange={(e) => setExpires(e.target.value)} />
            <p className="text-xs text-muted-foreground">Leave empty if this evidence does not expire.</p>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="verify-note">Note</Label>
            <Textarea id="verify-note" rows={2} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" disabled={busy || method.trim() === ""}>{busy ? "Saving…" : "Verify"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
