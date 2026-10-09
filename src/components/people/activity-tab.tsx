import { actionLabel, isRestricted, wallClock } from "@/lib/people"
import type { PersonProfile } from "@/lib/types"
import { RestrictedBlock, Section } from "@/components/people/shared"

const ENTITY_LABEL: Record<string, string> = {
  person: "Person",
  worker: "Worker record",
  applicant: "Application",
  onboarding_task: "Onboarding task",
  employment_contract: "Contract",
  verification: "Check",
  right_to_work_check: "Right to work",
  leave: "Leave",
  enrolment: "Training",
  file: "Document",
}

/** Every change to this person and their linked records, newest first, and who made it. */
export function ActivityTab({ profile }: { profile: PersonProfile }) {
  const activity = profile.activity
  if (isRestricted(activity)) return <RestrictedBlock what="the activity trail" />

  return (
    <Section title="Activity" description="The newest 200 changes to this person and the records linked to them.">
      {activity.length === 0 ? (
        <p className="text-sm text-muted-foreground">Nothing recorded yet.</p>
      ) : (
        <ol className="divide-y divide-border">
          {activity.map((a) => (
            <li key={a.id} className="grid gap-1 py-2 text-sm sm:grid-cols-[10rem_minmax(0,1fr)_auto] sm:gap-3">
              <span className="tabular-nums text-muted-foreground">{wallClock(a.created_at)}</span>
              <span className="min-w-0">
                <span className="font-medium">{actionLabel(a.action)}</span>
                <span className="text-muted-foreground"> on {ENTITY_LABEL[a.entity_type ?? ""] ?? a.entity_type ?? "record"}</span>
                {a.detail && <span className="block truncate text-xs text-muted-foreground">{a.detail}</span>}
                {a.reason && <span className="block text-xs">Reason: {a.reason}</span>}
              </span>
              <span className="text-muted-foreground">{a.user ?? "System"}</span>
            </li>
          ))}
        </ol>
      )}
    </Section>
  )
}
