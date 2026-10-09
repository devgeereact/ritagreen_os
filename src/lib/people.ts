// Presentation helpers for the person profile and the compliance screens.
//
// Nothing here decides anything. Every status is calculated by the server
// (server/pure.php) and arrives as a key; this file only says how each key is
// written and which semantic colour token it takes. Colour never carries the
// meaning alone: every chip has a word and an icon as well (docs/Plans 2026/04).

import { DIVISION_LABELS } from "@/lib/format"
import type { ContractStatus, RequirementChip, Restricted, ServiceStatus, VerificationStatus } from "@/lib/types"

/** A service's name as the office reads it. The labels live in one place, src/lib/format.ts. */
export function serviceName(service: string): string {
  return DIVISION_LABELS[service] ?? service
}

/** A date, or a date-time read as UTC, as "9 Oct 2026". */
export function dateOnly(value: string | null): string {
  if (!value) return "Not recorded"
  const d = new Date(value.length <= 10 ? `${value}T00:00:00Z` : value.replace(" ", "T") + "Z")
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" })
}

/** A section the account may not see arrives as { restricted: true }. */
export function isRestricted(value: unknown): value is Restricted {
  return typeof value === "object" && value !== null && (value as { restricted?: unknown }).restricted === true
}

/** The profile page for a person. People are addressed by person id, never worker id. */
export function personPath(personId: number): string {
  return `/app/people/${personId}`
}

export const SERVICE_STATUS_LABEL: Record<ServiceStatus, string> = {
  applicant: "Applicant",
  onboarding: "Onboarding",
  active: "Active",
  suspended: "Suspended",
  inactive: "Inactive",
  left: "Left",
}

/** Semantic tokens only, so a change of palette reaches these without an edit here. */
export const SERVICE_STATUS_TONE: Record<ServiceStatus, string> = {
  applicant: "border-border bg-muted text-foreground",
  onboarding: "border-warning/40 bg-warning/10 text-foreground",
  active: "border-success/40 bg-success/10 text-foreground",
  suspended: "border-destructive/40 bg-destructive/10 text-foreground",
  inactive: "border-border bg-muted text-muted-foreground",
  left: "border-border bg-muted text-muted-foreground",
}

export const CHIP_TONE: Record<RequirementChip, string> = {
  valid: "border-success/40 bg-success/10 text-foreground",
  due_soon: "border-warning/40 bg-warning/10 text-foreground",
  expired: "border-destructive/40 bg-destructive/10 text-foreground",
  awaiting_review: "border-border bg-muted text-foreground",
  missing: "border-destructive/40 bg-background text-foreground",
}

export const CHIP_ICON_TONE: Record<RequirementChip, string> = {
  valid: "text-success",
  due_soon: "text-warning",
  expired: "text-destructive",
  awaiting_review: "text-muted-foreground",
  missing: "text-destructive",
}

export const VERIFICATION_LABEL: Record<VerificationStatus, string> = {
  requested: "Requested",
  received: "Received",
  under_review: "Under review",
  verified: "Verified",
  rejected: "Rejected",
  expired: "Expired",
}

export const CONTRACT_LABEL: Record<ContractStatus, string> = {
  draft: "Draft",
  sent: "Sent",
  viewed: "Viewed",
  accepted: "Accepted",
  signed: "Signed",
  declined: "Declined",
  expired: "Expired",
  superseded: "Superseded",
}

export const RTW_METHOD_LABEL: Record<string, string> = {
  online_share_code: "Online share code",
  manual_document: "Manual document check",
  idsp_digital: "Identity service provider",
  other: "Other",
}

export const RTW_OUTCOME_LABEL: Record<string, string> = {
  pending: "Pending",
  valid: "Valid",
  invalid: "Not valid",
  requires_follow_up: "Valid, follow-up needed",
}

/** "7h 30m" from minutes. */
export function hoursLabel(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m === 0 ? `${h}h` : `${h}h ${m}m`
}

/** A wall-clock "YYYY-MM-DD HH:MM:SS" as "Thu 9 Oct, 07:30". Never shifted by the browser's zone. */
export function wallClock(value: string | null): string {
  if (!value) return "Not recorded"
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(value)
  if (!m) return value
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])))
  const day = d.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short", timeZone: "UTC" })
  return `${day}, ${m[4]}:${m[5]}`
}

/** Initials for an avatar when there is no photo. */
export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? "")
    .join("")
}

/** An audit action key, "worker.approve", as words: "Worker approve". */
export function actionLabel(action: string): string {
  const words = action.replace(/[._]/g, " ").trim()
  return words.charAt(0).toUpperCase() + words.slice(1)
}
