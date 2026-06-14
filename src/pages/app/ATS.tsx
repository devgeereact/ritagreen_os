import { useState } from "react"
import { CheckCircle2 } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from "@/components/ui/table"
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from "@/components/ui/dialog"
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import type { Applicant, OnboardingTask } from "@/lib/types"
import { shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const STAGES = ["applied", "screened", "interview", "vetting", "offer", "onboarded", "rejected"]
const STAGE_VARIANT: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  applied: "outline", screened: "secondary", interview: "secondary",
  vetting: "secondary", offer: "default", onboarded: "default", rejected: "destructive",
}

export default function ATS() {
  const { data, loading, error, reload } = useApi<{ applicants: Applicant[] }>("ats.php")
  const [openId, setOpenId] = useState<number | null>(null)

  async function setStage(id: number, stage: string) {
    try {
      await api(`ats.php?action=stage&id=${id}`, { method: "POST", body: { stage } })
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update.")
    }
  }

  const applicants = data?.applicants ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Recruitment & onboarding</h1>
        <p className="text-sm text-muted-foreground">Applicants from the careers page and the academy. Onboard to create a worker.</p>
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        applicants.length === 0 ? (
          <EmptyBlock title="No applicants yet" hint="Applications from /apply and academy hand-offs land here." />
        ) : (
          <Card>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Applicant</TableHead>
                  <TableHead>Role</TableHead>
                  <TableHead>Division</TableHead>
                  <TableHead>Source</TableHead>
                  <TableHead>Stage</TableHead>
                  <TableHead></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {applicants.map((a) => (
                  <TableRow key={a.id}>
                    <TableCell>
                      <button className="font-medium text-primary hover:underline" onClick={() => setOpenId(a.id)}>{a.name}</button>
                      <div className="text-xs text-muted-foreground">{a.email} · {shortDate(a.created_at)}</div>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{a.role_applied || "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{DIVISION_LABELS[a.division] ?? a.division}</TableCell>
                    <TableCell className="capitalize text-muted-foreground">{a.source || "—"}</TableCell>
                    <TableCell>
                      <Select value={a.stage} onValueChange={(v) => setStage(a.id, v)}>
                        <SelectTrigger className="h-8 w-32"><SelectValue /></SelectTrigger>
                        <SelectContent>{STAGES.map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}</SelectContent>
                      </Select>
                    </TableCell>
                    <TableCell>
                      <Badge variant={STAGE_VARIANT[a.stage]} className="capitalize">{a.stage}</Badge>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        )
      )}

      <ApplicantDialog id={openId} onOpenChange={(o) => !o && setOpenId(null)} onChanged={reload} />
    </div>
  )
}

function ApplicantDialog({ id, onOpenChange, onChanged }: { id: number | null; onOpenChange: (o: boolean) => void; onChanged: () => void }) {
  const { data, loading, reload } = useApi<{ applicant: Applicant; tasks: OnboardingTask[] }>(id ? `ats.php?id=${id}` : null, [id])
  const [newTask, setNewTask] = useState("")
  const [busy, setBusy] = useState(false)

  async function toggleTask(taskId: number, done: boolean) {
    await api(`ats.php?action=task_done&task_id=${taskId}`, { method: "POST", body: { done } }).catch(() => {})
    reload()
  }
  async function addTask() {
    if (!id || !newTask.trim()) return
    await api(`ats.php?action=add_task&id=${id}`, { method: "POST", body: { label: newTask.trim() } }).catch(() => {})
    setNewTask("")
    reload()
  }
  async function onboard() {
    if (!id) return
    setBusy(true)
    try {
      await api(`ats.php?action=onboard&id=${id}`, { method: "POST", body: {} })
      toast.success("Worker created from applicant.")
      reload(); onChanged()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not onboard.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={!!id} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] overflow-y-auto">
        {loading && <LoadingBlock rows={3} />}
        {data && (
          <>
            <DialogHeader>
              <DialogTitle>{data.applicant.name}</DialogTitle>
              <DialogDescription>
                {data.applicant.email} · {data.applicant.phone || "no phone"} · {DIVISION_LABELS[data.applicant.division] ?? data.applicant.division}
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-4">
              <div className="flex items-center gap-2">
                <Badge variant={STAGE_VARIANT[data.applicant.stage]} className="capitalize">{data.applicant.stage}</Badge>
                {data.applicant.worker_id && <Badge variant="default">Worker #{data.applicant.worker_id}</Badge>}
              </div>

              <div>
                <h3 className="mb-2 text-sm font-semibold">Onboarding checklist</h3>
                {data.tasks.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No tasks yet. Onboarding seeds a default checklist.</p>
                ) : (
                  <ul className="space-y-1.5">
                    {data.tasks.map((t) => (
                      <li key={t.id} className="flex items-center gap-2 text-sm">
                        <Checkbox checked={!!t.done} onCheckedChange={(v) => toggleTask(t.id, !!v)} />
                        <span className={t.done ? "text-muted-foreground line-through" : ""}>{t.label}</span>
                      </li>
                    ))}
                  </ul>
                )}
                <div className="mt-2 flex gap-2">
                  <Input value={newTask} onChange={(e) => setNewTask(e.target.value)} placeholder="Add a task" className="h-8" />
                  <Button size="sm" variant="outline" onClick={addTask}>Add</Button>
                </div>
              </div>

              {!data.applicant.worker_id && (
                <Button onClick={onboard} disabled={busy} className="w-full">
                  <CheckCircle2 className="mr-1 size-4" /> {busy ? "Onboarding…" : "Onboard → create worker"}
                </Button>
              )}
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
