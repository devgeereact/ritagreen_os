import { useState } from "react"
import { Link } from "react-router-dom"
import { personPath } from "@/lib/people"
import { CalendarClock, CheckCircle2, UserRound } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from "@/components/ui/table"
import {
  Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle,
} from "@/components/ui/dialog"
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import type {
  Applicant, ApplicantInterview, ApplicantStageChange, AssignableStaff, NextStage, OnboardingTask,
} from "@/lib/types"
import { shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const STAGE_VARIANT: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  applied: "outline", screened: "secondary", interview: "secondary",
  vetting: "secondary", offer: "default", onboarding: "secondary",
  onboarded: "default", rejected: "destructive", withdrawn: "destructive",
}

const OUTCOME_VARIANT: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  scheduled: "outline", passed: "default", failed: "destructive",
  no_show: "destructive", cancelled: "secondary",
}

const OUTCOME_LABELS: Record<string, string> = {
  scheduled: "Scheduled", passed: "Passed", failed: "Failed",
  no_show: "Did not attend", cancelled: "Cancelled",
}

/** "07:00 on 1 Oct 2026" from a wall-clock DATETIME, without shifting the hour. */
function whenLabel(value: string) {
  const [date, time = ""] = value.replace("T", " ").split(" ")
  return `${shortDate(date)}${time ? ` at ${time.slice(0, 5)}` : ""}`
}

type ApplicantDetail = {
  applicant: Applicant
  tasks: OnboardingTask[]
  interviews: ApplicantInterview[]
  history: ApplicantStageChange[]
  next_stages: NextStage[]
}

export default function ATS() {
  const { data, loading, error, reload } = useApi<{ applicants: Applicant[] }>("ats.php")
  const [openId, setOpenId] = useState<number | null>(null)

  const applicants = data?.applicants ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Recruitment &amp; onboarding</h1>
        <p className="text-sm text-muted-foreground">
          Applications from the careers page, linked forms and the academy. Open a candidate to
          move them on, book an interview or start onboarding.
        </p>
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
                      {/*
                        A badge, not a dropdown. The stage used to be a select listing
                        every value, so a candidate could be sent from "applied" to
                        "onboarded" in one click with no worker record, no checklist and
                        no approval behind it. Moving a candidate now happens in the
                        detail, where the server says which moves are open and a reason
                        can be given for the ones that need one.
                      */}
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
  const { data, loading, reload } = useApi<ApplicantDetail>(id ? `ats.php?id=${id}` : null, [id])
  const { data: staffData } = useApi<{ staff: AssignableStaff[] }>(id ? "users.php?action=assignable" : null, [id])
  const [busy, setBusy] = useState(false)

  const staff = staffData?.staff ?? []

  function refresh() { reload(); onChanged() }

  /** Every write goes through here so a refusal is always shown, never swallowed. */
  async function run(path: string, body: unknown, success?: string) {
    setBusy(true)
    try {
      await api(path, { method: "POST", body })
      if (success) toast.success(success)
      refresh()
      return true
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not save that.")
      return false
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={!!id} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
        {/*
          The header is rendered even while the candidate is loading. It used to
          sit inside the `data &&` branch, so for as long as the fetch was in
          flight the dialog had no title and no description — which Radix reports
          as an error, and which reaches a screen reader as an unnamed dialog.
        */}
        <DialogHeader>
          <DialogTitle>{data ? data.applicant.name : "Loading candidate…"}</DialogTitle>
          <DialogDescription>
            {data
              ? `${data.applicant.email} · ${data.applicant.phone || "no phone"} · ${DIVISION_LABELS[data.applicant.division] ?? data.applicant.division}`
              : "Fetching this candidate's record."}
          </DialogDescription>
        </DialogHeader>

        {loading && <LoadingBlock rows={4} />}
        {data && (
          <>
            <div className="space-y-6">
              <div className="flex flex-wrap items-center gap-2">
                <Badge variant={STAGE_VARIANT[data.applicant.stage]} className="capitalize">{data.applicant.stage}</Badge>
                {data.applicant.worker_id && <Badge variant="default">Worker #{data.applicant.worker_id}</Badge>}
                {data.applicant.role_applied && <span className="text-sm text-muted-foreground">{data.applicant.role_applied}</span>}
                {data.applicant.person_id && (
                  <Link to={personPath(data.applicant.person_id)} className="ml-auto text-sm font-medium text-primary underline-offset-2 hover:underline">
                    Open profile
                  </Link>
                )}
              </div>

              {data.applicant.outcome_reason && (
                <p className="rounded-md bg-muted px-3 py-2 text-sm">
                  <span className="font-medium">Left the pipeline:</span> {data.applicant.outcome_reason}
                </p>
              )}

              <RecruiterField
                applicant={data.applicant}
                staff={staff}
                busy={busy}
                onSet={(recruiterId) => run(`ats.php?action=recruiter&id=${data.applicant.id}`, { recruiter_id: recruiterId }, "Recruiter updated.")}
              />

              <StageField
                current={data.applicant.stage}
                options={data.next_stages}
                busy={busy}
                onMove={(stage, reason) => run(`ats.php?action=stage&id=${data.applicant.id}`, { stage, reason }, `Moved to ${stage}.`)}
              />

              <Interviews
                interviews={data.interviews}
                staff={staff}
                busy={busy}
                onBook={(body) => run(`ats.php?action=interview&id=${data.applicant.id}`, body, "Interview booked.")}
                onOutcome={(interviewId, outcome) => run(`ats.php?action=interview_outcome&interview_id=${interviewId}`, { outcome }, "Outcome recorded.")}
              />

              <Checklist
                tasks={data.tasks}
                staff={staff}
                busy={busy}
                onAdd={(body) => run(`ats.php?action=add_task&id=${data.applicant.id}`, body)}
                onToggle={(taskId, done) => run(`ats.php?action=task_done&task_id=${taskId}`, { done })}
                onAssign={(taskId, ownerId, dueOn) => run(`ats.php?action=task_assign&task_id=${taskId}`, { owner_id: ownerId, due_on: dueOn })}
              />

              <History history={data.history} />

              {!data.applicant.worker_id && (
                <Button
                  onClick={() => run(`ats.php?action=onboard&id=${data.applicant.id}`, {}, "Onboarding started — worker created.")}
                  disabled={busy}
                  className="w-full"
                >
                  <CheckCircle2 className="mr-1 size-4" />
                  {busy ? "Working…" : "Start onboarding → create worker"}
                </Button>
              )}
              {!data.applicant.worker_id && (
                <p className="-mt-4 text-xs text-muted-foreground">
                  This creates a pending worker and an onboarding checklist. It does not make them
                  active: that needs the checklist finished and an approval.
                </p>
              )}
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}

function RecruiterField({ applicant, staff, busy, onSet }: {
  applicant: Applicant
  staff: AssignableStaff[]
  busy: boolean
  onSet: (recruiterId: number | null) => void
}) {
  return (
    <section className="space-y-2">
      <h3 className="flex items-center gap-1.5 text-sm font-semibold">
        <UserRound className="size-4" /> Recruiter
      </h3>
      <Select
        value={applicant.recruiter_id ? String(applicant.recruiter_id) : "none"}
        onValueChange={(v) => onSet(v === "none" ? null : Number(v))}
        disabled={busy}
      >
        <SelectTrigger className="h-9"><SelectValue placeholder="Nobody yet" /></SelectTrigger>
        <SelectContent>
          <SelectItem value="none">Nobody yet</SelectItem>
          {staff.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
        </SelectContent>
      </Select>
    </section>
  )
}

function StageField({ current, options, busy, onMove }: {
  current: string
  options: NextStage[]
  busy: boolean
  onMove: (stage: string, reason: string) => Promise<boolean>
}) {
  const [stage, setStage] = useState("")
  const [reason, setReason] = useState("")

  const chosen = options.find((o) => o.stage === stage)
  const needsReason = !!chosen?.needs_reason

  return (
    <section className="space-y-2">
      <h3 className="text-sm font-semibold">Move to another stage</h3>
      {options.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          There is nowhere to move this candidate from <span className="font-medium">{current}</span>.
        </p>
      ) : (
        <div className="space-y-2">
          <Select value={stage} onValueChange={(v) => { setStage(v); setReason("") }} disabled={busy}>
            <SelectTrigger className="h-9"><SelectValue placeholder="Choose a stage" /></SelectTrigger>
            <SelectContent>
              {options.map((o) => (
                <SelectItem key={o.stage} value={o.stage} className="capitalize">
                  {o.stage}{o.needs_reason ? " — needs a reason" : ""}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {needsReason && (
            <div className="space-y-1">
              <Label htmlFor="stage-reason" className="text-xs">Reason</Label>
              <Textarea
                id="stage-reason"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Why is this candidate moving?"
                rows={2}
              />
            </div>
          )}
          <Button
            size="sm"
            variant="outline"
            disabled={busy || !stage || (needsReason && !reason.trim())}
            onClick={async () => {
              if (await onMove(stage, reason.trim())) { setStage(""); setReason("") }
            }}
          >
            Move
          </Button>
          {/*
            Only the moves the server will accept are offered. The rules live in
            rg_stage_transition() in server/pure.php and are sent with the record,
            so this screen cannot drift out of step with what is enforced.
          */}
          <p className="text-xs text-muted-foreground">
            A candidate moves forward one stage at a time. Onboarding and onboarded are set by
            starting onboarding and by approving activation, not from here.
          </p>
        </div>
      )}
    </section>
  )
}

function Interviews({ interviews, staff, busy, onBook, onOutcome }: {
  interviews: ApplicantInterview[]
  staff: AssignableStaff[]
  busy: boolean
  onBook: (body: Record<string, unknown>) => Promise<boolean>
  onOutcome: (interviewId: number, outcome: string) => void
}) {
  const [when, setWhen] = useState("")
  const [interviewer, setInterviewer] = useState("none")
  const [location, setLocation] = useState("")

  return (
    <section className="space-y-2">
      <h3 className="flex items-center gap-1.5 text-sm font-semibold">
        <CalendarClock className="size-4" /> Interviews
      </h3>

      {interviews.length === 0 ? (
        <p className="text-sm text-muted-foreground">None booked.</p>
      ) : (
        <ul className="space-y-2">
          {interviews.map((i) => (
            <li key={i.id} className="rounded-md border p-2.5 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-medium">{whenLabel(i.scheduled_at)}</span>
                <Badge variant={OUTCOME_VARIANT[i.outcome]}>{OUTCOME_LABELS[i.outcome] ?? i.outcome}</Badge>
              </div>
              <div className="text-xs text-muted-foreground">
                {i.interviewer_name ?? "No interviewer named"}
                {i.location ? ` · ${i.location}` : ""}
              </div>
              {i.notes && <p className="mt-1 text-xs">{i.notes}</p>}
              <div className="mt-2">
                <Select value={i.outcome} onValueChange={(v) => onOutcome(i.id, v)} disabled={busy}>
                  <SelectTrigger className="h-8 w-48"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {Object.entries(OUTCOME_LABELS).map(([value, label]) => (
                      <SelectItem key={value} value={value}>{label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </li>
          ))}
        </ul>
      )}

      <div className="grid gap-2 sm:grid-cols-2">
        <div className="space-y-1">
          <Label htmlFor="interview-when" className="text-xs">Date and time</Label>
          <Input
            id="interview-when"
            type="datetime-local"
            value={when}
            onChange={(e) => setWhen(e.target.value)}
            className="h-9"
          />
        </div>
        <div className="space-y-1">
          <Label htmlFor="interview-who" className="text-xs">Interviewer</Label>
          <Select value={interviewer} onValueChange={setInterviewer} disabled={busy}>
            <SelectTrigger id="interview-who" className="h-9"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="none">Not decided</SelectItem>
              {staff.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1 sm:col-span-2">
          <Label htmlFor="interview-where" className="text-xs">Where</Label>
          <Input
            id="interview-where"
            value={location}
            onChange={(e) => setLocation(e.target.value)}
            placeholder="Head office, Teams, by phone…"
            className="h-9"
          />
        </div>
      </div>
      <Button
        size="sm"
        variant="outline"
        disabled={busy || !when}
        onClick={async () => {
          const ok = await onBook({
            scheduled_at: when.replace("T", " "),
            interviewer_id: interviewer === "none" ? null : Number(interviewer),
            location: location.trim(),
          })
          if (ok) { setWhen(""); setInterviewer("none"); setLocation("") }
        }}
      >
        Book interview
      </Button>
    </section>
  )
}

function Checklist({ tasks, staff, busy, onAdd, onToggle, onAssign }: {
  tasks: OnboardingTask[]
  staff: AssignableStaff[]
  busy: boolean
  onAdd: (body: Record<string, unknown>) => Promise<boolean>
  onToggle: (taskId: number, done: boolean) => void
  onAssign: (taskId: number, ownerId: number | null, dueOn: string | null) => void
}) {
  const [label, setLabel] = useState("")
  const [owner, setOwner] = useState("none")
  const [due, setDue] = useState("")

  const today = new Date().toISOString().slice(0, 10)

  return (
    <section className="space-y-2">
      <h3 className="text-sm font-semibold">Onboarding checklist</h3>
      {tasks.length === 0 ? (
        <p className="text-sm text-muted-foreground">No tasks yet. Starting onboarding seeds a default checklist.</p>
      ) : (
        <ul className="space-y-1.5">
          {tasks.map((t) => {
            const overdue = !t.done && !!t.due_on && t.due_on < today
            return (
              <li key={t.id} className="flex flex-wrap items-center gap-2 text-sm">
                <Checkbox checked={!!t.done} disabled={busy} onCheckedChange={(v) => onToggle(t.id, !!v)} />
                <span className={t.done ? "text-muted-foreground line-through" : ""}>{t.label}</span>
                {t.owner_name && <Badge variant="outline">{t.owner_name}</Badge>}
                {t.due_on && (
                  <Badge variant={overdue ? "destructive" : "secondary"}>
                    {overdue ? "Overdue " : "Due "}{shortDate(t.due_on)}
                  </Badge>
                )}
                <Select
                  value={t.owner_id ? String(t.owner_id) : "none"}
                  onValueChange={(v) => onAssign(t.id, v === "none" ? null : Number(v), t.due_on)}
                  disabled={busy}
                >
                  <SelectTrigger className="h-7 w-36 text-xs"><SelectValue placeholder="Nobody" /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Nobody</SelectItem>
                    {staff.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </li>
            )
          })}
        </ul>
      )}

      <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto_auto]">
        <Input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="Add a task" className="h-9" />
        <Select value={owner} onValueChange={setOwner} disabled={busy}>
          <SelectTrigger className="h-9 w-36"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="none">Nobody</SelectItem>
            {staff.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
          </SelectContent>
        </Select>
        <Input type="date" value={due} onChange={(e) => setDue(e.target.value)} className="h-9 w-40" aria-label="Due date" />
        <Button
          size="sm"
          variant="outline"
          disabled={busy || !label.trim()}
          onClick={async () => {
            const ok = await onAdd({
              label: label.trim(),
              owner_id: owner === "none" ? null : Number(owner),
              due_on: due || null,
            })
            if (ok) { setLabel(""); setOwner("none"); setDue("") }
          }}
        >
          Add
        </Button>
      </div>
    </section>
  )
}

function History({ history }: { history: ApplicantStageChange[] }) {
  if (history.length === 0) return null
  return (
    <section className="space-y-2">
      <h3 className="text-sm font-semibold">History</h3>
      <ol className="space-y-1.5 text-sm">
        {history.map((h) => (
          <li key={h.id} className="flex flex-wrap items-baseline gap-x-2">
            <span className="capitalize font-medium">
              {h.from_stage ? `${h.from_stage} → ${h.to_stage}` : h.to_stage}
            </span>
            <span className="text-xs text-muted-foreground">
              {shortDate(h.changed_at)} · {h.changed_by_name ?? "automatically"}
            </span>
            {h.reason && <span className="w-full text-xs text-muted-foreground">{h.reason}</span>}
          </li>
        ))}
      </ol>
    </section>
  )
}
