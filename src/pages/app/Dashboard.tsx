import { useState } from "react"
import { Link } from "react-router-dom"
import ReactApexChart from "react-apexcharts"
import type { ApexOptions } from "apexcharts"
import {
  Users,
  GraduationCap,
  CalendarClock,
  Award,
  Banknote,
  CalendarX,
  AlertTriangle,
  Inbox,
  ArrowRight,
} from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { useAuth } from "@/providers/auth-provider"
import { useApi } from "@/hooks/use-api"
import type { DashboardSummary, Shift, Invoice, Enquiry } from "@/lib/types"
import { gbp, DIVISION_LABELS, DIVISION_ACCENTS } from "@/lib/format"
import { SERVICE_ACCENTS } from "@/lib/services"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"
import { WeatherWidget } from "@/components/app/weather-widget"

const DIVISION_TABS = [
  { id: "all", label: "All divisions" },
  { id: "care", label: "Healthcare" },
  { id: "academy", label: "Training" },
  { id: "cleaning", label: "Cleaning" },
  { id: "security", label: "Security" },
]

export default function Dashboard() {
  const { user, hasRole } = useAuth()
  // OpsToday reads scheduling.php, invoices.php and enquiries.php, all of which
  // are office-only on the server. Without this the panels rendered a 403 as an
  // empty state, which reads as "nothing to do" rather than "not yours to see".
  const isOffice = hasRole("super_admin", "admin", "manager")
  const [division, setDivision] = useState("all")
  const { data, loading, error, reload } = useApi<DashboardSummary>(
    `dashboard.php?division=${division}`,
    [division],
  )

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Unified Command Centre</h1>
          <p className="text-sm text-muted-foreground">
            Welcome back{user ? `, ${user.name.split(" ")[0]}` : ""}. One live view across the group.
          </p>
        </div>
        {/* Division switcher */}
        <div
          role="group"
          aria-label="Filter dashboard by division"
          className="flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1 shadow-soft"
        >
          {DIVISION_TABS.map((t) => (
            <button
              key={t.id}
              type="button"
              onClick={() => setDivision(t.id)}
              aria-pressed={division === t.id}
              className={
                "rounded-md px-3 py-1.5 text-sm font-medium transition-colors duration-150 ease-out " +
                (division === t.id
                  ? "bg-primary text-primary-foreground"
                  : "text-muted-foreground hover:bg-muted hover:text-foreground")
              }
            >
              {t.label}
            </button>
          ))}
        </div>
      </div>

      <WeatherWidget />

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-6">
            <Kpi icon={GraduationCap} label="Active programmes" value={data.active_programmes} />
            <Kpi icon={Users} label="Enrolments" value={data.enrolments} />
            <Kpi icon={CalendarClock} label="Upcoming sessions" value={data.upcoming_sessions} />
            <Kpi icon={Award} label="Certificates issued" value={data.certificates_issued} />
            <Kpi icon={CalendarX} label="Open shifts" value={data.open_shifts} tone="amber" />
            <Kpi
              icon={Banknote}
              label="Money owed"
              value={gbp(data.outstanding_invoices_pence)}
              tone="red"
            />
          </div>

          <div className="grid items-stretch gap-4 lg:grid-cols-3">
            <Card className="h-full lg:col-span-2">
              <CardHeader>
                <CardTitle className="text-base">Revenue by division</CardTitle>
              </CardHeader>
              <CardContent>
                <RevenueChart data={data.revenue_by_division} />
              </CardContent>
            </Card>

            <Card className="h-full">
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <AlertTriangle className="size-4 text-destructive" /> Compliance alerts
                </CardTitle>
              </CardHeader>
              <CardContent>
                {data.compliance_alerts.length === 0 ? (
                  <p className="text-sm text-muted-foreground">Nothing expiring soon. All clear.</p>
                ) : (
                  <ul className="space-y-3">
                    {data.compliance_alerts.map((a, i) => (
                      <li key={i} className="flex items-center justify-between gap-2 text-sm">
                        <div>
                          <p className="font-medium">{a.worker}</p>
                          <p className="text-muted-foreground">{a.item}</p>
                        </div>
                        <Badge variant={a.days <= 7 ? "destructive" : "secondary"}>
                          {a.days}d
                        </Badge>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>

          {isOffice && <OpsToday />}
        </>
      )}
    </div>
  )
}

// Live operational snapshot — today's cover, money to chase, leads to answer.
// Pulls straight from the module endpoints so it's always current.
function OpsToday() {
  const today = new Date().toISOString().slice(0, 10)
  const shifts = useApi<{ shifts: Shift[] }>(`scheduling.php?from=${today}&to=${today}`)
  const invoices = useApi<{ invoices: Invoice[] }>("invoices.php")
  const enquiries = useApi<{ enquiries: Enquiry[] }>("enquiries.php")

  const todayShifts = (shifts.data?.shifts ?? []).filter((s) => s.status === "scheduled")
  const unpaid = (invoices.data?.invoices ?? []).filter((i) => i.status === "sent" || i.status === "overdue")
  const newLeads = (enquiries.data?.enquiries ?? []).filter((e) => e.status === "new")

  return (
    <div className="grid gap-4 lg:grid-cols-3">
      <OpsCard title="Today's cover" icon={CalendarClock} to="/app/scheduling" count={todayShifts.length} empty="No shifts scheduled today.">
        {todayShifts.slice(0, 5).map((s) => (
          <Row key={s.id} left={s.worker ?? "Unassigned"} sub={s.title} right={s.starts_at.slice(11, 16)} />
        ))}
      </OpsCard>

      <OpsCard title="Money to chase" icon={Banknote} to="/app/invoicing" count={unpaid.length} empty="Nothing outstanding. Nice." tone="red">
        {unpaid.slice(0, 5).map((i) => (
          <Row key={i.id} left={i.number} sub={i.client || "—"} right={gbp(i.total_pence)} rightTone={i.status === "overdue" ? "red" : undefined} />
        ))}
      </OpsCard>

      <OpsCard title="New enquiries" icon={Inbox} to="/app/enquiries" count={newLeads.length} empty="Inbox clear. No new leads." tone="amber">
        {newLeads.slice(0, 5).map((e) => (
          <Row key={e.id} left={e.name} sub={DIVISION_LABELS[e.division] ?? e.division} right={e.created_at.slice(5, 10)} />
        ))}
      </OpsCard>
    </div>
  )
}

function OpsCard({
  title,
  icon: Icon,
  to,
  count,
  empty,
  tone = "primary",
  children,
}: {
  title: string
  icon: React.ComponentType<{ className?: string }>
  to: string
  count: number
  empty: string
  tone?: "primary" | "red" | "amber"
  children: React.ReactNode
}) {
  const color = tone === "red" ? "text-destructive" : tone === "amber" ? "text-warning" : "text-primary"
  return (
    <Card className="flex h-full flex-col">
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <CardTitle className="flex items-center gap-2 text-base">
          <Icon className={`size-4 ${color}`} /> {title}
          {count > 0 && <Badge variant="secondary">{count}</Badge>}
        </CardTitle>
        <Link
          to={to}
          aria-label={`View all — ${title}`}
          className="inline-flex items-center gap-1 rounded-md text-xs font-medium text-primary transition-colors hover:underline"
        >
          View all <ArrowRight className="size-3" />
        </Link>
      </CardHeader>
      <CardContent className="flex flex-1 flex-col">
        {count === 0 ? (
          <p className="flex flex-1 items-center justify-center py-6 text-center text-sm text-muted-foreground">{empty}</p>
        ) : (
          <ul className="space-y-2.5">{children}</ul>
        )}
      </CardContent>
    </Card>
  )
}

function Row({
  left,
  sub,
  right,
  rightTone,
}: {
  left: string
  sub: string
  right: string
  rightTone?: "red"
}) {
  return (
    <li className="flex items-center justify-between gap-2 text-sm">
      <div className="min-w-0">
        <p className="truncate font-medium">{left}</p>
        <p className="truncate text-xs text-muted-foreground">{sub}</p>
      </div>
      <span className={`shrink-0 text-sm tabular-nums ${rightTone === "red" ? "text-destructive" : "text-muted-foreground"}`}>{right}</span>
    </li>
  )
}

function Kpi({
  icon: Icon,
  label,
  value,
  tone = "primary",
}: {
  icon: React.ComponentType<{ className?: string }>
  label: string
  value: string | number
  tone?: "primary" | "amber" | "red"
}) {
  const toneClass =
    tone === "red"
      ? "text-destructive"
      : tone === "amber"
        ? "text-warning"
        : "text-primary"
  const chipClass =
    tone === "red"
      ? "bg-destructive/10 text-destructive"
      : tone === "amber"
        ? "bg-warning/10 text-warning"
        : "bg-primary/10 text-primary"
  return (
    <Card className="h-full transition-[transform,box-shadow,border-color] duration-200 ease-out hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-elevate">
      <CardContent className="flex h-full flex-col justify-between gap-3 pt-6">
        <div className="flex items-start justify-between gap-2">
          <span className="text-[13px] font-medium leading-snug text-muted-foreground">{label}</span>
          <span className={`grid size-9 shrink-0 place-items-center rounded-lg ${chipClass}`}>
            <Icon className="size-4" />
          </span>
        </div>
        <p className={`text-2xl font-bold tabular-nums ${tone !== "primary" ? toneClass : ""}`}>{value}</p>
      </CardContent>
    </Card>
  )
}

function RevenueChart({ data }: { data: { division: string; pence: number }[] }) {
  const categories = data.map((d) => DIVISION_LABELS[d.division] ?? d.division)
  const series = [{ name: "Revenue", data: data.map((d) => Math.round(d.pence / 100)) }]
  const colors = data.map((d) => DIVISION_ACCENTS[d.division] ?? SERVICE_ACCENTS.care)

  const options: ApexOptions = {
    chart: { type: "bar", toolbar: { show: false }, fontFamily: "inherit" },
    plotOptions: { bar: { borderRadius: 6, distributed: true, columnWidth: "45%" } },
    colors,
    dataLabels: { enabled: false },
    legend: { show: false },
    xaxis: { categories, labels: { style: { fontSize: "11px" } } },
    yaxis: { labels: { formatter: (v) => `£${(v / 1000).toFixed(0)}k` } },
    grid: { borderColor: "hsl(var(--border))", strokeDashArray: 4 },
    tooltip: { y: { formatter: (v) => `£${v.toLocaleString()}` } },
  }

  return <ReactApexChart options={options} series={series} type="bar" height={300} />
}
