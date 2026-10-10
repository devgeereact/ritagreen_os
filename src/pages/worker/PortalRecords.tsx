import { useRef, useState } from "react"
import { ChevronLeft, ChevronRight, Download, Upload } from "lucide-react"
import { toast } from "sonner"
import { useDocumentTitle } from "@/hooks/use-document-title"
import { useApi } from "@/hooks/use-api"
import { api, ApiError, downloadFile, uploadFile } from "@/lib/api"
import { gbp, monthBounds } from "@/lib/format"
import { dateOnly, hoursLabel } from "@/lib/people"
import type { Payslip, PortalCheck, PortalTimesheet, PortalTraining, RequirementChip } from "@/lib/types"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { StatusChip } from "@/components/people/shared"
import { Chip, Empty, PortalCard, PortalHeader, PortalSection, PortalState } from "@/components/worker/portal-bits"
import { act, fieldClass } from "@/components/worker/portal-utils"

const RAG_CHIP: Record<string, { chip: RequirementChip; label: string }> = {
  valid: { chip: "valid", label: "Valid" },
  expiring: { chip: "due_soon", label: "Due soon" },
  expired: { chip: "expired", label: "Expired" },
}

export default function PortalRecords() {
  useDocumentTitle("Records, RitaGreen staff portal")
  const { data, loading, error, reload } = useApi<{ checks: PortalCheck[]; training: PortalTraining[] }>("me.php?action=records")

  return (
    <>
      <PortalHeader title="Records" sub="Your documents, training, hours and gross pay" />
      <PortalState loading={loading} error={error} onRetry={reload} hasData={!!data} rows={5}>
        {data && (
          <>
            <PortalSection title="Documents and checks" id="checks">
              {data.checks.length === 0 ? (
                <Empty>No checks are set up for your role yet.</Empty>
              ) : (
                data.checks.map((c) => <CheckRow key={c.key} check={c} onSent={reload} />)
              )}
            </PortalSection>

            <PortalSection title="Training and certificates" id="training">
              {data.training.length === 0 ? (
                <Empty>No training recorded yet.</Empty>
              ) : (
                data.training.map((t) => (
                  <PortalCard key={t.id} className="space-y-2">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="font-medium">{t.course}</p>
                        <p className="text-sm text-muted-foreground">
                          {[t.provider, t.completed_on ? `Completed ${dateOnly(t.completed_on)}` : null,
                            t.expires_on ? `Expires ${dateOnly(t.expires_on)}` : null].filter(Boolean).join(" · ") || "No dates recorded"}
                        </p>
                      </div>
                      {t.rag && RAG_CHIP[t.rag] && <StatusChip chip={RAG_CHIP[t.rag].chip} label={RAG_CHIP[t.rag].label} />}
                    </div>
                    {t.file_id && (
                      <Button
                        variant="outline"
                        className="h-11 w-full"
                        onClick={() =>
                          downloadFile(`me.php?action=download&id=${t.file_id}`, t.file_name ?? "certificate").catch((e: unknown) =>
                            toast.error(e instanceof Error ? e.message : "Could not download the certificate."),
                          )
                        }
                      >
                        <Download className="mr-1.5 size-4" aria-hidden /> Download certificate
                      </Button>
                    )}
                  </PortalCard>
                ))
              )}
            </PortalSection>
          </>
        )}
      </PortalState>
      <Timesheets />
      <GrossPay />
    </>
  )
}

function CheckRow({ check, onSent }: { check: PortalCheck; onSent: () => void }) {
  const input = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)

  async function onFile(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    e.target.value = ""
    if (!file) return
    setBusy(true)
    const form = new FormData()
    form.append("file", file)
    form.append("requirement_key", check.key)
    const ok = await act(() => uploadFile("me.php?action=upload", form), "Sent. The office will check it and tell you.")
    setBusy(false)
    if (ok) onSent()
  }

  return (
    <PortalCard className="space-y-2">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-medium">{check.label}</p>
          <p className="text-sm text-muted-foreground tabular-nums">
            {check.expiry ? `Expires ${dateOnly(check.expiry)}` : check.mandatory ? "Needed for your role" : "Recommended"}
          </p>
        </div>
        <StatusChip chip={check.chip} label={check.chip_label} />
      </div>
      {check.waiting && <p className="text-sm">A copy is with the office to check. Nothing more is needed from you for now.</p>}
      {check.rejection_reason && (
        <p className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm">
          The office could not accept the last copy: {check.rejection_reason}
        </p>
      )}
      {check.can_upload && (
        <>
          <input
            ref={input}
            type="file"
            accept="application/pdf,image/jpeg,image/png,image/webp"
            className="sr-only"
            tabIndex={-1}
            aria-hidden
            onChange={onFile}
          />
          <Button variant="outline" className="h-11 w-full" disabled={busy} onClick={() => input.current?.click()}>
            <Upload className="mr-1.5 size-4" aria-hidden />
            {busy ? "Sending…" : check.chip === "missing" ? "Upload a copy" : "Upload a new copy"}
          </Button>
          <p className="text-xs text-muted-foreground">A photo or PDF, up to 10 MB. It counts once the office has checked it.</p>
        </>
      )}
    </PortalCard>
  )
}

const TS_CHIP: Record<PortalTimesheet["status"], [tone: "ok" | "wait" | "bad" | "quiet", label: string]> = {
  draft: ["wait", "To submit"],
  pending: ["quiet", "With the office"],
  queried: ["bad", "Queried"],
  approved: ["ok", "Approved"],
  rejected: ["bad", "Not approved"],
}

function Timesheets() {
  const { data, loading, error, reload } = useApi<{ timesheets: PortalTimesheet[] }>("me.php?action=timesheets")
  return (
    <PortalSection title="Timesheets" id="timesheets">
      <PortalState loading={loading} error={error} onRetry={reload} hasData={!!data} rows={2}>
        {data && (data.timesheets.length === 0 ? (
          <Empty>No timesheets yet. Clocking out of a shift makes one for you to check.</Empty>
        ) : (
          data.timesheets.slice(0, 12).map((t) => <TimesheetRow key={t.id} ts={t} onDone={reload} />)
        ))}
      </PortalState>
    </PortalSection>
  )
}

function TimesheetRow({ ts, onDone }: { ts: PortalTimesheet; onDone: () => void }) {
  const [brk, setBrk] = useState(String(ts.break_minutes))
  const [busy, setBusy] = useState(false)
  const open = ts.status === "draft" || ts.status === "queried"
  const [tone, label] = TS_CHIP[ts.status] ?? ["quiet", ts.status]
  const id = `brk-${ts.id}`

  async function submit() {
    setBusy(true)
    const ok = await act(
      () => api(`me.php?action=submit_timesheet&id=${ts.id}`, { method: "POST", body: { break_minutes: Number(brk) || 0 } }),
      "Submitted. The office will check it.",
    )
    setBusy(false)
    if (ok) onDone()
  }

  return (
    <PortalCard className="space-y-2">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-medium tabular-nums">
            {dateOnly(ts.work_date)}, {ts.start_time.slice(0, 5)} to {ts.end_time.slice(0, 5)}
          </p>
          <p className="text-sm text-muted-foreground">
            {[ts.site_name ?? ts.shift_title, `${hoursLabel(ts.minutes - ts.break_minutes)} after a ${ts.break_minutes} minute break`].filter(Boolean).join(" · ")}
          </p>
        </div>
        <Chip tone={tone}>{label}</Chip>
      </div>
      {ts.status === "queried" && ts.decision_note && (
        <p className="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm">The office asked: {ts.decision_note}</p>
      )}
      {ts.status === "rejected" && ts.decision_note && (
        <p className="text-sm">Reason: {ts.decision_note}</p>
      )}
      {open && (
        <div className="flex items-end gap-2">
          <div className="flex-1 space-y-1">
            <Label htmlFor={id}>Unpaid break, in minutes</Label>
            <input
              id={id}
              className={fieldClass}
              type="number"
              inputMode="numeric"
              min={0}
              step={5}
              value={brk}
              onChange={(e) => setBrk(e.target.value)}
            />
          </div>
          <Button className="h-11" disabled={busy} onClick={submit}>
            {busy ? "Sending…" : ts.status === "queried" ? "Send again" : "Submit"}
          </Button>
        </div>
      )}
    </PortalCard>
  )
}

/** Month as YYYY-MM moved by whole months. */
function shiftMonth(ym: string, by: number): string {
  const [y, m] = ym.split("-").map(Number)
  const d = new Date(Date.UTC(y, m - 1 + by, 1))
  return d.toISOString().slice(0, 7)
}

function GrossPay() {
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7))
  const { from, to } = monthBounds(new Date(`${month}-15T12:00:00Z`))
  const { data, loading, error, reload } = useApi<Payslip>(`me.php?action=payslip&from=${from}&to=${to}`, [from, to])
  const label = new Date(`${month}-01T00:00:00Z`).toLocaleDateString("en-GB", { month: "long", year: "numeric", timeZone: "UTC" })
  const thisMonth = new Date().toISOString().slice(0, 7)

  async function pdf() {
    if (!data) return
    try {
      // Loaded only when asked for: the PDF library is not part of the
      // installed app (vite.config.ts, globIgnores).
      const { payslipPdf } = await import("@/lib/pdf")
      payslipPdf(data)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not make the statement. Check your connection.")
    }
  }

  return (
    <PortalSection title="Gross pay" id="gross-pay">
      <div className="flex items-center justify-between gap-2">
        <Button variant="outline" size="icon" className="size-11" aria-label="Previous month" onClick={() => setMonth(shiftMonth(month, -1))}>
          <ChevronLeft className="size-5" aria-hidden />
        </Button>
        <p className="font-medium" aria-live="polite">{label}</p>
        <Button variant="outline" size="icon" className="size-11" aria-label="Next month" disabled={month >= thisMonth} onClick={() => setMonth(shiftMonth(month, 1))}>
          <ChevronRight className="size-5" aria-hidden />
        </Button>
      </div>
      <PortalState loading={loading} error={error} onRetry={reload} hasData={!!data} rows={2}>
        {data && (
          <PortalCard>
            {data.lines.length === 0 ? (
              <p className="text-sm text-muted-foreground">No approved hours in {label}. Hours appear here once the office approves them.</p>
            ) : (
              <>
                <ul className="space-y-1.5 text-sm">
                  {data.lines.map((l, i) => (
                    <li key={i} className="flex items-baseline justify-between gap-3">
                      <span className="text-muted-foreground tabular-nums">
                        {dateOnly(l.work_date)} · {l.start_time} to {l.end_time}
                      </span>
                      <span className="whitespace-nowrap font-medium tabular-nums">{l.hours.toFixed(2)}h</span>
                    </li>
                  ))}
                </ul>
                <div className="mt-3 flex items-baseline justify-between border-t border-border pt-3">
                  <span className="font-medium tabular-nums">{data.totals.hours.toFixed(2)} hours</span>
                  <span className="text-lg font-semibold tabular-nums">{gbp(data.totals.gross_pence)}</span>
                </div>
                <Button variant="outline" className="mt-3 h-11 w-full" onClick={pdf}>
                  <Download className="mr-1.5 size-4" aria-hidden /> Download gross pay statement
                </Button>
              </>
            )}
            <p className="mt-3 text-xs text-muted-foreground">
              Gross pay for approved hours, before tax, National Insurance and any other deductions. This is not a
              payslip. Ask the office if something looks wrong.
            </p>
          </PortalCard>
        )}
      </PortalState>
    </PortalSection>
  )
}
