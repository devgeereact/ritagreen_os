import ReactApexChart from "react-apexcharts"
import type { ApexOptions } from "apexcharts"
import { Download, Banknote, Building2, Users, Trophy } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { useApi } from "@/hooks/use-api"
import type { AnalyticsData } from "@/lib/types"
import { gbp, DIVISION_LABELS, DIVISION_ACCENTS } from "@/lib/format"
import { exportCsv } from "@/lib/csv"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"

export default function Analytics() {
  const { data, loading, error, reload } = useApi<AnalyticsData>("analytics.php")

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Analytics</h1>
          <p className="text-sm text-muted-foreground">The numbers that matter, across all four divisions.</p>
        </div>
        {data && (
          <Button
            variant="outline"
            onClick={() =>
              exportCsv(
                "ritagreen-revenue-by-division.csv",
                data.revenue_by_division.map((r) => ({
                  division: DIVISION_LABELS[r.division] ?? r.division,
                  revenue_gbp: (r.pence / 100).toFixed(2),
                })),
              )
            }
          >
            <Download className="mr-1 size-4" /> Export revenue CSV
          </Button>
        )}
      </div>

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Kpi icon={Banknote} label="Revenue (paid)" value={gbp(data.totals.paid_revenue_pence)} />
            <Kpi icon={Banknote} label="Outstanding" value={gbp(data.totals.outstanding_pence)} tone="red" />
            <Kpi icon={Building2} label="Clients" value={data.totals.clients} />
            <Kpi icon={Users} label="Workers" value={data.totals.workers} />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader><CardTitle className="text-base">Revenue by division</CardTitle></CardHeader>
              <CardContent>
                <ReactApexChart
                  type="bar"
                  height={300}
                  series={[{ name: "Revenue", data: data.revenue_by_division.map((r) => Math.round(r.pence / 100)) }]}
                  options={barOptions(
                    data.revenue_by_division.map((r) => DIVISION_LABELS[r.division] ?? r.division),
                    data.revenue_by_division.map((r) => DIVISION_ACCENTS[r.division] ?? "#2e7d32"),
                  )}
                />
              </CardContent>
            </Card>

            <Card>
              <CardHeader><CardTitle className="text-base">Monthly revenue (last 6 months)</CardTitle></CardHeader>
              <CardContent>
                {data.monthly_revenue.length === 0 ? (
                  <p className="py-12 text-center text-sm text-muted-foreground">No paid invoices yet.</p>
                ) : (
                  <ReactApexChart
                    type="area"
                    height={300}
                    series={[{ name: "Revenue", data: data.monthly_revenue.map((m) => Math.round(m.pence / 100)) }]}
                    options={areaOptions(data.monthly_revenue.map((m) => m.month))}
                  />
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader><CardTitle className="text-base">Workforce by division</CardTitle></CardHeader>
              <CardContent>
                <ReactApexChart
                  type="donut"
                  height={300}
                  series={data.workforce_by_division.map((w) => w.count)}
                  options={donutOptions(
                    data.workforce_by_division.map((w) => DIVISION_LABELS[w.division] ?? w.division),
                    data.workforce_by_division.map((w) => DIVISION_ACCENTS[w.division] ?? "#2e7d32"),
                  )}
                />
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <Trophy className="size-4 text-primary" /> Quote win rate
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="flex items-baseline gap-2">
                  <span className="text-4xl font-bold text-primary">{data.quotes.win_rate}%</span>
                  <span className="text-sm text-muted-foreground">of decided quotes won</span>
                </div>
                <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                  {Object.entries(data.quotes.counts).map(([k, v]) => (
                    <div key={k} className="flex justify-between rounded-md bg-muted px-3 py-2">
                      <span className="capitalize text-muted-foreground">{k}</span>
                      <span className="font-medium">{v}</span>
                    </div>
                  ))}
                </div>
                <div className="mt-4 border-t border-border pt-3 text-sm">
                  <p className="mb-2 font-medium">Jobs by status</p>
                  <div className="grid grid-cols-2 gap-3">
                    {Object.entries(data.jobs_by_status).length === 0 ? (
                      <span className="text-muted-foreground">No jobs yet.</span>
                    ) : (
                      Object.entries(data.jobs_by_status).map(([k, v]) => (
                        <div key={k} className="flex justify-between rounded-md bg-muted px-3 py-2">
                          <span className="capitalize text-muted-foreground">{k.replace("_", " ")}</span>
                          <span className="font-medium">{v}</span>
                        </div>
                      ))
                    )}
                  </div>
                </div>
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
  tone?: "primary" | "red"
}) {
  return (
    <Card>
      <CardContent className="pt-6">
        <div className="flex items-center justify-between">
          <span className="text-xs font-medium text-muted-foreground">{label}</span>
          <Icon className={`size-4 ${tone === "red" ? "text-destructive" : "text-primary"}`} />
        </div>
        <p className="mt-2 text-2xl font-bold">{value}</p>
      </CardContent>
    </Card>
  )
}

function barOptions(categories: string[], colors: string[]): ApexOptions {
  return {
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
}

function areaOptions(categories: string[]): ApexOptions {
  return {
    chart: { type: "area", toolbar: { show: false }, fontFamily: "inherit" },
    colors: ["#2e7d32"],
    dataLabels: { enabled: false },
    stroke: { curve: "smooth", width: 2 },
    fill: { type: "gradient", gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
    xaxis: { categories, labels: { style: { fontSize: "11px" } } },
    yaxis: { labels: { formatter: (v) => `£${(v / 1000).toFixed(0)}k` } },
    grid: { borderColor: "hsl(var(--border))", strokeDashArray: 4 },
    tooltip: { y: { formatter: (v) => `£${v.toLocaleString()}` } },
  }
}

function donutOptions(labels: string[], colors: string[]): ApexOptions {
  return {
    chart: { type: "donut", fontFamily: "inherit" },
    labels,
    colors,
    legend: { position: "bottom" },
    dataLabels: { enabled: false },
    stroke: { width: 0 },
  }
}
