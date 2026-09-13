import { useState } from "react"
import { Banknote, Clock, Plane, Download, FileText } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent } from "@/components/ui/card"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import type { PayRun, Payslip } from "@/lib/types"
import { gbp, monthBounds, DIVISION_LABELS } from "@/lib/format"
import { exportCsv } from "@/lib/csv"
import { payslipPdf } from "@/lib/pdf"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

export default function Payroll() {
  const init = monthBounds()
  const [from, setFrom] = useState(init.from)
  const [to, setTo] = useState(init.to)
  const { data, loading, error, reload } = useApi<PayRun>(
    `payroll.php?from=${from}&to=${to}`,
    [from, to],
  )
  const [slipBusy, setSlipBusy] = useState<number | null>(null)

  async function payslip(workerId: number) {
    setSlipBusy(workerId)
    try {
      const slip = await api<Payslip>(
        `payroll.php?action=payslip&worker_id=${workerId}&from=${from}&to=${to}`,
      )
      payslipPdf(slip)
      toast.success("Payslip generated.")
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not generate the payslip.")
    } finally {
      setSlipBusy(null)
    }
  }

  function exportRun() {
    if (!data || data.rows.length === 0) return
    exportCsv(
      `ritagreen-payrun-${from}-to-${to}.csv`,
      data.rows.map((r) => ({
        worker: r.worker,
        division: DIVISION_LABELS[r.division] ?? r.division,
        pay_type: r.pay_type,
        hours: r.hours.toFixed(2),
        rate: (r.pay_rate_pence / 100).toFixed(2),
        base: (r.base_pence / 100).toFixed(2),
        premiums: (r.premium_pence / 100).toFixed(2),
        gross: (r.gross_pence / 100).toFixed(2),
        holiday_hours: r.holiday_hours.toFixed(2),
        holiday_pay: (r.holiday_pay_pence / 100).toFixed(2),
      })),
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Payroll</h1>
          <p className="text-sm text-muted-foreground">
            Approved hours turned into a pay run — premiums, overtime and holiday accrual applied.
          </p>
        </div>
        <div className="flex items-end gap-2">
          <div className="grid gap-1.5">
            <Label htmlFor="from" className="text-xs text-muted-foreground">From</Label>
            <Input id="from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-40" />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="to" className="text-xs text-muted-foreground">To</Label>
            <Input id="to" type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-40" />
          </div>
          {data && data.rows.length > 0 && (
            <Button variant="outline" onClick={exportRun}>
              <Download className="mr-1 size-4" /> Export pay run
            </Button>
          )}
        </div>
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard icon={Clock} label="Total hours" value={data.totals.hours.toFixed(2)} />
            <StatCard icon={Banknote} label="Gross pay" value={gbp(data.totals.gross_pence)} tone="primary" />
            <StatCard icon={Banknote} label="Premiums" value={gbp(data.totals.premium_pence)} />
            <StatCard icon={Plane} label="Holiday pay accrued" value={gbp(data.totals.holiday_pay_pence)} />
          </div>

          {data.rows.length === 0 ? (
            <EmptyBlock
              title="No approved hours in this period"
              hint="Approve timesheets and set worker pay rates, then the pay run appears here."
            />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Worker</TableHead>
                    <TableHead>Pay type</TableHead>
                    <TableHead className="text-right">Hours</TableHead>
                    <TableHead className="text-right">Rate</TableHead>
                    <TableHead className="text-right">Base</TableHead>
                    <TableHead className="text-right">Premiums</TableHead>
                    <TableHead className="text-right">Gross</TableHead>
                    <TableHead className="text-right">Holiday</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.rows.map((r) => (
                    <TableRow key={r.worker_id}>
                      <TableCell>
                        <div className="font-medium">{r.worker}</div>
                        <div className="text-xs text-muted-foreground">{DIVISION_LABELS[r.division] ?? r.division}</div>
                      </TableCell>
                      <TableCell>
                        <Badge variant="outline" className="uppercase">{r.pay_type}</Badge>
                      </TableCell>
                      <TableCell className="text-right">{r.hours.toFixed(2)}</TableCell>
                      <TableCell className="text-right text-muted-foreground">
                        {r.pay_rate_pence > 0 ? `${gbp(r.pay_rate_pence)}/hr` : <span className="text-destructive">No rate</span>}
                      </TableCell>
                      <TableCell className="text-right">{gbp(r.base_pence)}</TableCell>
                      <TableCell className="text-right text-muted-foreground">{gbp(r.premium_pence)}</TableCell>
                      <TableCell className="text-right font-medium">{gbp(r.gross_pence)}</TableCell>
                      <TableCell className="text-right text-muted-foreground">{gbp(r.holiday_pay_pence)}</TableCell>
                      <TableCell className="text-right">
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => payslip(r.worker_id)}
                          disabled={slipBusy === r.worker_id}
                        >
                          <FileText className="mr-1 size-4" /> Payslip
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Card>
          )}

          {data.rows.some((r) => r.pay_rate_pence === 0) && (
            <p className="text-sm text-destructive">
              Some workers have no pay rate set — their pay shows as £0. Set rates on the Workers page.
            </p>
          )}
        </>
      )}
    </div>
  )
}

function StatCard({
  icon: Icon,
  label,
  value,
  tone,
}: {
  icon: React.ComponentType<{ className?: string }>
  label: string
  value: string
  tone?: "primary"
}) {
  return (
    <Card>
      <CardContent className="flex items-center justify-between pt-6">
        <div>
          <p className="text-xs font-medium text-muted-foreground">{label}</p>
          <p className="mt-1 text-2xl font-bold">{value}</p>
        </div>
        <Icon className={`size-7 ${tone === "primary" ? "text-primary" : "text-muted-foreground"}`} />
      </CardContent>
    </Card>
  )
}
