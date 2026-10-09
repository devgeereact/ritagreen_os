import { useState } from "react"
import { Link } from "react-router-dom"
import { CheckCircle2, Circle, ShieldCheck, ShieldOff } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { usePostAction } from "@/hooks/use-post-action"
import { dateOnly, hoursLabel, serviceName, wallClock } from "@/lib/people"
import type { PersonProfile, ProfileWorker } from "@/lib/types"
import { Fact, Section, ServiceTag } from "@/components/people/shared"
import { ReasonDialog } from "@/components/people/action-dialogs"

const ROLE_LABEL: Record<string, string> = {
  carer: "Carer", nurse: "Nurse", cleaner: "Cleaner", guard: "Security officer", trainer: "Trainer",
}

export function OverviewTab({ profile, onChanged }: { profile: PersonProfile; onChanged: () => void }) {
  const p = profile.person
  const address = [p.address_line1, p.address_line2, p.city, p.postcode].filter(Boolean).join(", ")

  return (
    <div className="space-y-4">
      <Section title="Details">
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Fact label="Name">{p.full_name}{p.preferred_name ? ` (${p.preferred_name})` : ""}</Fact>
          <Fact label="Email">{p.email ?? "Not recorded"}</Fact>
          <Fact label="Phone">{p.phone ?? "Not recorded"}</Fact>
          <Fact label="Address">{address || "Not recorded"}</Fact>
          <Fact label="Start date">{p.start_date ? dateOnly(p.start_date) : "No agreed contract yet"}</Fact>
          <Fact label="Payroll number"><span className="tabular-nums">{profile.payroll_no ?? "Not issued"}</span></Fact>
          <Fact label="Next shift">
            {profile.next_shift
              ? `${wallClock(profile.next_shift.starts_at)}, ${profile.next_shift.title}${profile.next_shift.site_name ? ` at ${profile.next_shift.site_name}` : ""} (${hoursLabel(profile.next_shift.minutes)})`
              : "None booked"}
          </Fact>
        </dl>
      </Section>

      <Section title="Services" description="Where this person stands in each service, worked out from their records.">
        {profile.services.length === 0 ? (
          <p className="text-sm text-muted-foreground">Not connected to any service you can see.</p>
        ) : (
          <ul className="divide-y divide-border">
            {profile.services.map((s) => (
              <li key={s.service} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <ServiceTag service={s.service} status={s.status} />
                <span className="text-sm text-muted-foreground">{s.detail}</span>
              </li>
            ))}
          </ul>
        )}
      </Section>

      {profile.workers.map((w) => (
        <WorkerCard key={w.id} worker={w} profile={profile} onChanged={onChanged} />
      ))}

      {profile.applications.map((a) => (
        <ApplicationCard key={a.id} application={a} canManage={profile.can.manage} onChanged={onChanged} />
      ))}
    </div>
  )
}

/** The employment record: approval, and suspension, each a decision with a name against it. */
function WorkerCard({ worker: w, profile, onChanged }: { worker: ProfileWorker; profile: PersonProfile; onChanged: () => void }) {
  const { run, busy } = usePostAction(onChanged)
  const [dialog, setDialog] = useState<null | "unapprove" | "suspend" | "reinstate">(null)
  const [note, setNote] = useState("")
  const blockers = w.approval.blockers
  const canApprove = profile.can.approve && !w.approval.approved && blockers.length === 0
  const evidenceMissing = profile.next_action?.key === "request_evidence"

  return (
    <Section
      title={`${serviceName(w.division)}: ${ROLE_LABEL[w.role] ?? w.role}`}
      description={`Payroll number ${w.payroll_no ?? "not issued"}. Worker record since ${dateOnly(w.created_at)}.`}
    >
      {w.status === "suspended" && (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm">
          <p className="font-medium">Suspended{w.suspended_at ? ` on ${dateOnly(w.suspended_at)}` : ""}{w.suspended_by_name ? ` by ${w.suspended_by_name}` : ""}.</p>
          {w.suspension_reason && <p className="mt-1">{w.suspension_reason}</p>}
          <p className="mt-1 text-muted-foreground">They cannot be put on a shift while suspended.</p>
        </div>
      )}

      {w.approval.approved ? (
        <div className="flex items-start gap-2 text-sm">
          <ShieldCheck className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
          <p>
            Approved for work on {dateOnly(w.approved_at)}
            {w.approved_by_name ? ` by ${w.approved_by_name}` : ", approver not recorded"}.
            {w.approval_note && <span className="text-muted-foreground"> {w.approval_note}</span>}
          </p>
        </div>
      ) : (
        <div className="space-y-2 text-sm">
          <div className="flex items-start gap-2">
            <ShieldOff className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
            <p className="font-medium">Not yet approved for work.</p>
          </div>
          {blockers.length > 0 ? (
            <div>
              <p className="text-muted-foreground">Approval needs these first:</p>
              <ul className="mt-1 list-disc space-y-0.5 pl-5">
                {blockers.map((b) => <li key={b.code}>{b.reason}</li>)}
              </ul>
            </div>
          ) : (
            <p className="text-muted-foreground">
              Onboarding is finished and a signed contract has been reviewed, so approval can be given.
              {evidenceMissing && " Mandatory checks are still missing: they will not be offered shifts until those are in place."}
            </p>
          )}
        </div>
      )}

      {profile.can.approve && (
        <div className="flex flex-wrap items-end gap-2 border-t border-border pt-3">
          {canApprove && (
            <>
              <div className="min-w-[12rem] flex-1">
                <label htmlFor={`approve-note-${w.id}`} className="text-xs text-muted-foreground">Note (optional)</label>
                <Input id={`approve-note-${w.id}`} value={note} maxLength={255} onChange={(e) => setNote(e.target.value)} />
              </div>
              <Button disabled={busy} onClick={() => run(`workers.php?action=approve&id=${w.id}`, { note }, "Approved for work.")}>
                Approve to work
              </Button>
            </>
          )}
          {w.approval.approved && (
            <Button variant="outline" disabled={busy} onClick={() => setDialog("unapprove")}>Withdraw approval</Button>
          )}
          {w.status === "suspended" ? (
            <Button variant="outline" disabled={busy} onClick={() => setDialog("reinstate")}>Lift suspension</Button>
          ) : (
            <Button variant="outline" disabled={busy} onClick={() => setDialog("suspend")}>Suspend</Button>
          )}
        </div>
      )}

      <ReasonDialog
        open={dialog === "unapprove"}
        onOpenChange={(o) => !o && setDialog(null)}
        title="Withdraw approval"
        description="They will not be offered or booked on shifts until approved again."
        confirm="Withdraw approval"
        destructive
        busy={busy}
        onConfirm={(reason) => run(`workers.php?action=unapprove&id=${w.id}`, { reason }, "Approval withdrawn.")}
      />
      <ReasonDialog
        open={dialog === "suspend"}
        onOpenChange={(o) => !o && setDialog(null)}
        title="Suspend"
        description="A suspension stops them being booked. Their approval is kept for when it is lifted."
        confirm="Suspend"
        destructive
        busy={busy}
        onConfirm={(reason) => run(`workers.php?action=suspend&id=${w.id}`, { reason }, "Suspended.")}
      />
      <ReasonDialog
        open={dialog === "reinstate"}
        onOpenChange={(o) => !o && setDialog(null)}
        title="Lift suspension"
        description="Say why the suspension is being lifted."
        confirm="Lift suspension"
        busy={busy}
        onConfirm={(reason) => run(`workers.php?action=reinstate&id=${w.id}`, { reason }, "Suspension lifted.")}
      />
    </Section>
  )
}

/** The application, and the onboarding checklist that hangs off it. */
function ApplicationCard({ application: a, canManage, onChanged }: {
  application: PersonProfile["applications"][number]
  canManage: boolean
  onChanged: () => void
}) {
  const { run, busy } = usePostAction(onChanged)
  const [label, setLabel] = useState("")
  const outstanding = a.tasks.filter((t) => !t.done).length

  return (
    <Section
      title={`${serviceName(a.division)} application`}
      description={`${a.role_applied ?? "Role not given"}. Stage: ${a.stage}. Applied ${dateOnly(a.created_at)}${a.recruiter_name ? `, recruiter ${a.recruiter_name}` : ""}.`}
      action={<Button asChild size="sm" variant="outline"><Link to="/app/ats">Open in Recruitment</Link></Button>}
    >
      {a.outcome_reason && <p className="text-sm"><span className="font-medium">Left the pipeline:</span> {a.outcome_reason}</p>}
      {a.tasks.length > 0 && (
        <div>
          <p className="text-sm font-medium">
            Onboarding checklist {outstanding > 0 ? `(${outstanding} outstanding)` : "(finished)"}
          </p>
          <ul className="mt-2 space-y-1.5">
            {a.tasks.map((t) => (
              <li key={t.id} className="flex items-start gap-2 text-sm">
                {canManage ? (
                  <Checkbox
                    id={`task-${t.id}`}
                    checked={!!t.done}
                    disabled={busy}
                    onCheckedChange={(v) => run(`ats.php?action=task_done&task_id=${t.id}`, { done: v === true })}
                    className="mt-0.5"
                  />
                ) : t.done ? (
                  <CheckCircle2 className="mt-0.5 size-4 text-success" aria-label="Done" />
                ) : (
                  <Circle className="mt-0.5 size-4 text-muted-foreground" aria-label="Outstanding" />
                )}
                <label htmlFor={`task-${t.id}`} className={t.done ? "text-muted-foreground line-through" : ""}>
                  {t.label}
                  {t.owner_name && <span className="text-muted-foreground">, {t.owner_name}</span>}
                  {t.due_on && <span className="text-muted-foreground">, due {dateOnly(t.due_on)}</span>}
                </label>
              </li>
            ))}
          </ul>
        </div>
      )}
      {canManage && a.worker_id !== null && (
        <form
          className="flex flex-wrap gap-2"
          onSubmit={async (e) => {
            e.preventDefault()
            if (await run(`ats.php?action=add_task&id=${a.id}`, { label }, "Task added.")) setLabel("")
          }}
        >
          <Input aria-label="Add a task for this person" placeholder="Add a task for this person" value={label}
            maxLength={160} onChange={(e) => setLabel(e.target.value)} className="min-w-[12rem] flex-1" />
          <Button type="submit" size="sm" variant="outline" disabled={busy || label.trim() === ""}>Add task</Button>
        </form>
      )}
    </Section>
  )
}
