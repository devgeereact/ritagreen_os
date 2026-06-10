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
