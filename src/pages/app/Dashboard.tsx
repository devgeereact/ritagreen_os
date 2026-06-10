import { useState } from "react"
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
} from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { useAuth } from "@/providers/auth-provider"
import { useApi } from "@/hooks/use-api"
import type { DashboardSummary } from "@/lib/types"
import { gbp, DIVISION_LABELS, DIVISION_ACCENTS } from "@/lib/format"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"

const DIVISION_TABS = [
  { id: "all", label: "All divisions" },
  { id: "care", label: "Care" },
  { id: "academy", label: "Academy" },
  { id: "cleaning", label: "Cleaning" },
  { id: "security", label: "Security" },
]

export default function Dashboard() {
  const { user } = useAuth()
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
        <div className="flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1">
          {DIVISION_TABS.map((t) => (
            <button
              key={t.id}
              onClick={() => setDivision(t.id)}
              className={
                "rounded-md px-3 py-1.5 text-sm font-medium transition-colors " +
                (division === t.id
                  ? "bg-primary text-primary-foreground"
                  : "text-muted-foreground hover:bg-accent hover:text-foreground")
              }
            >
              {t.label}
            </button>
          ))}
        </div>
      </div>

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
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

          <div className="grid gap-4 lg:grid-cols-3">
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="text-base">Revenue by division</CardTitle>
              </CardHeader>
              <CardContent>
                <RevenueChart data={data.revenue_by_division} />
              </CardContent>
            </Card>

            <Card>
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
        </>
      )}
    </div>
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
        ? "text-amber-600"
        : "text-primary"
  return (
    <Card>
      <CardContent className="pt-6">
        <div className="flex items-center justify-between">
          <span className="text-xs font-medium text-muted-foreground">{label}</span>
          <Icon className={`size-4 ${toneClass}`} />
        </div>
        <p className="mt-2 text-2xl font-bold">{value}</p>
      </CardContent>
    </Card>
  )
}

function RevenueChart({ data }: { data: { division: string; pence: number }[] }) {
  const categories = data.map((d) => DIVISION_LABELS[d.division] ?? d.division)
  const series = [{ name: "Revenue", data: data.map((d) => Math.round(d.pence / 100)) }]
  const colors = data.map((d) => DIVISION_ACCENTS[d.division] ?? "#15803D")

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
