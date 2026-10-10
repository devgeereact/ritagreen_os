import { useState } from "react"
import { Link, useSearchParams } from "react-router-dom"
import { Download, FileSpreadsheet } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { api, ApiError, downloadFile } from "@/lib/api"
import { exportCsv } from "@/lib/csv"
import { useApi } from "@/hooks/use-api"
import type { ReportKey, ReportListing, ReportResult } from "@/lib/types"
import { SERVICE_OPTIONS, serviceLabel } from "@/lib/services"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"
import { cn } from "@/lib/utils"

/**
 * Reports: one page, a small set (plan file 02, launch plan Phase 5).
 *
 * Every figure comes from server/reports.php, which applies the account's
 * service scope and zones and totals the whole filtered dataset. The CSV is
 * built on the server from the report's declared columns and recorded in the
 * export log there; this page only asks for it.
 */
export default function Reports() {
  const [params, setParams] = useSearchParams()
  const list = useApi<{ reports: ReportListing[]; scope: string | null }>("reports.php?action=list")
  const reports = list.data?.reports ?? []
  const asked = params.get("report") as ReportKey | null
  const current = reports.find((r) => r.key === asked)?.key ?? reports[0]?.key ?? null
  const service = params.get("service") ?? "all"
  const scope = list.data?.scope ?? null

  const set = (key: string, value: string) =>
    setParams((p) => { p.set(key, value); return p }, { replace: true })

  const query = current ? `reports.php?action=run&report=${current}${service !== "all" ? `&service=${service}` : ""}` : null
  const result = useApi<ReportResult>(query, [query])

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Reports</h1>
        <p className="text-sm text-muted-foreground">
          Counts cover everything that matches, not just what fits on the screen.
          {scope && ` You see ${serviceLabel(scope)} only.`}
        </p>
      </div>

      {list.loading && !list.data && <LoadingBlock rows={2} />}
      {list.error && <ErrorBlock message={list.error} onRetry={list.reload} />}

      {reports.length > 0 && (
        <>
          <div role="tablist" aria-label="Reports" className="-mx-4 flex gap-1 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:px-0">
            {reports.map((r) => (
              <button
                key={r.key}
                type="button"
                role="tab"
                aria-selected={r.key === current}
                onClick={() => set("report", r.key)}
                className={cn(
                  "shrink-0 rounded-md border px-3 py-1.5 text-sm font-medium transition-colors duration-150",
                  r.key === current
                    ? "border-primary bg-primary text-primary-foreground"
                    : "border-border bg-card text-muted-foreground hover:text-foreground",
                )}
              >
                {r.title}
              </button>
            ))}
          </div>

          <div className="flex flex-wrap items-end gap-3">
            {!scope && (
              <div className="grid gap-1.5">
                <Label htmlFor="report-service" className="text-xs text-muted-foreground">Service</Label>
                <Select value={service} onValueChange={(v) => set("service", v)}>
                  <SelectTrigger id="report-service" className="w-44"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All services</SelectItem>
                    {SERVICE_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
            )}
            {current && <ExportControl report={current} service={service} personal={reports.find((r) => r.key === current)?.personal ?? true} />}
          </div>

          {result.loading && !result.data && <LoadingBlock rows={4} />}
          {result.error && <ErrorBlock message={result.error} onRetry={result.reload} />}
          {result.data && <ReportView data={result.data} />}
        </>
      )}

      <RegulatorPacks />
    </div>
  )
}

function ExportControl({ report, service, personal }: { report: ReportKey; service: string; personal: boolean }) {
  const [purpose, setPurpose] = useState("")
  const [busy, setBusy] = useState(false)

  async function run() {
    setBusy(true)
    try {
      const qs = new URLSearchParams({ action: "export", report })
      if (service !== "all") qs.set("service", service)
      if (purpose.trim()) qs.set("purpose", purpose.trim())
      await downloadFile(`reports.php?${qs.toString()}`, `ritagreen-${report}${service !== "all" ? `-${service}` : ""}.csv`)
      toast.success("Downloaded. The export has been recorded.")
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not export the report.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex flex-wrap items-end gap-2">
      <div className="grid gap-1.5">
        <Label htmlFor="export-purpose" className="text-xs text-muted-foreground">
          Why are you exporting this?{personal ? " (it names people)" : ""}
        </Label>
        <Input id="export-purpose" value={purpose} onChange={(e) => setPurpose(e.target.value)} placeholder="Optional, recorded with the export" className="w-64" maxLength={200} />
      </div>
      <Button variant="outline" onClick={run} disabled={busy}>
        <Download className="mr-1 size-4" aria-hidden /> {busy ? "Preparing…" : "Export CSV"}
      </Button>
    </div>
  )
}

const TOTAL_LABELS: Record<string, string> = {
  candidates: "Candidates", open: "Still in progress", workers: "Workers", deployable: "Can work", blocked: "Blocked",
  items: "Items", expired: "Already expired", within_30: "Within 30 days", within_60: "31 to 60 days", within_90: "61 to 90 days",
  shifts: "Shifts", unfilled: "Unfilled", entries: "Timesheets", hours: "Approved hours",
  open_tasks: "Open tasks", overdue_tasks: "Overdue tasks", unowned_tasks: "Tasks with no owner",
}

const STAGE_LABEL: Record<string, string> = {
  applied: "Applied", screened: "Screened", interview: "Interview", vetting: "Vetting", offer: "Offer",
  onboarding: "Onboarding", onboarded: "Onboarded", rejected: "Rejected", withdrawn: "Withdrawn",
}

function cell(key: string, value: string | number | boolean | null): string {
  if (value === null || value === "") return ""
  if (typeof value === "boolean") return value ? "Yes" : "No"
  if (key === "service") return serviceLabel(String(value))
  if (key === "stage") return STAGE_LABEL[String(value)] ?? String(value)
  if (key === "band") return value === "expired" ? "Expired" : `${value} days`
  return String(value)
}

function ReportView({ data }: { data: ReportResult }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{data.title}</CardTitle>
        <p className="text-sm text-muted-foreground">{data.description}</p>
        <dl className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
          {Object.entries(data.totals).map(([k, v]) => (
            <div key={k}>
              <dt className="text-xs text-muted-foreground">{TOTAL_LABELS[k] ?? k}</dt>
              <dd className="text-lg font-semibold tabular-nums">{v}</dd>
            </div>
          ))}
        </dl>
      </CardHeader>
      <CardContent className="px-0 pb-2">
        {data.rows.length === 0 ? (
          <p className="px-6 pb-4 text-sm text-muted-foreground">Nothing to report. This list is empty because nothing matches, not because it failed.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-xs text-muted-foreground">
                  {data.columns.map((c) => <th key={c.key} className="whitespace-nowrap px-4 py-2 font-medium">{c.label}</th>)}
                </tr>
              </thead>
              <tbody>
                {data.rows.map((row, i) => (
                  <tr key={i} className="border-b border-border last:border-0">
                    {data.columns.map((c) => (
                      <td key={c.key} className={cn("px-4 py-2 align-top", typeof row[c.key] === "number" && "tabular-nums")}>
                        {c.key === "name" && typeof row.person_id === "number"
                          ? <Link to={`/app/people/${row.person_id}`} className="text-primary hover:underline">{cell(c.key, row[c.key])}</Link>
                          : cell(c.key, row[c.key])}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

type PackRow = Record<string, string | number>

// The regulator packs that were this page before. They are built in the
// browser, so the export is recorded through governance.php as it downloads.
const PACKS = [
  { action: "cqc_pack", title: "CQC evidence pack", desc: "Healthcare workers: DBS, right to work, references, certificates.", file: "cqc-evidence.csv" },
  { action: "sia_acs", title: "SIA ACS audit export", desc: "Security officers: SIA licence, vetting, references.", file: "sia-acs-audit.csv" },
  { action: "coshh", title: "COSHH and H&S report", desc: "Cleaning workforce and logged incidents.", file: "coshh-hs.csv" },
]

function RegulatorPacks() {
  const [busy, setBusy] = useState<string | null>(null)
  async function run(action: string, file: string) {
    setBusy(action)
    try {
      const res = await api<{ rows?: PackRow[] }>(`reports.php?action=${action}`)
      const rows = res.rows ?? []
      if (rows.length === 0) {
        toast("Nothing to export for that pack yet.")
        return
      }
      await api("governance.php?action=log_export", {
        method: "POST",
        body: { export_type: `report.${action}`, format: "csv", record_count: rows.length, scope: action, contains_personal_data: true },
      })
      exportCsv(file, rows)
      toast.success("Downloaded. The export has been recorded.")
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not build the pack.")
    } finally {
      setBusy(null)
    }
  }
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Regulator packs</CardTitle>
        <p className="text-sm text-muted-foreground">For an inspection or an audit. Each downloads as a CSV.</p>
      </CardHeader>
      <CardContent className="grid gap-3 sm:grid-cols-3">
        {PACKS.map((p) => (
          <div key={p.action} className="flex flex-col justify-between gap-3 rounded-lg border border-border p-3">
            <div className="flex gap-2">
              <FileSpreadsheet className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
              <div>
                <p className="text-sm font-medium">{p.title}</p>
                <p className="text-xs text-muted-foreground">{p.desc}</p>
              </div>
            </div>
            <Button size="sm" variant="outline" onClick={() => run(p.action, p.file)} disabled={busy === p.action}>
              <Download className="mr-1 size-4" aria-hidden /> {busy === p.action ? "Building…" : "Download"}
            </Button>
          </div>
        ))}
      </CardContent>
    </Card>
  )
}
