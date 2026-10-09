import { Ban, CheckCheck, CheckCircle2, CircleDashed, OctagonX, PencilLine, Send, TriangleAlert } from "lucide-react"
import { cn } from "@/lib/utils"
import { serviceLabel, SERVICE_ACCENTS, type ServiceId } from "@/lib/services"
import { CHECK_LABEL, STATE_LABEL } from "@/lib/rota"
import type { AssignmentCheck, CheckReason, ShiftState } from "@/lib/types"

const STATE_ICON = {
  draft: PencilLine,
  open: CircleDashed,
  offered: Send,
  assigned: CheckCircle2,
  completed: CheckCheck,
  cancelled: Ban,
} as const

// A symbol and a word, never colour alone (docs/DESIGN_SYSTEM.md). Published is
// not shown in the brand green: green is the brand, not a status.
const STATE_TONE: Record<ShiftState, string> = {
  draft: "border-dashed border-border bg-background text-muted-foreground",
  open: "border-warning/40 bg-warning-soft text-foreground",
  offered: "border-border bg-muted text-foreground",
  assigned: "border-success/40 bg-success-soft text-foreground",
  completed: "border-border bg-muted text-muted-foreground",
  cancelled: "border-destructive/40 bg-destructive/10 text-foreground",
}

export function StateChip({ state, className }: { state: ShiftState; className?: string }) {
  const Icon = STATE_ICON[state]
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[11px] font-medium leading-none",
        STATE_TONE[state],
        className,
      )}
    >
      <Icon className="size-3" aria-hidden />
      {STATE_LABEL[state]}
    </span>
  )
}

/** A service tag: a small dot and the word, never a filled surface. */
export function ServiceDot({ service, className }: { service: string; className?: string }) {
  const colour = SERVICE_ACCENTS[service as ServiceId] ?? "currentColor"
  return (
    <span className={cn("inline-flex items-center gap-1 text-[11px] text-muted-foreground", className)}>
      <span className="size-1.5 shrink-0 rounded-full" style={{ backgroundColor: colour }} aria-hidden />
      {serviceLabel(service)}
    </span>
  )
}

/** One reason something is wrong with a booking, said plainly where the booking is. */
export function ConflictLine({ c, compact }: { c: CheckReason & { severity?: "block" | "warning" }; compact?: boolean }) {
  const blocking = c.severity !== "warning" && c.code !== "rest" && c.code !== "unavailable"
  const Icon = blocking ? OctagonX : TriangleAlert
  return (
    <p className={cn("flex items-start gap-1 text-xs", blocking ? "text-destructive" : "text-warning")}>
      <Icon className="mt-px size-3.5 shrink-0" aria-hidden />
      <span className={cn(compact && "line-clamp-2", "text-foreground")}>
        <span className="font-medium">{CHECK_LABEL[c.code]}:</span> {c.reason}
      </span>
    </p>
  )
}

/** The result of checking one worker for one shift: what blocks, what warns. */
export function CheckResult({ check, name }: { check: AssignmentCheck; name: string }) {
  if (check.ok && check.warnings.length === 0) {
    return (
      <p className="flex items-center gap-1.5 rounded-md border border-success/40 bg-success-soft px-3 py-2 text-sm">
        <CheckCircle2 className="size-4 text-success" aria-hidden />
        {name} can work this shift.
      </p>
    )
  }
  return (
    <div className="space-y-2">
      {check.blocking.length > 0 && (
        <div className="space-y-1.5 rounded-md border border-destructive/40 bg-destructive/5 p-3" role="alert">
          <p className="text-sm font-medium">{name} cannot work this shift</p>
          {check.blocking.map((b, i) => (
            <ConflictLine key={i} c={b} />
          ))}
          {/* The gate's reason already names a single missing requirement. */}
          {check.eligibility.blocking.length > 1 && (
            <ul className="ml-5 list-disc text-xs text-muted-foreground">
              {check.eligibility.blocking.map((b) => (
                <li key={b.requirement}>
                  {b.requirement}: {b.reason}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
      {check.ok && check.warnings.length > 0 && (
        <div className="space-y-1.5 rounded-md border border-warning/40 bg-warning-soft p-3">
          <p className="text-sm font-medium">Check before booking {name}</p>
          {check.warnings.map((w, i) => (
            <ConflictLine key={i} c={{ ...w, severity: "warning" }} />
          ))}
        </div>
      )}
    </div>
  )
}
