import { hoursLabel, serviceName, wallClock } from "@/lib/people"
import type { PersonProfile, ProfileShift } from "@/lib/types"
import { Section } from "@/components/people/shared"

const STATUS_LABEL: Record<ProfileShift["status"], string> = {
  open: "Open", scheduled: "Booked", completed: "Completed", cancelled: "Cancelled",
}

/**
 * Shifts booked for this person's worker records. Durations are what was
 * booked; hours actually worked are timesheets, which are the payroll zone and
 * live on the Timesheets page.
 */
export function ShiftsTab({ profile }: { profile: PersonProfile }) {
  if (profile.workers.length === 0) {
    return (
      <Section title="Shifts">
        <p className="text-sm text-muted-foreground">No worker record yet, so no shifts.</p>
      </Section>
    )
  }
  const bookedMinutes = profile.shifts.past
    .filter((s) => s.status !== "cancelled")
    .reduce((sum, s) => sum + s.minutes, 0)
  const cancelled = profile.shifts.past.filter((s) => s.status === "cancelled").length
    + profile.shifts.upcoming.filter((s) => s.status === "cancelled").length

  return (
    <div className="space-y-4">
      <Section title="Upcoming" description={`${profile.shifts.upcoming.length} booked or open from now on.`}>
        <ShiftList shifts={profile.shifts.upcoming} empty="Nothing booked." />
      </Section>
      <Section
        title="Past"
        description={`The last ${profile.shifts.past.length}: ${hoursLabel(bookedMinutes)} booked, ${cancelled} cancelled.`}
      >
        <ShiftList shifts={profile.shifts.past} empty="No past shifts." />
      </Section>
    </div>
  )
}

function ShiftList({ shifts, empty }: { shifts: ProfileShift[]; empty: string }) {
  if (shifts.length === 0) return <p className="text-sm text-muted-foreground">{empty}</p>
  return (
    <ul className="divide-y divide-border">
      {shifts.map((s) => (
        <li key={s.id} className="grid gap-1 py-2 text-sm sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1.5fr)_auto] sm:items-center sm:gap-3">
          <span className="tabular-nums">{wallClock(s.starts_at)} ({hoursLabel(s.minutes)})</span>
          <span className="min-w-0">
            <span className="font-medium">{s.title}</span>
            <span className="text-muted-foreground">
              {s.site_name ? `, ${s.site_name}` : ""}{s.client_name ? `, ${s.client_name}` : ""}, {serviceName(s.division)}
            </span>
          </span>
          <span className={s.status === "cancelled" ? "font-medium" : "text-muted-foreground"}>{STATUS_LABEL[s.status]}</span>
        </li>
      ))}
    </ul>
  )
}
