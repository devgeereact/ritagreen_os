import { useState } from "react"
import { FileSpreadsheet, Download } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { api, ApiError } from "@/lib/api"
import { exportCsv } from "@/lib/csv"

type Row = Record<string, string | number>

const PACKS = [
  { action: "cqc_pack", title: "CQC evidence pack", desc: "Care workers — DBS, right-to-work, references, certificates.", file: "cqc-evidence.csv" },
  { action: "sia_acs", title: "SIA ACS audit export", desc: "Security officers — SIA licence, vetting, references.", file: "sia-acs-audit.csv" },
  { action: "coshh", title: "COSHH & H&S report", desc: "Cleaning workforce compliance + logged incidents.", file: "coshh-hs.csv" },
  { action: "skills_for_care", title: "Skills for Care report", desc: "Academy — programmes, enrolments, completions, CPD hours.", file: "skills-for-care.csv" },
  { action: "client_kpi", title: "Client KPI report", desc: "Per-client revenue, outstanding, jobs and stage.", file: "client-kpi.csv" },
]

export default function Reports() {
  const [busy, setBusy] = useState<string | null>(null)

  async function run(action: string, file: string) {
    setBusy(action)
    try {
      const res = await api<{ rows?: Row[]; clients?: Row[] }>(`reports.php?action=${action}`)
      const rows = res.rows ?? res.clients ?? []
      if (rows.length === 0) {
        toast("Nothing to export for that report yet.")
        return
      }
      exportCsv(file, rows)
      toast.success("Report downloaded.")
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not run the report.")
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Reports & regulatory exports</h1>
        <p className="text-sm text-muted-foreground">Evidence on demand for regulators and clients. Each downloads as a CSV.</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        {PACKS.map((p) => (
          <Card key={p.action}>
            <CardContent className="flex items-start justify-between gap-4 pt-6">
              <div className="flex gap-3">
                <span className="mt-0.5 grid size-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                  <FileSpreadsheet className="size-5" />
                </span>
                <div>
                  <p className="font-medium">{p.title}</p>
                  <p className="text-sm text-muted-foreground">{p.desc}</p>
                </div>
              </div>
              <Button size="sm" variant="outline" onClick={() => run(p.action, p.file)} disabled={busy === p.action}>
                <Download className="mr-1 size-4" /> {busy === p.action ? "…" : "Export"}
              </Button>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}
