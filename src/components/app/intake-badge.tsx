import { Badge } from "@/components/ui/badge"
import type { IntakeStatus } from "@/lib/types"

// One reading of form intake health, used by the forms directory and by
// Settings. Mirrors the status forms-intake.php?action=health computes.

const LABEL: Record<IntakeStatus, string> = {
  ok: "Working",
  silent: "Nothing received",
  paused: "Paused",
  not_configured: "No secret set",
  not_linked: "Not connected",
}

const VARIANT: Record<IntakeStatus, "default" | "secondary" | "destructive" | "outline"> = {
  ok: "default",
  silent: "destructive",
  paused: "secondary",
  not_configured: "destructive",
  not_linked: "outline",
}

export function IntakeBadge({ status }: { status: IntakeStatus }) {
  return <Badge variant={VARIANT[status]}>{LABEL[status]}</Badge>
}
