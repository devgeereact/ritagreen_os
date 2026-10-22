import { useState } from "react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { api, ApiError } from "@/lib/api"
import { usePostAction } from "@/hooks/use-post-action"
import { dateOnly, isRestricted, RTW_METHOD_LABEL, RTW_OUTCOME_LABEL, serviceName, VERIFICATION_LABEL, verifierLabel } from "@/lib/people"
import type { PersonProfile, ProfileRequirement } from "@/lib/types"
import { Fact, FileLink, RestrictedBlock, Section, StatusChip, UploadButton } from "@/components/people/shared"
import { ReasonDialog, VerifyDialog } from "@/components/people/action-dialogs"
import { RightToWorkDialog } from "@/components/people/rtw-dialog"

const VERDICT_LABEL: Record<string, string> = {
  eligible: "May work in this service",
  eligible_with_warnings: "May work, with something due soon",
  not_eligible: "May not work in this service yet",
  unassessed: "Not assessed",
}

/**
 * Every requirement for every service the person is connected to, with its
 * status, evidence, verifier, date checked and expiry.
 *
 * The status is calculated by the server from the rules and the evidence. The
 * actions here add evidence or record a decision about it; none of them sets a
 * status, because there is no endpoint that would accept one.
 */
export function ChecksTab({ profile, onChanged, onOpenEmployment }: {
  profile: PersonProfile
  onChanged: () => void
  onOpenEmployment: () => void
}) {
  const { run, busy } = usePostAction(onChanged)
  const [verifying, setVerifying] = useState<ProfileRequirement | null>(null)
  const [rejecting, setRejecting] = useState<ProfileRequirement | null>(null)
  const [rtwOpen, setRtwOpen] = useState(false)
  const checks = profile.checks
  const personId = profile.person.id
  const canManage = profile.can.manage

  if (isRestricted(checks)) return <RestrictedBlock what="checks and documents" />

  /** A document arrived for a requirement: file it against the open check, or open one. */
  async function evidenceArrived(item: ProfileRequirement, fileId: number) {
    try {
      let id = item.open_status === "requested" ? item.open_verification_id : null
      if (id === null) {
        const res = await api<{ id: number }>("verifications.php", {
          method: "POST",
          body: { person_id: personId, requirement_key: item.key, subject_type: "document", evidence_file_id: fileId },
        })
        id = res.id
      }
      await api(`verifications.php?action=received&id=${id}`, { method: "POST", body: { evidence_file_id: fileId } })
      toast.success(`Evidence for ${item.label} received. It now needs verifying.`)
      onChanged()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not record the evidence.")
    }
  }

  const rtw = checks.right_to_work.current

  return (
    <div className="space-y-4">
      {checks.assessments.length === 0 && (
        <Section title="No services yet">
          <p className="text-sm text-muted-foreground">
            This person is not connected to a service, so there is nothing to check them against.
          </p>
        </Section>
      )}

      {checks.assessments.map((a) => (
        <Section
          key={a.service}
          title={`${serviceName(a.service)} checks`}
          description={`${VERDICT_LABEL[a.status] ?? a.status}. ${a.items.length === 0 ? "No requirements are set for this service." : ""}`}
        >
          <ul className="divide-y divide-border">
            {a.items.map((item) => (
              <li key={item.key} className="grid gap-3 py-3 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,2fr)_10rem] sm:items-start">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{item.label}</span>
                    <StatusChip chip={item.chip} label={item.chip_label} />
                  </div>
                  <p className="mt-0.5 text-xs capitalize text-muted-foreground">{item.weight}</p>
                  {/* The engine's reason describes the evidence it counts. Evidence that
                      has arrived and is waiting for a reviewer does not count yet, so
                      say that instead of "no evidence on file". */}
                  {item.open_status === "received" || item.open_status === "under_review" ? (
                    <p className="mt-1 text-xs text-muted-foreground">Evidence received, waiting for a reviewer.</p>
                  ) : item.reason && <p className="mt-1 text-xs text-muted-foreground">{item.reason}</p>}
                  {item.rejection_reason && (
                    <p className="mt-1 text-xs"><span className="font-medium">Rejected:</span> {item.rejection_reason}</p>
                  )}
                </div>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                  <Fact label="Expiry">{item.expiry ? dateOnly(item.expiry) : "None recorded"}</Fact>
                  <Fact label="Checked">{item.checked_on ? dateOnly(item.checked_on) : "Not yet"}</Fact>
                  <Fact label="Verifier">{verifierLabel(item.verified_by, item.evidence_origin)}</Fact>
                  <Fact label="Evidence"><FileLink id={item.evidence_file_id} name={item.evidence_file_name} /></Fact>
                  {item.open_status && (
                    <Fact label="In progress">{VERIFICATION_LABEL[item.open_status]}</Fact>
                  )}
                </dl>
                {canManage && (
                  <div className="flex flex-wrap gap-2 sm:justify-end">
                    {item.key === "right_to_work" ? (
                      <>
                        <Button size="sm" variant="outline" onClick={() => setRtwOpen(true)}>Record check</Button>
                        {rtw && !rtw.verified && rtw.outcome !== "pending" && rtw.outcome !== "invalid" && (
                          <Button size="sm" disabled={busy}
                            onClick={() => run(`right-to-work.php?action=verify&id=${rtw.id}`, {}, "Right-to-work check signed off.")}>
                            Sign off
                          </Button>
                        )}
                      </>
                    ) : item.key === "contract" ? (
                      <Button size="sm" variant="outline" onClick={onOpenEmployment}>Open contract</Button>
                    ) : (
                      <>
                        {(item.open_status === null || item.open_status === "requested") && (
                          <UploadButton personId={personId} category="certificate" label="Upload evidence"
                            onUploaded={(fileId) => evidenceArrived(item, fileId)} />
                        )}
                        {(item.open_status === "received" || item.open_status === "under_review") && (
                          <Button size="sm" disabled={busy} onClick={() => setVerifying(item)}>Verify</Button>
                        )}
                        {item.open_status !== null && (
                          <Button size="sm" variant="outline" disabled={busy} onClick={() => setRejecting(item)}>Reject</Button>
                        )}
                      </>
                    )}
                  </div>
                )}
              </li>
            ))}
          </ul>
        </Section>
      ))}

      <Section
        title="Right to work"
        description="Every check recorded, newest first. The newest one is what counts."
        action={canManage ? <Button size="sm" variant="outline" onClick={() => setRtwOpen(true)}>Record check</Button> : undefined}
      >
        {checks.right_to_work.checks.length === 0 ? (
          <p className="text-sm text-muted-foreground">No right-to-work check is recorded.</p>
        ) : (
          <ul className="divide-y divide-border">
            {checks.right_to_work.checks.map((c) => (
              <li key={c.id} className="grid gap-2 py-3 sm:grid-cols-4">
                <Fact label="Checked">{dateOnly(c.checked_on)}</Fact>
                <Fact label="Method and outcome">{RTW_METHOD_LABEL[c.method] ?? c.method}, {RTW_OUTCOME_LABEL[c.outcome] ?? c.outcome}</Fact>
                <Fact label="Follow-up">{c.recheck_due ? dateOnly(c.recheck_due) : "None"}{c.follow_up_required ? " (required)" : ""}</Fact>
                <Fact label="Reviewer">{c.verified_by ? `${c.verified_by}, ${dateOnly(c.verified_at)}` : "Not signed off"}</Fact>
              </li>
            ))}
          </ul>
        )}
      </Section>

      {checks.references !== null && (
        <Section title="References" description="Referee details belong to the referee. Office staff only.">
          {checks.references.length === 0 ? (
            <p className="text-sm text-muted-foreground">No references recorded. Add them from Compliance, References.</p>
          ) : (
            <ul className="divide-y divide-border">
              {checks.references.map((r) => (
                <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                  <span>
                    <span className="font-medium">{r.referee_name}</span>
                    {r.referee_org && <span className="text-muted-foreground">, {r.referee_org}</span>}
                  </span>
                  <span className="capitalize text-muted-foreground">{r.status.replace("_", " ")}</span>
                </li>
              ))}
            </ul>
          )}
        </Section>
      )}

      <Section
        title="Documents on file"
        description="Filed against this person or their worker record."
        action={canManage ? (
          <UploadButton personId={personId} category="other" label="Upload document"
            onUploaded={() => { toast.success("Document filed."); onChanged() }} />
        ) : undefined}
      >
        {checks.files.length === 0 ? (
          <p className="text-sm text-muted-foreground">No documents on file, or none your account may open.</p>
        ) : (
          <ul className="divide-y divide-border">
            {checks.files.map((f) => (
              <li key={f.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <FileLink id={f.id} name={f.original_name} />
                <span className="text-xs text-muted-foreground">
                  <span className="capitalize">{f.category}</span>, {dateOnly(f.created_at)}{f.uploaded_by ? `, ${f.uploaded_by}` : ""}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Section>

      <VerifyDialog
        open={verifying !== null}
        onOpenChange={(o) => !o && setVerifying(null)}
        requirement={verifying?.label ?? ""}
        busy={busy}
        onConfirm={(body) => verifying?.open_verification_id
          ? run(`verifications.php?action=verify&id=${verifying.open_verification_id}`, body, `${verifying.label} verified.`)
          : false}
      />
      <ReasonDialog
        open={rejecting !== null}
        onOpenChange={(o) => !o && setRejecting(null)}
        title={`Reject ${rejecting?.label ?? "evidence"}`}
        description="Say what was wrong, so the person can be told and can put it right."
        confirm="Reject"
        destructive
        busy={busy}
        onConfirm={(reason) => rejecting?.open_verification_id
          ? run(`verifications.php?action=reject&id=${rejecting.open_verification_id}`, { reason }, `${rejecting.label} rejected.`)
          : false}
      />
      <RightToWorkDialog personId={personId} personName={profile.person.full_name} open={rtwOpen} onOpenChange={setRtwOpen} onSaved={onChanged} />
    </div>
  )
}
