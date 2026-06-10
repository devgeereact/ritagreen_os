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
  sites_count?: number
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
  created_at: string
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

export type Shift = {
  id: number
  worker_id: number
  worker: string
  division: DivisionId
  title: string
  site_name: string | null
  client_id: number | null
  client: string | null
  starts_at: string
  ends_at: string
  status: "scheduled" | "completed" | "cancelled"
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
  created_at: string
}

export type InvoiceTotals = {
  outstanding_pence: number
  paid_pence: number
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
