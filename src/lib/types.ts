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
  kind: "clock_in" | "clock_out" | "check_in" | "panic" | "note" | "task" | "signoff"
  note: string | null
  lat: string | null
  lng: string | null
  signer: string | null
  worker: string | null
  created_at: string
}

export type Applicant = {
  id: number
  name: string
  email: string
  phone: string | null
  role_applied: string | null
  division: DivisionId
  stage: "applied" | "screened" | "interview" | "vetting" | "offer" | "onboarded" | "rejected"
  source: string | null
  worker_id: number | null
  created_at: string
}

export type OnboardingTask = { id: number; label: string; done: number }

export type ComplianceItem = {
  worker_id: number
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
}

/**
 * One worked period, as claimed and then decided on.
 *
 * work_date is the date the shift STARTS: an end_time at or before start_time
 * means it ran past midnight, which is why minutes is the server's number and
 * not something to recompute from the two clock times here.
 */
export type TimesheetStatus = "pending" | "approved" | "rejected"

export type Timesheet = {
  id: number
  worker_id: number
  worker: string
  division: DivisionId
  work_date: string
  start_time: string
  end_time: string
  minutes: number
  status: TimesheetStatus
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

export type AnalyticsData = {
  revenue_by_division: { division: string; pence: number }[]
  monthly_revenue: { month: string; pence: number }[]
  jobs_by_status: Record<string, number>
  quotes: { counts: Record<string, number>; win_rate: number }
  workforce_by_division: { division: string; count: number }[]
  compliance_rag: { valid: number; expiring: number; expired: number }
  totals: {
    clients: number
    workers: number
    paid_revenue_pence: number
    outstanding_pence: number
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
