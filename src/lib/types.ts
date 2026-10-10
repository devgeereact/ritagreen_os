// Shared CMS data shapes (mirror the MySQL schema in server/schema.sql).

export type DivisionId = "care" | "academy" | "cleaning" | "security"

export type Client = {
  id: number
  name: string
  type: string // care_home | nhs | landlord | event | private
  contact_name: string | null
  email: string | null
  phone: string | null
  divisions: string // comma-separated division ids
  status: string // active | prospect | paused
  stage?: "lead" | "qualified" | "proposal" | "won" | "lost"
  account_manager_id?: number | null
  account_manager?: string | null
  sla_response_hours?: number | null
  sites_count?: number
  created_at: string
}

export type RateCard = {
  id: number
  division: DivisionId
  role: string
  charge_pence: number
}

export type ClientFinancials = {
  quoted_pence: number
  invoiced_pence: number
  paid_pence: number
  outstanding_pence: number
}

export type ServiceRequest = {
  id: number
  client_id: number | null
  client: string | null
  contact_name: string
  email: string
  phone: string | null
  division: DivisionId
  site_name: string | null
  detail: string
  preferred_start: string | null
  preferred_end: string | null
  status: "new" | "quoted" | "confirmed" | "declined"
  shift_id: number | null
  created_at: string
}

export type Worker = {
  id: number
  /** The person this employment record belongs to; the profile is keyed by it. */
  person_id?: number | null
  name: string
  email: string | null
  phone: string | null
  role: string // carer | nurse | cleaner | guard | trainer
  division: DivisionId
  status: string // active | onboarding | inactive
  right_to_work: boolean
  dbs_status: string | null // valid | pending | expired
  dbs_expiry: string | null
  sia_number: string | null
  sia_expiry: string | null
  pay_rate_pence?: number
  pay_type?: "paye" | "umbrella"
  holiday_hours?: number
  home_postcode?: string | null
  travel_radius_mi?: number
  avail_days?: boolean
  avail_nights?: boolean
  avail_weekends?: boolean
  created_at: string
}

export type Skill = {
  id: number
  name: string
  category: "care" | "academy" | "cleaning" | "security" | "general"
}

export type MatchWorker = {
  id: number
  name: string
  role: string
  home_postcode: string | null
  travel_radius_mi: number
  upcoming_shifts: number
  /** Things the office must acknowledge before booking them (rest gap, marked unavailable). */
  warnings?: CheckReason[]
}

/** One reason from the rota's assignment check (server: rg_assignment_check). */
export type CheckReason = {
  code: "employment" | "evidence" | "clash" | "leave" | "unavailable" | "rest"
  reason: string
}

/** May this worker take this shift? Blocking reasons refuse; warnings need acknowledging. */
export type AssignmentCheck = {
  ok: boolean
  blocking: CheckReason[]
  warnings: CheckReason[]
  eligibility: {
    status: string | null
    score: number | null
    blocking: { requirement: string; reason: string }[]
  }
}

/** A site under a client (migration 038). */
export type Site = {
  id: number
  client_id: number
  client?: string
  name: string
  address: string | null
  postcode: string | null
  access_notes: string | null
  contact_name: string | null
  contact_phone: string | null
  active: boolean
  requirements: { id: number; req_key: string; label: string }[]
}

/** A compliance requirement a site can insist on. */
export type RequirementOption = { id: number; req_key: string; label: string }

/** A cover request or a website enquiry, as the Requests page lists them. */
export type RequestItem = {
  type: "cover" | "enquiry"
  id: number
  name: string
  email: string
  phone: string | null
  division: string
  summary: string
  status: string
  open: boolean
  owner_id: number | null
  owner: string | null
  client_id: number | null
  client: string | null
  site_name: string | null
  preferred_start: string | null
  preferred_end: string | null
  shift_id: number | null
  created_at: string
}

export type ShiftTemplate = {
  id: number
  title: string
  division: DivisionId
  client_id: number | null
  client: string | null
  site_name: string | null
  weekday: number
  start_time: string
  end_time: string
  active: boolean
}

export type AppNotification = {
  id: number
  type: string
  title: string
  body: string | null
  url: string | null
  read: boolean
  broadcast: boolean
  created_at: string
}

export type PayRunRow = {
  worker_id: number
  worker: string
  division: DivisionId
  pay_type: "paye" | "umbrella"
  pay_rate_pence: number
  hours: number
  base_pence: number
  premium_pence: number
  gross_pence: number
  holiday_hours: number
  holiday_pay_pence: number
  /** The worker's rate moved inside the period, so pay_rate_pence is the latest of them. */
  rate_changed: boolean
  /** Paid below the configured pay floor. False whenever no floor is set. */
  below_floor: boolean
}

export type PayRun = {
  period: { from: string; to: string }
  rows: PayRunRow[]
  totals: {
    hours: number
    base_pence: number
    premium_pence: number
    gross_pence: number
    holiday_pay_pence: number
  }
  rules: Record<string, number>
  /** The pay floor in force, or null when none is configured. See migration 024. */
  floor_pence: number | null
}

export type PayslipLine = {
  work_date: string
  start_time: string
  end_time: string
  hours: number
  /** The dearest premium band the shift touched; `breakdown` is what earned the money. */
  premium: string
  /** Hours per premium band. A shift running from a weekday night into a Saturday has two. */
  breakdown: Record<string, number>
  /** Unpaid break already deducted from `hours`. */
  break_minutes: number
  overtime_hours: number
  gross_pence: number
}

export type Payslip = {
  worker: {
    id: number
    name: string
    division: DivisionId
    role: string
    pay_type: "paye" | "umbrella"
    pay_rate_pence: number
  }
  period: { from: string; to: string }
  lines: PayslipLine[]
  totals: {
    hours: number
    base_pence: number
    premium_pence: number
    gross_pence: number
    holiday_hours: number
    holiday_pay_pence: number
  }
}

export type Incident = {
  id: number
  ref: string
  division: DivisionId
  type: "accident" | "incident" | "near_miss" | "safeguarding" | "complaint"
  severity: "low" | "medium" | "high" | "critical"
  title: string
  detail?: string
  client?: string | null
  site_name?: string | null
  worker?: string | null
  riddor: number
  safeguarding: number
  status: "open" | "investigating" | "actioned" | "closed"
  reporter?: string | null
  occurred_at?: string | null
  created_at: string
}

export type IncidentAction = { id: number; note: string; done: number; created_at: string }

export type IncidentSummary = { open: number; safeguarding: number; riddor: number; critical: number }

export type FieldShift = {
  id: number
  title: string
  division: DivisionId
  site_name: string | null
  client: string | null
  worker: string | null
  starts_at: string
  ends_at: string
  field_status: "scheduled" | "late" | "on_site" | "overrunning" | "completed" | "no_show" | "unfilled"
  panic: boolean
}

export type ShiftEvent = {
  id: number
  kind: "clock_in" | "clock_out" | "check_in" | "panic" | "panic_ack" | "note" | "task" | "signoff"
  note: string | null
  lat: string | null
  lng: string | null
  signer: string | null
  worker: string | null
  created_at: string
}

export type Applicant = {
  id: number
  /** Only sent on the detail read. The person profile is keyed by it. */
  person_id?: number | null
  name: string
  email: string
  phone: string | null
  role_applied: string | null
  division: DivisionId
  // "onboarding" is onboarding STARTED: a pending worker exists and the checklist
  // is outstanding. "onboarded" is the completed state. They were one value, so
  // the pipeline said onboarded the moment the first unfinished task was written.
  stage: "applied" | "screened" | "interview" | "vetting" | "offer" | "onboarding" | "onboarded" | "rejected"
  source: string | null
  worker_id: number | null
  recruiter_id: number | null
  /** Only sent on the detail read, not in the list. */
  recruiter_name?: string | null
  /** Why they left the pipeline. Cleared if they come back into it. */
  outcome_reason?: string | null
  created_at: string
}

export type OnboardingTask = {
  id: number
  label: string
  done: number
  owner_id: number | null
  owner_name: string | null
  due_on: string | null
  done_at: string | null
}

/** An interview record. `scheduled_at` is wall-clock, like a shift's start. */
export type ApplicantInterview = {
  id: number
  scheduled_at: string
  location: string | null
  outcome: "scheduled" | "passed" | "failed" | "no_show" | "cancelled"
  notes: string | null
  decided_at: string | null
  interviewer_id: number | null
  interviewer_name: string | null
}

export type ApplicantStageChange = {
  id: number
  from_stage: string | null
  to_stage: string
  reason: string | null
  changed_at: string
  /** Null when the public form or an integration made the change. */
  changed_by_name: string | null
}

/**
 * A stage the candidate may be moved to, and whether it needs a reason.
 * Sent by the server so the screen does not keep its own copy of the rules.
 */
export type NextStage = { stage: string; needs_reason: boolean }

/** Internal staff who can be given a candidate, a task or an interview. */
export type AssignableStaff = { id: number; name: string; role: string }

export type ComplianceItem = {
  worker_id: number
  person_id?: number | null
  worker: string
  division: DivisionId
  type: string
  identifier: string | null
  expiry: string | null
  status: "valid" | "expiring" | "expired" | "unknown"
  days: number | null
}

export type ComplianceSummary = {
  valid: number
  expiring: number
  expired: number
  unknown: number
}

export type Insurance = {
  id: number
  type: "employers_liability" | "public_liability" | "professional_indemnity" | "other"
  insurer: string
  policy_no: string | null
  cover_pence: number
  start_date: string | null
  expiry: string | null
  notes: string | null
  status: "valid" | "expiring" | "expired" | "unknown"
  days: number | null
}

export type InsuranceSummary = {
  el_minimum_met: boolean
  el_minimum_pence: number
}

export type WorkerReference = {
  id: number
  worker_id: number
  referee_name: string
  referee_org: string | null
  relationship: string | null
  email: string | null
  phone: string | null
  status: "requested" | "received" | "verified" | "gap_explained"
  gap_note: string | null
  created_at: string
}

export type CompliancePack = {
  worker: {
    id: number
    name: string
    email: string | null
    phone: string | null
    role: string
    division: DivisionId
    status: string
    right_to_work: boolean
    recheck_due: string | null
  }
  credentials: {
    type: string
    identifier: string | null
    expiry: string | null
    status: "valid" | "expiring" | "expired" | "unknown"
    days: number | null
  }[]
  references: {
    referee_name: string
    referee_org: string | null
    relationship: string | null
    status: string
    gap_note: string | null
  }[]
  documents: { original_name: string; category: string; created_at: string }[]
  generated_by: string
  generated_at: string
}

export type Shift = {
  id: number
  worker_id: number | null
  worker: string | null
  division: DivisionId
  title: string
  site_name: string | null
  client_id: number | null
  client: string | null
  starts_at: string
  ends_at: string
  status: "open" | "scheduled" | "completed" | "cancelled"
  /** Migration 038. Workers never see a draft. */
  is_draft?: boolean
  site_id?: number | null
  cancel_reason?: string | null
}

/** Where a shift is in its life, derived by the server (rg_shift_state). */
export type ShiftState = "draft" | "open" | "offered" | "assigned" | "completed" | "cancelled"

/** A shift as the rota shows it: its state and anything wrong with who is on it. */
export type RotaShift = Shift & {
  is_draft: boolean
  site_id: number | null
  state: ShiftState
  live_offers: number
  conflicts: (CheckReason & { severity: "block" | "warning" })[]
}

export type ShiftHistoryEntry = {
  action: string
  detail: string | null
  reason: string | null
  by: string | null
  at: string
}

export type ShiftOffer = {
  worker_id: number
  worker: string
  status: "sent" | "accepted" | "declined" | "expired"
  expires_at: string | null
  responded_at: string | null
}

/**
 * One worked period, as claimed and then decided on.
 *
 * work_date is the date the shift STARTS: an end_time at or before start_time
 * means it ran past midnight, which is why minutes is the server's number and
 * not something to recompute from the two clock times here.
 */
export type TimesheetStatus = "draft" | "pending" | "approved" | "rejected" | "queried"

export type Timesheet = {
  id: number
  worker_id: number
  worker: string
  division: DivisionId
  work_date: string
  start_time: string
  end_time: string
  /** Clock minutes from start to end, breaks included. */
  minutes: number
  /** Unpaid break. Pay is on `minutes - break_minutes`. */
  break_minutes: number
  /** The rota entry these hours were worked on, when there was one. */
  shift_id: number | null
  shift_title: string | null
  status: TimesheetStatus
  /** Made from clock-in and clock-out, or logged by the office. */
  source?: "office" | "clock"
  submitted_at?: string | null
  decided_at: string | null
  decision_note: string | null
  decided_by: string | null
}

export type Announcement = {
  id: number
  audience: "all" | "care" | "academy" | "cleaning" | "security"
  title: string
  body: string
  author: string | null
  created_at: string
}

export type FileDoc = {
  id: number
  original_name: string
  mime: string
  size_bytes: number
  category: "contract" | "certificate" | "policy" | "rams" | "id" | "other"
  uploaded_by: string | null
  created_at: string
}

export type Enquiry = {
  id: number
  name: string
  email: string
  phone: string | null
  division: string
  message: string
  status: "new" | "handled"
  created_at: string
}

export type AdminUser = {
  id: number
  name: string
  email: string
  role: string
  division: string | null
  active: boolean
  client_id?: number | null
  worker_id?: number | null
  client?: string | null
  worker?: string | null
  created_at: string
}

// AuditEntry is defined with the rest of the governance types at the end of this
// file. It gained entity, before/after and IP in migration 009.

export type Programme = {
  id: number
  title: string
  description: string | null
  division: DivisionId
  status: "draft" | "published" | "archived"
  cpd_hours?: number
  enrolments: number
  created_at: string
}

export type Enrolment = {
  id: number
  learner_name: string
  status: "enrolled" | "in_progress" | "completed" | "withdrawn"
  certificate_public_id: string | null
  created_at: string
}

export type Job = {
  id: number
  client_id: number | null
  client: string | null
  division: DivisionId
  type: "care_visit" | "clean" | "guard_shift" | "course" | "other"
  title: string
  priority: "emergency" | "high" | "standard"
  status: "booked" | "assigned" | "in_progress" | "done" | "cancelled"
  scheduled_for: string | null
  notes: string | null
  /** Who is doing it. `status: "assigned"` was decorative until this existed. */
  assigned_to: number | null
  assignee: string | null
  created_at: string
}

export type Contract = {
  id: number
  client_id: number | null
  client: string | null
  division: DivisionId
  title: string
  scope: string | null
  rate_pence: number
  frequency: "weekly" | "fortnightly" | "monthly" | "quarterly"
  sla_hours: number | null
  start_date: string
  end_date: string
  status: "active" | "expired" | "cancelled"
  days_to_renewal: number
}

export type Quote = {
  id: number
  number: string
  client_id: number | null
  client: string | null
  division: DivisionId
  title: string
  net_pence: number
  vat_pence: number
  total_pence: number
  status: "draft" | "sent" | "accepted" | "declined"
  invoice_id: number | null
  created_at: string
}

export type QuoteTotals = {
  open_pence: number
  won_pence: number
}

export type Invoice = {
  id: number
  number: string
  client_id: number | null
  client: string | null
  division: DivisionId
  net_pence: number
  vat_pence: number
  total_pence: number
  status: "draft" | "sent" | "paid" | "overdue" | "void"
  due_date?: string | null
  credited_pence?: number
  amount_paid_pence?: number
  has_pay_link?: boolean
  days_overdue?: number
  created_at: string
}

export type InvoiceTotals = {
  outstanding_pence: number
  paid_pence: number
}

export type AgedDebt = {
  buckets: {
    current: number
    d1_30: number
    d31_60: number
    d61_90: number
    d90_plus: number
  }
  items: {
    id: number
    number: string
    client: string | null
    balance_pence: number
    days_overdue: number
    bucket: string
  }[]
}

export type CreditNote = {
  id: number
  number: string
  invoice_id: number
  amount_pence: number
  reason: string | null
  created_by: string | null
  created_at: string
}

export type MarginRow = {
  division: DivisionId
  revenue_pence: number
  cost_pence: number
  margin_pence: number
  margin_pct: number | null
}

export type MarginData = {
  period: { from: string; to: string }
  rows: MarginRow[]
  totals: {
    revenue_pence: number
    cost_pence: number
    margin_pence: number
    margin_pct: number | null
  }
}

export type DashboardSummary = {
  active_programmes: number
  enrolments: number
  upcoming_sessions: number
  certificates_issued: number
  outstanding_invoices_pence: number
  open_shifts: number
  revenue_by_division: { division: string; pence: number }[]
  compliance_alerts: { worker: string; item: string; days: number }[]
}

// ---- Today, Reports and system health (section S5) -------------------------

/** A count on the Today page and where it leads. Null when the account may not see it. */
export type TodayTile = { count: number; link: string } | null

export type ActionItem = {
  urgency: "urgent" | "today" | "soon"
  kind: string
  title: string
  detail: string
  link: string | null
  count: number
  /** The verb on the button, e.g. "Verify". */
  action: string
  /** Up to five people the item is about, in this account's scope. */
  people: { id: number; name: string }[]
}

/** server/action-centre.php */
export type ActionCentre = {
  tiles: {
    unfilled_shifts: TodayTile
    checks_to_review: TodayTile
    expiring_30: TodayTile
    approvals_waiting: TodayTile
  }
  items: ActionItem[]
  counts: { urgent: number; today: number; soon: number }
  total: number
  all_clear: boolean
  scope: string | null
  generated_at: string
}

export type ReportKey = "pipeline" | "workforce" | "expiring" | "unfilled" | "hours" | "workload"

export type ReportListing = { key: ReportKey; title: string; description: string; personal: boolean }

/** server/reports.php?action=run */
export type ReportResult = {
  report: ReportKey
  title: string
  description: string
  columns: { key: string; label: string }[]
  rows: Record<string, string | number | boolean | null>[]
  totals: Record<string, number>
  service: string | null
  personal: boolean
  generated_at: string
}

/** server/compliance.php?action=profile_pack: client-safe fields only. */
export type ProfilePack = {
  person: { id: number; name: string; photo: string | null }
  roles: string[]
  services: string[]
  training: { course: string; completed_on: string | null; expires_on: string | null; status: string }[]
  dbs: { verified: boolean; checked_on: string | null; level: string | null; renewal_due: string | null }
  right_to_work: { verified: boolean; verified_on: string | null }
  sia: { licence_number: string | null; expiry: string | null; status: string } | null
  generated_by: string
  generated_at: string
}

export type HealthState = "ok" | "warn" | "fail" | "off"

/** server/system-health.php */
export type SystemHealth = {
  overall: HealthState
  checks: { key: string; label: string; state: HealthState; detail: string }[]
  jobs: {
    key: string
    label: string
    every_hours: number
    state: "ok" | "late" | "unknown"
    last_started_at: string | null
    last_success_at: string | null
    last_detail: string | null
    runs: number
    note: string | null
  }[]
  mail_queue: {
    enabled: boolean
    counts: Record<"queued" | "sending" | "sent" | "failed" | "cancelled", number>
    recent_failures: { id: number; subject: string; template_key: string | null; attempts: number; last_error: string | null; created_at: string }[]
  }
  forms: {
    id: number
    name: string
    state: "ok" | "fail" | "off"
    live: boolean
    last_success_at: string | null
    last_failure_at: string | null
    last_failure_reason: string | null
    failed: number
    quiet: boolean
  }[]
  generated_at: string
}

// ─────────────────────────────────────────────────────────────────────────────
// The person spine
//
// A person exists once. Candidate, worker, learner and login are roles that
// person holds, not separate records. See docs/BLUEPRINT.md.
// ─────────────────────────────────────────────────────────────────────────────

export type ServiceId = "care" | "academy" | "cleaning" | "security"

export type Person = {
  id: number
  full_name: string
  preferred_name: string | null
  date_of_birth: string | null
  email: string | null
  phone: string | null
  address_line1: string | null
  address_line2: string | null
  city: string | null
  postcode: string | null
  emergency_contact_name: string | null
  emergency_contact_phone: string | null
  emergency_contact_relationship: string | null
  /** 'e:<email>' or 'p:<digits>'. Unique, so no two people share an identity. */
  match_key: string | null
  created_at: string
}

// ─────────────────────────────────────────────────────────────────────────────
// The person profile (server/people.php)
//
// One read for the whole page. A section the account may not see arrives as
// { restricted: true } rather than as data, so the screen can say so. Every
// status below is calculated by the server; none of it is ever POSTed back.
// ─────────────────────────────────────────────────────────────────────────────

export type Restricted = { restricted: true }

/** Where a person stands in one service. */
export type ServiceStatus = "applicant" | "onboarding" | "active" | "suspended" | "inactive" | "left"

export type PersonService = {
  service: ServiceId
  status: ServiceStatus
  detail: string
  worker_id: number | null
  applicant_id: number | null
  payroll_no: string | null
  stage: string | null
  /** Present when the account holds the compliance zone. */
  eligibility?: EligibilityStatus
}

/** The five status chips on the checks tab. Always shown with a symbol and a word. */
export type RequirementChip = "valid" | "due_soon" | "expired" | "awaiting_review" | "missing"

export type VerificationStatus = "requested" | "received" | "under_review" | "verified" | "rejected" | "expired"

export type ProfileRequirement = AssessedRequirement & {
  chip: RequirementChip
  chip_label: string
  expiry: string | null
  verification_id: number | null
  verification_status: VerificationStatus | null
  open_verification_id: number | null
  open_status: VerificationStatus | null
  /** What the latest verification for this requirement may become next. */
  next: VerificationStatus[]
  rejection_reason: string | null
  verified_by: string | null
  checked_on: string | null
  evidence_file_id: number | null
  evidence_file_name: string | null
}

export type ProfileAssessment = {
  service: ServiceId
  status: EligibilityStatus
  eligible: boolean
  score: number
  reason: string
  items: ProfileRequirement[]
}

export type ProfileVerification = {
  id: number
  requirement_key: string
  requirement: string
  subject_type: string
  subject_label: string | null
  status: VerificationStatus
  requested_at: string | null
  received_at: string | null
  reviewed_at: string | null
  verified_at: string | null
  expires_on: string | null
  method: string | null
  outcome_note: string | null
  rejection_reason: string | null
  evidence_file_id: number | null
  evidence_file_name: string | null
  verified_by: string | null
  next: VerificationStatus[]
}

export type ProfileFile = {
  id: number
  original_name: string
  mime: string
  category: string
  created_at: string
  expires_on: string | null
  uploaded_by: string | null
}

export type ApprovalBlocker = { code: "employment" | "contract"; reason: string }

export type ProfileWorker = {
  id: number
  payroll_no: string | null
  role: string
  division: ServiceId
  status: "active" | "onboarding" | "inactive" | "suspended"
  derived_status: string | null
  derived_status_reason: string | null
  left_on: string | null
  approved_at: string | null
  approved_by_name: string | null
  approval_note: string | null
  suspended_at: string | null
  suspended_by_name: string | null
  suspension_reason: string | null
  created_at: string
  approval: { approved: boolean; blockers: ApprovalBlocker[] }
}

export type ProfileApplication = {
  id: number
  division: ServiceId
  stage: Applicant["stage"] | "withdrawn"
  role_applied: string | null
  source: string | null
  created_at: string
  outcome_reason: string | null
  worker_id: number | null
  recruiter_name: string | null
  tasks: (Omit<OnboardingTask, "owner_id"> & { applicant_id: number })[]
}

export type ProfileShift = {
  id: number
  worker_id: number
  division: ServiceId
  title: string
  site_name: string | null
  client_name: string | null
  starts_at: string
  ends_at: string
  status: "open" | "scheduled" | "completed" | "cancelled"
  /** What was booked, not hours worked: those are timesheets, the payroll zone. */
  minutes: number
}

export type ContractStatus = "draft" | "sent" | "viewed" | "accepted" | "signed" | "declined" | "expired" | "superseded"

export type ProfileContract = {
  id: number
  reference: string
  version: number
  status: ContractStatus
  engagement_type: string
  job_title: string | null
  start_date: string | null
  /** Null without the payroll zone. */
  pay_rate_pence: number | null
  pay_frequency: string | null
  hours_pattern: string | null
  supersedes_id: number | null
  document_file_id: number | null
  document_file_name: string | null
  signed_file_id: number | null
  signed_file_name: string | null
  sent_at: string | null
  accepted_at: string | null
  signed_at: string | null
  signed_name: string | null
  reviewed_at: string | null
  reviewed_by_name: string | null
  review_note: string | null
  created_at: string
  next: ContractStatus[]
}

export type ProfilePayRate = {
  id: number
  worker_id: number
  rate_pence: number
  pay_type: "paye" | "umbrella"
  effective_from: string
  note: string | null
  set_by_name: string | null
}

export type ProfileLeave = {
  id: number
  kind: string
  status: "requested" | "approved" | "declined" | "cancelled" | "taken"
  starts_on: string
  ends_on: string
  hours: number
  leave_year: string | null
  /** Null, with health_redacted set, without the health zone. */
  reason: string | null
  return_to_work_on: string | null
  return_to_work_note: string | null
  decided_at: string | null
  decided_by: string | null
  health_redacted?: boolean
}

/** rg_leave_balance() in server/pure.php. Hours, not days. */
export type LeaveBalance = {
  entitlement_known: boolean
  entitlement: number
  carried_in: number
  taken: number
  approved: number
  requested: number
  paid_in_lieu: number
  /** Null until an entitlement is recorded, rather than a misleading negative. */
  remaining: number | null
  used: number
}

export type EmergencyContacts = {
  emergency_contact_name: string | null
  emergency_contact_phone: string | null
  emergency_contact_relationship: string | null
  emergency_contact2_name: string | null
  emergency_contact2_phone: string | null
  emergency_contact2_relationship: string | null
}

export type ProfileTrainingRecord = {
  id: number
  status: string
  course: string | null
  provider: string | null
  completed_on: string | null
  expires_on: string | null
  certificate_public_id: string | null
  evidence_file_id: number | null
  evidence_file_name: string | null
  rag: "valid" | "expiring" | "expired" | "unknown" | null
  days: number | null
}

export type ProfileCertification = {
  id: number
  worker_id: number
  type: string
  number: string | null
  expiry: string | null
  status: string
  rag: "valid" | "expiring" | "expired" | "unknown"
  days: number | null
}

export type ProfileActivity = {
  id: number
  action: string
  entity_type: string | null
  entity_id: number | null
  detail: string
  reason: string | null
  created_at: string
  user: string | null
}

export type NextAction = {
  key: string
  label: string
  tab: "overview" | "checks" | "training" | "shifts" | "employment" | "emergency" | "activity"
  target_id: number | null
}

export type PersonProfile = {
  person: {
    id: number
    full_name: string
    preferred_name: string | null
    email: string | null
    phone: string | null
    address_line1: string | null
    address_line2: string | null
    city: string | null
    postcode: string | null
    created_at: string
    start_date: string | null
  }
  services: PersonService[]
  payroll_no: string | null
  next_action: NextAction | null
  next_shift: ProfileShift | null
  zones: { compliance: boolean; payroll: boolean; health: boolean; employment: boolean }
  can: { manage: boolean; approve: boolean; edit_emergency: boolean }
  workers: ProfileWorker[]
  applications: ProfileApplication[]
  checks:
    | {
        assessments: ProfileAssessment[]
        verifications: ProfileVerification[]
        right_to_work: { current: RightToWorkCheck | null; checks: RightToWorkCheck[] }
        /** Null for anybody outside the office: a third party's contact details. */
        references: WorkerReference[] | null
        files: ProfileFile[]
      }
    | Restricted
  training: { certifications: ProfileCertification[]; records: ProfileTrainingRecord[]; providers: { id: number; name: string }[] } | Restricted
  shifts: { upcoming: ProfileShift[]; past: ProfileShift[] }
  employment: {
    contracts: ProfileContract[] | Restricted
    pay_rates: ProfilePayRate[] | Restricted
    leave: { leave_year: string; balance: LeaveBalance; records: ProfileLeave[] }
  }
  emergency: EmergencyContacts | Restricted
  activity: ProfileActivity[] | Restricted
}

/** One row of the verification queue (verifications.php?action=queue). */
export type VerificationQueueItem = {
  id: number
  person_id: number
  full_name: string
  requirement_key: string
  requirement: string
  subject_label: string | null
  status: VerificationStatus
  requested_at: string | null
  received_at: string | null
  expires_on: string | null
  evidence_file_id: number | null
  waiting_days: number | null
  services: ServiceId[]
  next: VerificationStatus[]
}

export type OnboardingTemplateItem = {
  id: number
  service: ServiceId
  label: string
  sort_order: number
  active: boolean
  updated_at: string
}

// ─────────────────────────────────────────────────────────────────────────────
// Compliance
//
// Every value below is CALCULATED by the server from the configured rules and
// the evidence on file. Nothing here is ever POSTed back — there is no endpoint
// that accepts an eligibility status. See docs/RULES.md rule 1.
// ─────────────────────────────────────────────────────────────────────────────

export type EligibilityStatus =
  | "eligible"
  | "eligible_with_warnings"
  | "not_eligible"
  | "unassessed"

/** Why a requirement is or is not satisfied. */
export type RequirementState =
  /** satisfied */
  | "met"
  /** valid today, but inside its expiry window — warns, never blocks */
  | "expiring"
  /** lapsed */
  | "expired"
  /** nothing on file at all, which is a gap in the evidence, not a lapse */
  | "missing"
  /** should carry an expiry but none is recorded — incomplete evidence */
  | "undated"
  /** on file but nobody has checked it — a claim, not evidence */
  | "unverified"

export type RequirementWeight = "mandatory" | "recommended" | "optional"

export type AssessedRequirement = {
  key: string
  label: string
  weight: RequirementWeight
  state: RequirementState
  /** Human-readable, e.g. "SIA licence: expired 3 days ago". Null when met. */
  reason: string | null
  /** Days until expiry; negative once passed. Null where there is no date. */
  days: number | null
}

export type EligibilityVerdict = {
  status: EligibilityStatus
  eligible: boolean
  /** 0–100. Mandatory counts double; optional does not count. */
  score: number
  /** Every blocker, not just the first. */
  blocking: AssessedRequirement[]
  warnings: AssessedRequirement[]
  items: AssessedRequirement[]
  reason: string
  service: ServiceId
  person_id: number
  worker?: string
}

/** One worker, assessed against every service. */
export type EligibilityBreakdown = {
  worker: string
  person_id: number
  services: Record<
    ServiceId,
    {
      status: EligibilityStatus
      eligible: boolean
      score: number
      reason: string
      blocking: string[]
    }
  >
}

// ─────────────────────────────────────────────────────────────────────────────
// Right to work
//
// A check, not a flag. Recording and verifying are separate actions: the person
// who collects a document is often not the person who signs it off.
// ─────────────────────────────────────────────────────────────────────────────

export type RightToWorkMethod =
  | "online_share_code"
  | "manual_document"
  | "idsp_digital"
  | "other"

export type RightToWorkOutcome = "pending" | "valid" | "invalid" | "requires_follow_up"

export type RightToWorkCheck = {
  id: number
  method: RightToWorkMethod
  method_note: string | null
  reference: string | null
  checked_on: string
  outcome: RightToWorkOutcome
  follow_up_required: boolean
  recheck_due: string | null
  evidence_file_id: number | null
  notes: string | null
  created_at: string
  /** Null until somebody signs it off. A check with no verifier is a claim. */
  verified_at: string | null
  verified_by: string | null
  verified: boolean
}

/** A check needing attention, with the reason it is on the list. */
export type RightToWorkDue = {
  id: number
  person_id: number
  full_name: string
  method: RightToWorkMethod
  checked_on: string
  outcome: RightToWorkOutcome
  recheck_due: string | null
  follow_up_required: boolean
  verified: boolean
  days_left: number | null
  attention: string
}

// ─────────────────────────────────────────────────────────────────────────────
// Governance
// ─────────────────────────────────────────────────────────────────────────────

export type AuditEntry = {
  id: number
  action: string
  entity_type: string | null
  entity_id: number | null
  detail: string
  /** Only the fields that changed, not a copy of the record. */
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  reason: string | null
  ip: string | null
  created_at: string
  user: string | null
}

export type EmailQueueStatus = "queued" | "sending" | "sent" | "failed" | "cancelled"

export type QueuedEmail = {
  id: number
  recipient: string
  subject: string
  status: EmailQueueStatus
  attempts: number
  last_error: string | null
  template_key: string | null
  created_at: string
  sent_at: string | null
}

// Feature switches (server/features.php, migration 036). The names are fixed in
// server/pure.php, rg_feature_defaults().
export type FeatureName = "finance" | "online_payments" | "sms_whatsapp" | "inbox"

export type FeatureSwitch = {
  name: FeatureName
  enabled: boolean
  updated_at: string | null
  /** Name of the account that last changed it, or null if never changed. */
  updated_by: string | null
}

export type FeaturesResponse = {
  features: Record<FeatureName, boolean>
  switches: FeatureSwitch[]
}

// ---- Staff portal (/m), me.php -------------------------------------------------

export type PortalShift = {
  id: number
  title: string
  division: DivisionId
  site_name: string | null
  client: string | null
  starts_at: string
  ends_at: string
  status: "scheduled" | "open" | "completed" | "cancelled"
  cancel_reason: string | null
  site_address: string | null
  site_postcode: string | null
  site_notes: string | null
  site_contact: string | null
  site_contact_phone: string | null
  clocked_in: number
  clocked_out: number
  clock_in_opens_at: string
  can_clock_in: boolean
}

export type PortalOpenShift = {
  id: number
  title: string
  division: DivisionId
  site_name: string | null
  client: string | null
  starts_at: string
  ends_at: string
  /** The assignment check, screened: may this worker pick it up? */
  eligible: boolean
  reason: string | null
}

export type PortalOffer = {
  id: number
  shift_id: number
  title: string
  division: DivisionId
  starts_at: string
  ends_at: string
  expires_at: string | null
  site_name: string | null
  site_address: string | null
  site_postcode: string | null
}

export type PortalBlocker = { key: string | null; title: string; todo: string }

export type PortalHome = {
  worker: { id: number; name: string; role: string; division: DivisionId; status: string }
  shifts: PortalShift[]
  open_shifts: PortalOpenShift[]
  offers: PortalOffer[]
  eligible: boolean
  blockers: PortalBlocker[]
  training_due: { label: string; expires_on: string | null; days: number | null }[]
  unread: number
}

export type PortalCheck = {
  key: string
  label: string
  mandatory: boolean
  chip: RequirementChip
  chip_label: string
  expiry: string | null
  days: number | null
  waiting: boolean
  rejection_reason: string | null
  can_upload: boolean
}

export type PortalTraining = {
  id: string
  course: string
  provider: string | null
  status: string
  completed_on: string | null
  expires_on: string | null
  rag: "valid" | "expiring" | "expired" | "unknown" | null
  days: number | null
  file_id: number | null
  file_name: string | null
}

export type PortalTimesheet = {
  id: number
  shift_id: number | null
  shift_title: string | null
  site_name: string | null
  work_date: string
  start_time: string
  end_time: string
  minutes: number
  break_minutes: number
  status: "draft" | "pending" | "queried" | "approved" | "rejected"
  source: string
  submitted_at: string | null
  decided_at: string | null
  decision_note: string | null
}

export type PortalPerson = {
  full_name: string
  preferred_name: string | null
  email: string | null
  phone: string | null
  address_line1: string | null
  address_line2: string | null
  city: string | null
  postcode: string | null
  emergency_contact_name: string | null
  emergency_contact_phone: string | null
  emergency_contact_relationship: string | null
  emergency_contact2_name: string | null
  emergency_contact2_phone: string | null
  emergency_contact2_relationship: string | null
}

export type PortalProfile = {
  person: PortalPerson
  worker: { role: string; division: DivisionId; payroll_no: string | null; status: string }
  login_email: string
}

export type PortalLeave = {
  person_id: number
  leave_year: string
  balance: {
    entitlement_known: boolean
    entitlement: number
    taken: number
    approved: number
    requested: number
    remaining: number | null
    used: number
  }
  records: {
    id: number
    kind: string
    status: "requested" | "approved" | "declined" | "cancelled" | "taken"
    starts_on: string
    ends_on: string
    hours: number
    reason: string | null
    decided_at: string | null
  }[]
}

// ---- Website: vacancies and the forms directory (server/vacancies.php,
// server/website-forms.php, migration 039) -----------------------------------

export type VacancyStatus = "draft" | "published" | "closed"

/** How a role takes applications: its linked Google Form, closed, or the native short form. */
export type ApplyRoute = "form" | "closed" | "native"

/** A published role as the website sees it. Public fields only. */
export type PublicVacancy = {
  slug: string
  title: string
  service: ServiceId
  location: string
  hours_text: string
  pay_text: string | null
  summary: string
  published_at: string | null
}

/** One role page (vacancies.php?slug=...). */
export type PublicVacancyDetail = PublicVacancy & {
  requirements: string | null
  checks: string | null
  apply: ApplyRoute
  /** The Google Form, by id only, when apply is "form". */
  form: { id: string; display: "embedded" | "link" } | null
}

/** A vacancy in the Website area. */
export type Vacancy = {
  id: number
  slug: string
  title: string
  service: ServiceId
  location: string
  hours_text: string
  pay_text: string | null
  summary?: string
  requirements?: string | null
  checks?: string | null
  status: VacancyStatus
  form_id: number | null
  form_title: string | null
  form_active: boolean | null
  owner_id: number | null
  owner_name: string | null
  published_at: string | null
  closed_at: string | null
  updated_at: string
  /** False once the role has been published: its address is then fixed. */
  slug_editable: boolean
}

export type IntakeStatus = "ok" | "silent" | "paused" | "not_configured" | "not_linked"

/** An entry in the forms directory. */
export type WebsiteForm = {
  id: number
  title: string
  purpose: "recruitment" | "training" | "other"
  service: ServiceId | null
  google_form_id: string
  /** Rebuilt from the id by the server; never what was pasted. */
  url: string
  display: "embedded" | "link"
  active: boolean
  owner_id: number | null
  owner_name: string | null
  integration_id: number | null
  integration_name: string | null
  integration_key: string | null
  last_success_at: string | null
  last_failure_at: string | null
  last_failure_reason: string | null
  intake_status: IntakeStatus
  last_reviewed_at: string | null
  /** Not reviewed for 90 days, or never. */
  review_due: boolean
  /** Titles of the vacancies that link to it. */
  vacancies: string[]
  updated_at: string
}

// ---- Spreadsheet import (S8, server/sheet-import.php) ----------------------------

export type ImportOutcome = "new" | "update" | "duplicate" | "no_change" | "uncertain" | "problem"
export type ImportRowResult = "pending" | "created" | "updated" | "skipped" | "failed"

export type ImportCandidate = {
  id: number
  name: string
  email: string | null
  phone: string | null
  services: string[]
  staff: boolean
}

export type ImportChange = { field: string; label: string; old: string | null; new: string }

/** A row in the preview (open import) or, once applied, what happened to it. */
export type ImportRow = {
  id: number
  row: number
  outcome: ImportOutcome | ImportRowResult
  reason: string | null
  name?: string | null
  email?: string | null
  phone?: string | null
  role?: string | null
  service?: string | null
  response_at?: string | null
  match?: { decision: string; basis: string | null; reason: string | null } | null
  person?: { id: number; name: string | null; staff: boolean } | null
  candidates?: ImportCandidate[]
  changes?: ImportChange[]
  person_id?: number | null
  applicant_id?: number | null
  changed_fields?: string[]
}

export type ImportCounts = { created: number; updated: number; skipped: number; failed: number; pending: number }

export type SheetImport = {
  id: number
  file_name: string
  status: "open" | "applied" | "closed"
  purpose: "applications" | "staff_updates"
  default_service: string | null
  mapping_id: number | null
  row_count: number
  created_at: string
  applied_at: string | null
  closed_at: string | null
  counts: ImportCounts
}

export type SheetImportView = {
  import: SheetImport
  headers: string[]
  /** Normalised heading => field. */
  columns: Record<string, string>
  samples: Record<string, string[]>
  tally: Partial<Record<string, number>>
  rows: ImportRow[]
}

export type SheetImportHistory = {
  id: number
  file_name: string
  status: SheetImport["status"]
  purpose: SheetImport["purpose"]
  row_count: number
  created_count: number
  updated_count: number
  skipped_count: number
  failed_count: number
  created_at: string
  applied_at: string | null
  created_by_name: string | null
}

export type ImportMapping = {
  id: number
  name: string
  website_form_id: number | null
  form_title: string | null
  purpose: SheetImport["purpose"]
  default_service: string | null
  columns: Record<string, string>
}
