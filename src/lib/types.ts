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
}

export type PayslipLine = {
  work_date: string
  start_time: string
  end_time: string
  hours: number
  premium: string
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

export type Timesheet = {
  id: number
  worker_id: number
  worker: string
  division: DivisionId
  work_date: string
  start_time: string
  end_time: string
  minutes: number
  status: "pending" | "approved"
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
  created_at: string
}

export type AuditEntry = {
  id: number
  action: string
  detail: string
  user: string | null
  created_at: string
}

export type Programme = {
  id: number
  title: string
  description: string | null
  division: DivisionId
  status: "draft" | "published" | "archived"
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
